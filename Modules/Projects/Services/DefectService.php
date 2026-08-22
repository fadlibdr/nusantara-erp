<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Core\Services\NotificationService;
use Modules\Projects\Enums\DefectSeverity;
use Modules\Projects\Enums\DefectStatus;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\Project;

/**
 * Register defect — the punch list, and the evidence BAST II rests on.
 *
 * The register exists because Rp 2.425.000.000 of retensi on CTR/2026/I/0001 is
 * held as security against defects and there was no defect anywhere in the
 * system to hold it against. The lifecycle here is deliberately short and each
 * step means one concrete thing:
 *
 *  markFixed  — the site says the repair is done. Cheap, reversible, prj.update.
 *  verify     — the customer/MK says they accept it. This is the row BAST II
 *               counts, so it is prj.approve.
 *  waive      — the customer accepts the item AS IS. Also prj.approve, and it
 *               REQUIRES A REASON, because this is the one way past the BAST II
 *               hard block and an escape valve with no writing on it is just a
 *               delete button with extra steps.
 *  reopen     — the repair did not hold.
 *
 * WHY REOPEN AND NOT A SECOND ROW. Same argument SafetyIncidentService::reopen
 * makes: a repair that came back is the single most useful thing a punch list
 * can tell you, and a fresh row for the same item would double-count it in every
 * count the gate and the dashboard read.
 *
 * A DEFECT MAY BE RAISED ON A PROJECT IN ANY STATUS, INCLUDING CLOSED. Creation
 * is deliberately not gated on ProjectStatus::isOperational(): masa pemeliharaan
 * runs AFTER BAST I, and since approving BAST II closes the project today, a
 * warranty claim arriving the week after has to land somewhere. It does not
 * reopen the project — that belongs to the project-lifecycle package — but it
 * does ring the people who will be paying for it.
 */
class DefectService
{
    /** A reason short enough to be "ok" or "fine" is not a reason. */
    private const MIN_REASON_LENGTH = 10;

    public function __construct(private readonly NotificationService $notifications) {}

    public function create(array $data, ?User $by = null): Defect
    {
        $defect = new Defect(Arr::except($data, ['code', 'status']));
        $defect->status = DefectStatus::Open;
        $defect->reported_on = $data['reported_on'] ?? now()->toDateString();
        $defect->reported_by = $by?->id;
        $defect->save();

        $this->announce($defect->refresh());

        return $defect;
    }

    /**
     * A terminal defect is a record of an acceptance. Correcting one means
     * reopening it first, exactly as the K3 register requires.
     */
    public function update(Defect $defect, array $data, ?User $by = null): Defect
    {
        $this->assertNotTerminal($defect, 'diubah');
        $this->guardSeverityDowngrade($defect, $data, $by);

        $defect->fill(Arr::except($data, ['code', 'status', 'reported_by', 'verified_at', 'verified_by', 'fixed_at', 'downgrade_reason']))->save();

        return $defect->refresh();
    }

    /**
     * Re-severity out of critical/mayor is the cheap way past the BAST II hard
     * block: the route middleware is prj.update, so before this guard a site
     * manager could turn the block into a 20-character warning with one PUT —
     * and the frozen prerequisite_snapshot would then record the false statement
     * that no critical/major item was ever open.
     *
     * A downgrade IS sometimes right (a cracked tile raised as "kritis" in the
     * heat of a punch walk), so it is not refused outright. It costs exactly
     * what waive() costs — prj.approve plus a written reason — and the old
     * severity is prepended to resolution_note so the snapshot is never the
     * only record of what the item used to be. Upgrades and same-class edits
     * stay free: making a finding heavier never clears a gate.
     */
    private function guardSeverityDowngrade(Defect $defect, array &$data, ?User $by): void
    {
        if (! array_key_exists('severity', $data)) {
            return;
        }

        $new = $data['severity'] instanceof DefectSeverity
            ? $data['severity']
            : DefectSeverity::from((string) $data['severity']);

        if (! $defect->severity->blocksHandover() || $new->blocksHandover()) {
            return;
        }

        if ($by === null || ! $by->can('prj.approve')) {
            throw new LogicException(
                "Menurunkan tingkat keparahan temuan {$defect->code} dari {$defect->severity->label()} "
                .'menghapus penahan BAST II, sehingga butuh wewenang persetujuan (prj.approve). '
                .'Gunakan dispensasi bila pelanggan menerimanya apa adanya.'
            );
        }

        $reason = trim((string) ($data['downgrade_reason'] ?? ''));
        $this->assertReason($reason, 'Penurunan tingkat keparahan temuan harus disertai alasan');

        $stamp = now()->format('d-m-Y');
        $note = "Keparahan diturunkan ({$stamp}): {$defect->severity->label()} → {$new->label()}. {$reason}";

        $data['resolution_note'] = trim($note."\n".(string) ($data['resolution_note'] ?? $defect->resolution_note));
    }

