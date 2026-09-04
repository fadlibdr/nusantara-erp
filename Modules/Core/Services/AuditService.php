<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Modules\Core\Models\AuditLog;
use Modules\Core\Support\AuditedModels;

/**
 * Records changes to the master data that moves money.
 *
 * Wired by CoreServiceProvider as model observers, so it catches every write
 * path at once — controller, service, console command, seeder, tinker. A trait
 * on each model would have missed whichever one somebody forgot, and the paths
 * that get forgotten are exactly the ones an investigation cares about.
 *
 * LIKE NOTIFICATIONS, THIS MAY NEVER BREAK WHAT IT OBSERVES. It runs inside the
 * transaction that is saving the row. A logging failure that rolled back a
 * vendor update would turn a missing audit line into a lost edit, which is worse
 * in every direction. Failures are logged and swallowed.
 *
 * The one thing it will not do is guess. If it cannot tell who made a change the
 * actor is null, recorded plainly as "no authenticated user", rather than
 * attributed to whoever happens to be convenient.
 */
class AuditService
{
    public function created(Model $model): void
    {
        $this->guard(function () use ($model): void {
            $this->write($model, 'created', $this->presentable($model, $model->getAttributes()));
        });
    }

    public function updated(Model $model): void
    {
        $this->guard(function () use ($model): void {
            $changes = [];

            foreach ($model->getChanges() as $attribute => $new) {
                if (in_array($attribute, AuditedModels::NEVER_LOGGED, true)) {
                    continue;
                }

                $old = $model->getOriginal($attribute);

                // getChanges() can report an attribute whose cast round-trips to
                // the same value. Recording those makes the log say something
                // happened when nothing did.
                if ($this->scalar($old) === $this->scalar($new)) {
                    continue;
                }

                $changes[$attribute] = ['from' => $this->scalar($old), 'to' => $this->scalar($new)];
            }

            if ($changes === []) {
                return;
            }

            $this->write($model, 'updated', $changes);
        });
    }

    public function deleted(Model $model): void
    {
        $this->guard(function () use ($model): void {
            $this->write($model, 'deleted', $this->presentable($model, $model->getOriginal()));
        });
    }

    /**
     * A named event on a record that is NOT observed — written by the service
     * that performed it, with the changes it made.
     *
     * Documents are absent from AuditedModels on purpose: their lifecycle is
     * core_approvals and their edits stop at draft. A surat penagihan (T3.7)
     * is neither — it is a step taken against an APPROVED invoice, outside the
     * approval trail, and INV/2026/VIII/0004 (Rp 15,42 M, production 4 Sep
     * 2026) would otherwise carry no record of who escalated it and when. The
     * shape is the observer's own ('changes' as from/to pairs) so the audit
     * screen reads it without a second renderer; $label stands in for the
     * AuditedModels attribute the model does not declare.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function event(Model $model, string $event, array $changes, ?string $label = null): void
    {
        $this->guard(function () use ($model, $event, $changes, $label): void {
            $scalar = [];

            foreach ($changes as $attribute => $pair) {
                $scalar[$attribute] = ['from' => $this->scalar($pair['from'] ?? null), 'to' => $this->scalar($pair['to'] ?? null)];
            }

            $this->write($model, $event, $scalar, $label);
        });
    }

    /**
     * The change history of one record, newest first.
     */
    public function historyFor(string $type, int $id, int $limit = 100)
    {
        return AuditLog::query()
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->orderByDesc('id')
            ->limit(min(500, max(1, $limit)))
            ->get();
    }

    private function write(Model $model, string $event, array $changes, ?string $label = null): void
    {
        $user = Auth::user();

        // Written with the query builder rather than the model so nothing here
        // can trigger another observer and recurse.
        DB::table('core_audit_log')->insert([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'event' => $event,
            'auditable_type' => $model::class,
            'auditable_id' => (int) $model->getKey(),
            'auditable_label' => $label ?? AuditedModels::labelFor($model),
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            'ip' => $this->clientIp(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{from: null, to: mixed}>
     */
    private function presentable(Model $model, array $attributes): array
    {
        $result = [];

        foreach ($attributes as $attribute => $value) {
            if (in_array($attribute, AuditedModels::NEVER_LOGGED, true)) {
                continue;
            }

            $result[$attribute] = ['from' => null, 'to' => $this->scalar($value)];
        }

        return $result;
    }

    /**
     * Anything that survives json_encode and reads back sensibly. Casts hand
     * back enums, Carbon instances and arrays, none of which belong in a log
     * column as objects.
     */
    private function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            is_object($value) => (string) $value,
            default => $value,
        };
    }

    /** Null outside an HTTP request — a console command has no client IP. */
    private function clientIp(): ?string
    {
        return app()->runningInConsole() ? null : Request::ip();
    }

    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::warning('Audit write failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }
}
