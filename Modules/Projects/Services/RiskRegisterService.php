<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\RiskRegisterEntry;

/**
 * P6: register IBPRP — dan ATURAN KEJUJURAN SKORNYA.
 *
 * NILAI RISIKO ADALAH ARITMETIKA: initial_score = likelihood × severity,
 * residual_score = residual_likelihood × residual_severity. Keduanya DIHITUNG
 * di sini dan hanya di sini; klaim skor (atau tingkat) yang ikut terkirim di
 * payload dibuang tanpa dibaca — sebuah lembar yang bisa mengetik skornya
 * sendiri adalah matriks risiko yang tidak pernah dihitung siapa pun.
 *
 * Risiko sisa dinilai BERPASANGAN atau tidak sama sekali: kemungkinan tanpa
 * keparahan bukan penilaian setengah jadi melainkan bukan penilaian; NULL
 * tersimpan apa adanya dan lembar F/IBPRP menggarisi selnya, bukan mencetak 0.
 */
class RiskRegisterService
{
    /** Klaim yang tidak pernah diterima dari klien. */
    private const COMPUTED = ['initial_score', 'residual_score', 'initial_level', 'residual_level'];

    public function create(array $data, User $by): RiskRegisterEntry
    {
        Project::query()->findOrFail((int) $data['project_id']);

        [$residualLikelihood, $residualSeverity] = $this->residualPair(
            $data['residual_likelihood'] ?? null,
            $data['residual_severity'] ?? null,
        );

        return RiskRegisterEntry::query()->create(
            Arr::except($data, [...self::COMPUTED, 'residual_likelihood', 'residual_severity'])
            + ['created_by' => $by->id]
            + $this->scores((int) $data['likelihood'], (int) $data['severity'], $residualLikelihood, $residualSeverity),
        );
    }

    public function update(RiskRegisterEntry $entry, array $data): RiskRegisterEntry
    {
        // Baris register tidak pindah proyek; lembar F/IBPRP-nya per proyek.
        unset($data['project_id']);

        // Nilai EFEKTIF: payload bila kuncinya dikirim, tersimpan bila tidak —
        // update parsial yang hanya menggeser keparahan tetap dihitung ulang
        // terhadap kemungkinan lama.
        $likelihood = (int) (array_key_exists('likelihood', $data) ? $data['likelihood'] : $entry->likelihood);
        $severity = (int) (array_key_exists('severity', $data) ? $data['severity'] : $entry->severity);

        [$residualLikelihood, $residualSeverity] = $this->residualPair(
            array_key_exists('residual_likelihood', $data) ? $data['residual_likelihood'] : $entry->residual_likelihood,
            array_key_exists('residual_severity', $data) ? $data['residual_severity'] : $entry->residual_severity,
        );

        $entry->fill(
            Arr::except($data, [...self::COMPUTED, 'created_by', 'residual_likelihood', 'residual_severity'])
            + $this->scores($likelihood, $severity, $residualLikelihood, $residualSeverity),
        )->save();

        return $entry;
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{initial_score: int, residual_likelihood: ?int, residual_severity: ?int, residual_score: ?int} */
    private function scores(int $likelihood, int $severity, ?int $residualLikelihood, ?int $residualSeverity): array
    {
        return [
            'initial_score' => $likelihood * $severity,
            'residual_likelihood' => $residualLikelihood,
            'residual_severity' => $residualSeverity,
            'residual_score' => $residualLikelihood !== null && $residualSeverity !== null
                ? $residualLikelihood * $residualSeverity
                : null,
        ];
    }

    /** @return array{0: ?int, 1: ?int} */
    private function residualPair(mixed $likelihood, mixed $severity): array
    {
        if (($likelihood === null) !== ($severity === null)) {
            throw ValidationException::withMessages([
                'residual_likelihood' => 'Risiko sisa dinilai lengkap: kemungkinan DAN keparahan, atau kosongkan keduanya.',
            ]);
        }

        return [
            $likelihood === null ? null : (int) $likelihood,
            $severity === null ? null : (int) $severity,
        ];
    }
}