    /**
     * The site declares the repair done. It is not closed — somebody still has
     * to look at it.
     */
    public function markFixed(Defect $defect, ?string $fixedOn = null): Defect
    {
        $this->assertNotTerminal($defect, 'ditandai selesai diperbaiki');

        $defect->forceFill([
            'status' => DefectStatus::ReadyForReview,
            'fixed_at' => $fixedOn ?? now()->toDateString(),
        ])->save();

        return $defect->refresh();
    }

    /**
     * The customer / MK accepts the repair. This is the acceptance BAST II
     * counts.
     *
     * fixed_at is back-filled from the verification date when nobody declared
     * the repair separately: an MK who signs an item off on the spot during a
     * punch walk is the normal case on site, and refusing that would be a block
     * the business cannot satisfy — which is how a gate gets routed around.
     */
    public function verify(Defect $defect, ?User $by = null, ?string $verifiedOn = null): Defect
    {
        if ($defect->status === DefectStatus::Closed) {
            throw new LogicException("Temuan {$defect->code} sudah diverifikasi selesai.");
        }

        if ($defect->status === DefectStatus::Waived) {
            throw new LogicException("Temuan {$defect->code} sudah diberi dispensasi; buka kembali lebih dulu bila perlu diperbaiki.");
        }

        $verifiedOn ??= now()->toDateString();

        $defect->forceFill([
            'status' => DefectStatus::Closed,
            'fixed_at' => $defect->fixed_at?->toDateString() ?? $verifiedOn,
            'verified_at' => $verifiedOn,
            'verified_by' => $by?->id,
        ])->save();

        return $defect->refresh();
    }

    /**
     * The customer accepts the item as it stands (dispensasi).
     *
     * The reason is mandatory and it is stored ON THE ITEM, with who accepted
     * it, rather than in one sentence attached to a Rp 2,4 miliar BAST approval.
     * If this is where the block gives way, this is where the evidence has to be.
     */
    public function waive(Defect $defect, string $reason, ?User $by = null, ?string $waivedOn = null): Defect
    {
        $this->assertReason($reason, 'Dispensasi temuan harus disertai alasan');

        if ($defect->status->isTerminal()) {
            throw new LogicException("Temuan {$defect->code} sudah berstatus {$defect->status->label()}.");
        }

        $waivedOn ??= now()->toDateString();

        $defect->forceFill([
            'status' => DefectStatus::Waived,
            'verified_at' => $waivedOn,
            'verified_by' => $by?->id,
            'resolution_note' => trim($reason),
        ])->save();

        return $defect->refresh();
    }

    /**
     * The repair did not hold, or the acceptance was wrong.
     *
     * The reason is PREPENDED rather than replacing what was there: why an item
     * came back is only readable next to what was claimed about it the first
     * time.
     */
    public function reopen(Defect $defect, string $reason, ?string $reopenedOn = null): Defect
    {
        $this->assertReason($reason, 'Pembukaan kembali temuan harus disertai alasan');

        if (! $defect->status->isTerminal()) {
            throw new LogicException("Temuan {$defect->code} masih terbuka.");
        }

        $stamp = Carbon::parse($reopenedOn ?? now()->toDateString())->format('d-m-Y');
        $note = "Dibuka kembali ({$stamp}): ".trim($reason);

        $defect->forceFill([
            'status' => DefectStatus::InProgress,
            'fixed_at' => null,
            'verified_at' => null,
            'verified_by' => null,
            'resolution_note' => trim($note."\n".(string) $defect->resolution_note),
        ])->save();

        return $defect->refresh();
    }

