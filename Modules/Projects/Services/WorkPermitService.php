<?php

namespace Modules\Projects\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WorkPermit;

/**
 * P0-C: Izin Kerja Lapangan — the rules that COMPARE, one implementation for
 * store and update (the DailyReportService split: per-field shape lives in the
 * FormRequest, anything weighing two values lives here).
 *
 *  - valid_from < valid_until, both quoted in the refusal;
 *  - permit_date inside the project's execution window. DECISION (the spec is
 *    silent on the edges): INCLUSIVE of both ends — the first and the last day
 *    of the job are working days and a permit for either is exactly what the
 *    form exists for. The window is the PLANNED start_date..end_date, the same
 *    pair the printed kop's WAKTU PELAKSANAAN quotes, so the sheet and the
 *    gate can never disagree; a null bound enforces nothing (a project exists
 *    before its SPK dates are signed).
 */
class WorkPermitService
{
    public function create(array $data): WorkPermit
    {
        $project = Project::query()->findOrFail((int) $data['project_id']);
        $project->assertOperational('izin kerja lapangan');

        $this->assertValidity($data['valid_from'] ?? null, $data['valid_until'] ?? null);
        $this->assertPermitDateInWindow($project, (string) $data['permit_date']);

        // Status written explicitly rather than left to the column default:
        // a DB default is not hydrated on the freshly created model, and the
        // resource would answer `status: null` for a permit that IS a draft.
        return DB::transaction(fn (): WorkPermit => WorkPermit::query()->create(
            Arr::except($data, ['code', 'status']) + ['status' => DocumentStatus::Draft],
        ));
    }

    public function update(WorkPermit $permit, array $data): WorkPermit
    {
        $project = $permit->project()->firstOrFail();
        $project->assertOperational('izin kerja lapangan');

        // Effective values: the payload's when the key is sent, the stored
        // ones when not — a partial update that only moves valid_until is
        // still weighed against the stored valid_from.
        $this->assertValidity(
            array_key_exists('valid_from', $data) ? $data['valid_from'] : $permit->valid_from,
            array_key_exists('valid_until', $data) ? $data['valid_until'] : $permit->valid_until,
        );
        $this->assertPermitDateInWindow(
            $project,
            (string) (array_key_exists('permit_date', $data) ? $data['permit_date'] : $permit->permit_date->toDateString()),
        );

        return DB::transaction(function () use ($permit, $data): WorkPermit {
            $permit->fill(Arr::except($data, ['code', 'project_id', 'status']))->save();

            return $permit;
        });
    }

    // ---------------------------------------------------------------- rules

    private function assertValidity(mixed $from, mixed $until): void
    {
        if ($from === null || $until === null) {
            return; // the FormRequest requires both on create; partial updates fall back to stored values
        }

        $from = Carbon::parse($from);
        $until = Carbon::parse($until);

        if ($until->lte($from)) {
            throw ValidationException::withMessages(['valid_until' => sprintf(
                'Berlaku sampai (%s) harus setelah berlaku mulai (%s).',
                $until->format('d-m-Y H:i'),
                $from->format('d-m-Y H:i'),
            )]);
        }
    }

    private function assertPermitDateInWindow(Project $project, string $permitDate): void
    {
        $date = Carbon::parse($permitDate)->startOfDay();
        $start = $project->start_date?->copy()->startOfDay();
        $end = $project->end_date?->copy()->startOfDay();

        if (($start !== null && $date->lt($start)) || ($end !== null && $date->gt($end))) {
            throw ValidationException::withMessages(['permit_date' => sprintf(
                'Tanggal izin %s di luar waktu pelaksanaan proyek %s (%s s/d %s). '
                    .'Izin kerja hanya untuk hari di dalam masa pelaksanaan — perpanjangan waktu dicatat lewat CCO waktu, bukan lewat izin.',
                $date->toDateString(),
                $project->code,
                $start?->toDateString() ?? '—',
                $end?->toDateString() ?? '—',
            )]);
        }
    }
}
