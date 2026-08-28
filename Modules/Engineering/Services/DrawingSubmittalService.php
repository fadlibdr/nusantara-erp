<?php

namespace Modules\Engineering\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Engineering\Enums\DrawingStatus;
use Modules\Engineering\Enums\SubmittalDecision;
use Modules\Engineering\Models\Drawing;
use Modules\Engineering\Models\DrawingSubmittal;

/**
 * P1-ENG: the SDS lifecycle — revision replacement and decision recording.
 *
 * DECISION WRITTEN DOWN (the lane was asked to): the MK's stamp does NOT ride
 * the Approvable trait. The MK is external — not a users row, and owner
 * decision #6 keeps external parties out of users — while the four FM-10
 * stamps are data somebody types from the sheet that came back. So the stamp
 * is recorded-fact columns, and MAKER-CHECKER moves to the RECORDING: the
 * route demands eng.approve, and the recorder may not be the person who
 * created the submittal — otherwise one login could submit a drawing and
 * "return" it approved without any sheet existing.
 *
 * Revision replacement mirrors BaselineService: the new row supersedes every
 * live predecessor of the same drawing inside one transaction, writing ONLY
 * superseded_at + superseded_by_id onto them — the predecessor's own decision
 * columns are history and stay untouched.
 */
class DrawingSubmittalService
{
    public function create(array $data, User $by): DrawingSubmittal
    {
        $drawing = Drawing::query()->findOrFail((int) $data['drawing_id']);

        return DB::transaction(function () use ($drawing, $data, $by): DrawingSubmittal {
            /** @var DrawingSubmittal $submittal */
            $submittal = DrawingSubmittal::query()->create(
                Arr::except($data, ['code', 'decision', 'decided_at', 'notes', 'created_by'])
                + ['created_by' => $by->id],
            );

            // Locked before stamping, so two racing revisions cannot both
            // believe they are the current one (the BaselineService pattern).
            DrawingSubmittal::query()
                ->where('drawing_id', $drawing->id)
                ->whereKeyNot($submittal->id)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (DrawingSubmittal $old) => $old->forceFill([
                    'superseded_at' => now(),
                    'superseded_by_id' => $submittal->id,
                ])->save());

            $drawing->forceFill(['status' => DrawingStatus::Diajukan])->save();

            return $submittal;
        });
    }

    public function update(DrawingSubmittal $submittal, array $data): DrawingSubmittal
    {
        $this->assertUndecidedAndCurrent($submittal, 'diubah');

        $submittal->fill(Arr::except($data, [
            'code', 'drawing_id', 'decision', 'decided_at', 'notes', 'created_by',
        ]))->save();

        return $submittal;
    }

    public function delete(DrawingSubmittal $submittal): void
    {
        $this->assertUndecidedAndCurrent($submittal, 'dihapus');

        $predecessors = DrawingSubmittal::query()
            ->where('superseded_by_id', $submittal->id)
            ->count();

        if ($predecessors > 0) {
            throw ValidationException::withMessages(['submittal' => sprintf(
                'Submittal %s telah menggantikan %d revisi sebelumnya dan tidak dapat dihapus; '
                    .'catat keputusannya atau ajukan revisi baru.',
                $submittal->code,
                $predecessors,
            )]);
        }

        DB::transaction(function () use ($submittal): void {
            $submittal->delete();

            // With no live submittal left, the register honestly says so.
            $drawing = $submittal->drawing;

            if ($drawing !== null && ! $drawing->submittals()->whereNull('superseded_at')->exists()) {
                $drawing->forceFill(['status' => DrawingStatus::BelumDiajukan])->save();
            }
        });
    }

    /**
     * Type the MK's stamp in — once, by someone other than whoever created the
     * submittal, and only onto the CURRENT revision.
     */
    public function recordDecision(DrawingSubmittal $submittal, array $data, User $recorder): DrawingSubmittal
    {
        if ($submittal->isSuperseded()) {
            throw ValidationException::withMessages(['decision' => sprintf(
                'Submittal %s telah digantikan revisi %s; keputusan MK dicatat pada revisi terbarunya.',
                $submittal->code,
                $submittal->supersededBy?->code ?? '-',
            )]);
        }

        if ($submittal->isDecided()) {
            throw ValidationException::withMessages(['decision' => sprintf(
                'Keputusan %s sudah tercatat untuk %s pada %s dan tidak dapat ditimpa; '
                    .'bila lembar stempel berbeda, ajukan revisi baru.',
                $submittal->decision?->label(),
                $submittal->code,
                $submittal->decided_at?->format('d-m-Y') ?? '-',
            )]);
        }

        if ((int) $submittal->created_by === (int) $recorder->id) {
            throw ValidationException::withMessages(['decision' => sprintf(
                'Pencatat keputusan tidak boleh orang yang mengajukan submittal %s sendiri — '
                    .'minta pemegang eng.approve lain mencatat lembar stempel MK.',
                $submittal->code,
            )]);
        }

        $decision = SubmittalDecision::from((string) $data['decision']);

        return DB::transaction(function () use ($submittal, $data, $decision): DrawingSubmittal {
            $submittal->forceFill([
                'decision' => $decision,
                'decided_at' => $data['decided_at'],
                'notes' => $data['notes'] ?? null,
            ])->save();

            // The register mirrors the stamp, string for string.
            $submittal->drawing?->forceFill([
                'status' => DrawingStatus::from($decision->value),
            ])->save();

            return $submittal;
        });
    }

    private function assertUndecidedAndCurrent(DrawingSubmittal $submittal, string $verb): void
    {
        if ($submittal->isSuperseded()) {
            throw ValidationException::withMessages(['submittal' => sprintf(
                'Submittal %s telah digantikan revisi %s dan tidak dapat %s.',
                $submittal->code,
                $submittal->supersededBy?->code ?? '-',
                $verb,
            )]);
        }

        if ($submittal->isDecided()) {
            throw ValidationException::withMessages(['submittal' => sprintf(
                'Submittal %s sudah berkeputusan %s dan tidak dapat %s.',
                $submittal->code,
                $submittal->decision?->label(),
                $verb,
            )]);
        }
    }
}
