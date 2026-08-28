<?php

namespace Modules\Quality\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Location;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Enums\NcrStatus;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Models\Ncr;

/**
 * P1-QC: the NCR lifecycle. Not Approvable — status is NcrStatus and moves
 * through explicit transitions (raise → start correction → verify → close), each
 * guarding the state it may run from.
 *
 * THE RESPONSIBLE PARTY IS AN XOR: an NCR names exactly one party to fix it —
 * an own employee OR a subcontractor, never both and never neither. Both blame
 * everyone and nobody. `stage` is inherited from the originating inspection when
 * one is named (so it can never disagree with the sheet the NCR came off), and
 * supplied directly only for a standalone NCR.
 */
class NcrService
{
    public function create(array $data): Ncr
    {
        $data = $this->resolveStageAndAssert($data, null);

        return DB::transaction(fn (): Ncr => Ncr::query()->create(
            Arr::except($data, ['code', 'status', 'verified_by', 'verified_at'])
            + ['status' => NcrStatus::Open],
        ));
    }

    public function update(Ncr $ncr, array $data): Ncr
    {
        if ($ncr->status === NcrStatus::Closed) {
            throw ValidationException::withMessages(['status' => sprintf(
                'NCR %s sudah ditutup dan tidak dapat diubah lagi.',
                $ncr->code,
            )]);
        }

        // project_id and inspection_id are fixed at creation — the NCR belongs to
        // the nonconformance it was raised for.
        $data['project_id'] = $ncr->project_id;
        $data['inspection_id'] = $ncr->inspection_id;
        $data = $this->resolveStageAndAssert($data, $ncr);

        return DB::transaction(function () use ($ncr, $data): Ncr {
            $ncr->fill(Arr::except($data, ['code', 'status', 'project_id', 'inspection_id', 'verified_by', 'verified_at']))->save();

            return $ncr;
        });
    }

    /** open → under_correction: the responsible party has begun the fix. */
    public function startCorrection(Ncr $ncr): Ncr
    {
        $this->assertStatus($ncr, [NcrStatus::Open], 'memulai perbaikan');
        $ncr->forceFill(['status' => NcrStatus::UnderCorrection])->save();

        return $ncr;
    }

    /**
     * → verified: QC re-inspected and accepts the correction. This is the point
     * the block lifts (isOpen() turns false), so it records WHO verified and
     * WHEN — the fact an auditor reads.
     */
    public function verify(Ncr $ncr, User $by, ?string $verifiedAt = null): Ncr
    {
        $this->assertStatus($ncr, [NcrStatus::Open, NcrStatus::UnderCorrection], 'memverifikasi');

        $ncr->forceFill([
            'status' => NcrStatus::Verified,
            'verified_by' => $by->id,
            'verified_at' => $verifiedAt ?? now()->toDateString(),
        ])->save();

        return $ncr;
    }

    /** verified → closed: administratively closed after the verification stands. */
    public function close(Ncr $ncr): Ncr
    {
        $this->assertStatus($ncr, [NcrStatus::Verified], 'menutup');
        $ncr->forceFill(['status' => NcrStatus::Closed])->save();

        return $ncr;
    }

    // ------------------------------------------------------------------ helpers

    private function assertStatus(Ncr $ncr, array $allowed, string $verb): void
    {
        if (! in_array($ncr->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => sprintf(
                'NCR %s berstatus %s dan tidak dapat %s dari status itu.',
                $ncr->code,
                $ncr->status->label(),
                $verb,
            )]);
        }
    }

    /**
     * Fill `stage` from the originating inspection (when named) so it can never
     * disagree with the sheet, enforce the responsible-party XOR, and check the
     * cross-module references belong to the same project.
     */
    private function resolveStageAndAssert(array $data, ?Ncr $existing): array
    {
        $projectId = (int) ($data['project_id'] ?? $existing?->project_id);
        $inspectionId = $data['inspection_id'] ?? $existing?->inspection_id;

        // Stage: inherited from the inspection, else supplied directly.
        if (! empty($inspectionId)) {
            $inspection = Inspection::query()->with('template')->find((int) $inspectionId);

            if ($inspection === null || (int) $inspection->project_id !== $projectId) {
                throw ValidationException::withMessages([
                    'inspection_id' => 'Inspeksi yang dirujuk berada pada proyek lain dan tidak dapat menjadi asal NCR ini.',
                ]);
            }

            $data['stage'] = $inspection->template?->stage?->value;
            // Default the NCR location to the inspection's when the caller left it blank.
            $data['location_id'] ??= $inspection->location_id;
        }

        if (empty($data['stage'])) {
            throw ValidationException::withMessages([
                'stage' => 'Tahap NCR wajib diisi bila tidak mengacu pada inspeksi.',
            ]);
        }

        // Coerce/validate the stage value.
        InspectionStage::from((string) $data['stage']);

        // Location must belong to the project.
        $location = Location::query()->find((int) ($data['location_id'] ?? 0));

        if ($location === null || (int) $location->project_id !== $projectId) {
            throw ValidationException::withMessages([
                'location_id' => 'Lokasi yang dipilih bukan bagian dari proyek NCR ini.',
            ]);
        }

        // THE XOR — exactly one responsible party.
        $hasEmployee = ! empty($data['responsible_employee_id']);
        $hasSubcontract = ! empty($data['subcontract_id']);

        if ($hasEmployee === $hasSubcontract) {
            throw ValidationException::withMessages([
                'responsible_employee_id' => 'Isi tepat satu penanggung jawab: karyawan sendiri ATAU subkontraktor, tidak keduanya dan tidak kosong.',
            ]);
        }

        // Null the party that is not set, so an update that switches parties does
        // not leave the old one behind.
        $data['responsible_employee_id'] = $hasEmployee ? (int) $data['responsible_employee_id'] : null;
        $data['subcontract_id'] = $hasSubcontract ? (int) $data['subcontract_id'] : null;

        return $data;
    }
}