    /**
     * Only an untouched open item may be deleted.
     *
     * Anything somebody has repaired, accepted or waived is evidence about a
     * job whose retensi turns on it, and deleting it is how the register would
     * be emptied to clear the BAST II block.
     */
    public function delete(Defect $defect): void
    {
        if ($defect->status !== DefectStatus::Open || $defect->fixed_at !== null) {
            throw new LogicException(
                "Temuan {$defect->code} sudah ditindaklanjuti dan tidak dapat dihapus; gunakan dispensasi bila pelanggan menerimanya apa adanya."
            );
        }

        $defect->delete();
    }

    /**
     * The punch-list summary a PM is asked for and the BAST II checklist quotes.
     */
    public function summary(?int $projectId): array
    {
        $defects = Defect::query()
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->get();

        $open = $defects->filter(fn (Defect $defect): bool => $defect->status->isOpen());
        $oldest = $open->sortBy(fn (Defect $defect) => $defect->reported_on?->toDateString() ?? '9999-12-31')->first();

        return [
            'project_id' => $projectId,
            'as_of' => now()->toDateString(),
            'total' => $defects->count(),
            'by_status' => $this->tally($defects, 'status'),
            'by_severity' => $this->tally($defects, 'severity'),
            'open_count' => $open->count(),
            // The number the BAST II hard block reads.
            'open_blocking_count' => $open->filter(fn (Defect $defect): bool => $defect->severity->blocksHandover())->count(),
            'overdue_count' => $defects->filter(fn (Defect $defect): bool => $defect->isOverdue())->count(),
            'oldest_open_days' => $oldest?->daysOpen(),
            'oldest_open_code' => $oldest?->code,
        ];
    }

    /**
     * Tell the people who will pay for the repair.
     *
     * prj.update is the PM and the site manager — the smallest audience that can
     * actually do something about it. Deliberately quiet otherwise: a bell that
     * rings for every snagging item on a live site is a bell that gets muted,
     * and the muting takes the warranty claims with it.
     *
     *  - ANY defect on a project already in masa pemeliharaan or closed, because
     *    the job is nominally finished and nobody is watching the punch list any
     *    more; that is money the contractor did not budget for.
     *  - ANY critical defect anywhere, because it stops BAST II by itself.
     */
    private function announce(Defect $defect): void
    {
        $project = $defect->project ?? Project::query()->find($defect->project_id);

        if ($project === null) {
            return;
        }

        $afterHandover = in_array($project->status, [ProjectStatus::Warranty, ProjectStatus::Closed], true);

        if (! $afterHandover && $defect->severity !== DefectSeverity::Critical) {
            return;
        }

        $this->notifications->system(
            'prj.update',
            // The code is in the title because NotificationService deduplicates
            // on unread title: two different findings must not collapse into one.
            sprintf('Temuan %s (%s) — proyek %s', $defect->code, $defect->severity->label(), $project->code),
            sprintf(
                '%s. Sumber: %s. Lokasi: %s. Proyek %s berstatus %s.%s',
                $defect->title,
                $defect->source->label(),
                $defect->location ?: 'tidak dicatat',
                $project->code,
                $project->status?->label() ?? '-',
                $defect->due_date === null ? '' : ' Target perbaikan '.$defect->due_date->format('d-m-Y').'.',
            ),
            "#/d/projects/defects/{$defect->id}",
        );
    }

    private function assertNotTerminal(Defect $defect, string $verb): void
    {
        if ($defect->status->isTerminal()) {
            throw new LogicException(
                "Temuan {$defect->code} berstatus {$defect->status->label()} dan tidak dapat {$verb}. Buka kembali lebih dulu bila ada koreksi."
            );
        }
    }

    private function assertReason(string $reason, string $prefix): void
    {
        if (mb_strlen(trim($reason)) < self::MIN_REASON_LENGTH) {
            throw new LogicException($prefix.', minimal '.self::MIN_REASON_LENGTH.' karakter.');
        }
    }

    /**
     * @return array<int, array{value: string, label: string, count: int}>
     */
    private function tally($defects, string $field): array
    {
        return $defects
            ->groupBy(fn (Defect $defect) => $defect->{$field}->value)
            ->map(fn ($group, $value): array => [
                'value' => $value,
                'label' => $group->first()->{$field}->label(),
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
