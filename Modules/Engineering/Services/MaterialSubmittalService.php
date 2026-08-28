<?php

namespace Modules\Engineering\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\Engineering\Enums\SubmittalDecision;
use Modules\Engineering\Models\MaterialSubmittal;

/**
 * P1-ENG: the SMS lifecycle. The decision-recording rules are the
 * DrawingSubmittalService rules — recorded fact, once, recorder ≠ creator —
 * minus the supersede chain (a returned material is re-submitted as a NEW SMS
 * row; see migration 001320 for why).
 */
class MaterialSubmittalService
{
    public function create(array $data, User $by): MaterialSubmittal
    {
        return MaterialSubmittal::query()->create(
            Arr::except($data, ['code', 'decision', 'decided_at', 'notes', 'created_by'])
            + ['created_by' => $by->id],
        );
    }

    public function update(MaterialSubmittal $submittal, array $data): MaterialSubmittal
    {
        if ($submittal->isDecided()) {
            throw ValidationException::withMessages(['submittal' => sprintf(
                'Submittal %s sudah berkeputusan %s dan tidak dapat diubah; '
                    .'material yang dikembalikan diajukan sebagai submittal baru.',
                $submittal->code,
                $submittal->decision?->label(),
            )]);
        }

        $submittal->fill(Arr::except($data, [
            'code', 'project_id', 'decision', 'decided_at', 'notes', 'created_by',
        ]))->save();

        return $submittal;
    }

    public function delete(MaterialSubmittal $submittal): void
    {
        if ($submittal->isDecided()) {
            throw ValidationException::withMessages(['submittal' => sprintf(
                'Submittal %s sudah berkeputusan %s dan tidak dapat dihapus.',
                $submittal->code,
                $submittal->decision?->label(),
            )]);
        }

        $submittal->delete();
    }

    /** Type the MK's stamp in — once, by someone other than the creator. */
    public function recordDecision(MaterialSubmittal $submittal, array $data, User $recorder): MaterialSubmittal
    {
        if ($submittal->isDecided()) {
            throw ValidationException::withMessages(['decision' => sprintf(
                'Keputusan %s sudah tercatat untuk %s pada %s dan tidak dapat ditimpa; '
                    .'bila lembar stempel berbeda, ajukan submittal baru.',
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

        $submittal->forceFill([
            'decision' => SubmittalDecision::from((string) $data['decision']),
            'decided_at' => $data['decided_at'],
            'notes' => $data['notes'] ?? null,
        ])->save();

        return $submittal;
    }
}
