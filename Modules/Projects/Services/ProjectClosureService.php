<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Exceptions\ProjectClosureException;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\Project;

/**
 * The checklist 'Tutup proyek' has to pass, and the refusal it produces.
 *
 * This is the gate for closing WITHOUT a handover ceremony — proyek batal,
 * kontrak putus, data lama yang dirapikan. A project that ends the normal way
 * is closed by its BAST II, whose own gate (BastPrerequisiteService) is
 * stricter about handover order; this one asks the administrative question
 * instead: what is still OPEN on this project, and does the person closing it
 * know? On the demo file the answer used to be "Rp 9,7 miliar of hak tagih"
 * (termin 4 + 5 of CTR/2026/I/0001) and nothing asked.
 *
 * THE LINE BETWEEN A BLOCK AND A WARNING IS BastPrerequisiteService's line:
 * "can the business always satisfy it". An open PO can always be received or
 * cancelled and an accepted-critical defect can always be verified or waived,
 * so both block. An unbilled termin after a putus-kontrak settlement will
 * legitimately never be billed, and retensi on a defaulted customer will never
 * be released — so both warn, and the warning costs a recorded reason instead
 * of a routed-around block.
 *
 * The cross-module numbers are not re-derived here: termins and retention come
 * from BastPrerequisiteService's own readers, the open POs from ProjectService's
 * — one query per fact, however many gates quote it.
 */
class ProjectClosureService
{
    /** Enough codes to act on, few enough to read in a toast. */
    private const MAX_NAMED = 5;

    private const BLOCK = 'block';

    private const WARNING = 'warning';

    public function __construct(
        private readonly ProjectService $projects,
        private readonly BastPrerequisiteService $prerequisites,
    ) {}

    /**
     * The live open-items summary for one project — what closing costs, read
     * before anybody clicks.
     */
    public function evaluate(Project $project): array
    {
        $checks = array_merge(
            $this->statusCheck($project),
            $this->defectChecks($project),
            $this->purchaseOrderCheck($project),
            $this->receivableChecks($project),
        );

        return [
            'project_id' => $project->id,
            'project_code' => $project->code,
            'as_of' => now()->toDateString(),
            'can_close' => $this->failed($checks, self::BLOCK) === [],
            'needs_override' => $this->failed($checks, self::WARNING) !== [],
            'checks' => $checks,
        ];
    }

    /**
     * Refuse the close, or return the checklist to be snapshotted.
     *
     * The override lifts WARNINGS ONLY — same contract, same minimum length and
     * for the same reason as BastPrerequisiteService::assertApprovable.
     *
     * @throws ProjectClosureException
     */
    public function assertClosable(Project $project, ?string $overrideReason = null): array
    {
        $evaluation = $this->evaluate($project);
        $reason = $overrideReason === null ? null : trim($overrideReason);

        $blocked = $this->failed($evaluation['checks'], self::BLOCK);

        if ($blocked !== []) {
            throw new ProjectClosureException(
                "Proyek {$project->code} belum dapat ditutup — ".$this->sentence($blocked).'.',
                $blocked,
            );
        }

        $warnings = $this->failed($evaluation['checks'], self::WARNING);

        if ($warnings === []) {
            return $evaluation;
        }

        if ($reason === null || $reason === '') {
            throw new ProjectClosureException(
                "Proyek {$project->code} belum dapat ditutup — ".$this->sentence($warnings)
                    .'; sertakan alasan (minimal '.BastPrerequisiteService::MIN_OVERRIDE_REASON_LENGTH
                    .' karakter) bila tetap ditutup.',
                $warnings,
            );
        }

        if (mb_strlen($reason) < BastPrerequisiteService::MIN_OVERRIDE_REASON_LENGTH) {
            throw new ProjectClosureException(
                'Alasan melewati item terbuka harus dijelaskan, minimal '
                    .BastPrerequisiteService::MIN_OVERRIDE_REASON_LENGTH.' karakter.',
                $warnings,
            );
        }

        return $evaluation;
    }

    /**
     * The explicit action. Stamps who, when and what-was-true onto the project
     * row, so a year later "kenapa proyek ini tutup dengan termin terbuka?" has
     * an answer with a name on it.
     */
    public function close(Project $project, User $by, ?string $overrideReason = null): Project
    {
        $overrideReason = $overrideReason === null || trim($overrideReason) === '' ? null : trim($overrideReason);

        $evaluation = $this->assertClosable($project, $overrideReason);

        return DB::transaction(function () use ($project, $by, $overrideReason, $evaluation): Project {
            $usedOverride = $overrideReason !== null && $evaluation['needs_override'];

            $project->forceFill([
                'status' => ProjectStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $by->id,
                'closure_snapshot' => $evaluation,
                'closure_override_reason' => $usedOverride ? $overrideReason : null,
            ])->save();

            return $project->refresh();
        });
    }

    // ------------------------------------------------------------------ checks

    /**
     * @return array<int, array<string, mixed>>
     */
    private function statusCheck(Project $project): array
    {
        $open = $project->status !== ProjectStatus::Closed;

        return [
            $this->check(
                'belum_ditutup',
                self::BLOCK,
                $open,
                'Proyek belum berstatus Ditutup',
                $open
                    ? 'Proyek berstatus '.($project->status?->label() ?? '—').'.'
                    : 'proyek sudah berstatus Ditutup'
                        .($project->closed_at !== null ? ' sejak '.$project->closed_at->format('d-m-Y') : ''),
            ),
        ];
    }

    /**
     * The same register, the same severity line BAST II draws: accepted-critical
     * blocks, snagging-list minor warns.
     *
     * @return array<int, array<string, mixed>>
     */
    private function defectChecks(Project $project): array
    {
        $open = $project->openDefects()->orderBy('code')->get();
        $blocking = $open->filter(fn (Defect $defect): bool => $defect->severity->blocksHandover());
        $minor = $open->count() - $blocking->count();

        return [
            $this->check(
                'defect_berat',
                self::BLOCK,
                $blocking->isEmpty(),
                'Tidak ada temuan kritis/mayor yang terbuka',
                $blocking->isEmpty()
                    ? 'Tidak ada temuan kritis atau mayor yang masih terbuka.'
                    : $blocking->count().' temuan kritis/mayor masih terbuka ('.$this->named($blocking->pluck('code')->all(), $blocking->count()).')',
            ),
            $this->check(
                'defect_minor',
                self::WARNING,
                $minor === 0,
                'Tidak ada temuan minor yang terbuka',
                $minor === 0
                    ? 'Tidak ada temuan minor yang masih terbuka.'
                    : "{$minor} temuan minor masih terbuka",
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function purchaseOrderCheck(Project $project): array
    {
        $orders = $this->projects->openPurchaseOrders($project);

        return [
            $this->check(
                'po_terbuka',
                self::BLOCK,
                $orders['count'] === 0,
                'Tidak ada PO yang masih terbuka',
                $orders['count'] === 0
                    ? 'Tidak ada pesanan pembelian yang masih terbuka untuk proyek ini.'
                    : $orders['count'].' PO masih terbuka ('.$this->named($orders['codes'], $orders['count'])
                        .'); terima barangnya atau batalkan PO-nya',
            ),
        ];
    }

    /**
     * The receivables — the Rp 9,7 miliar the audit leads with.
     *
     * @return array<int, array<string, mixed>>
     */
    private function receivableChecks(Project $project): array
    {
        $termins = $this->prerequisites->unbilledTermins($project);
        $retention = $this->prerequisites->retentionAtStake($project);
        $withheld = $retention['source'] === 'fin_ar_retentions' ? $retention['amount'] : 0.0;

        return [
            $this->check(
                'termin_belum_ditagih',
                self::WARNING,
                $termins['count'] === 0,
                'Semua termin kontrak sudah ditagih',
                $termins['count'] === 0
                    ? 'Tidak ada termin kontrak yang belum ditagih.'
                    : $termins['count'].' termin belum ditagih senilai '.$this->rupiah($termins['amount']),
            ),
            // Only money GENUINELY withheld on issued invoices warns here. The
            // contractual-percentage fallback BastPrerequisiteService also
            // answers is a projection, and the unbilled retention termin it
            // projects from is already counted by the check above — warning on
            // both would bill the same rupiah twice in one sentence.
            $this->check(
                'retensi_belum_cair',
                self::WARNING,
                round($withheld, 2) <= 0,
                'Tidak ada retensi yang belum dicairkan',
                round($withheld, 2) <= 0
                    ? 'Tidak ada retensi yang masih tertahan pada invoice.'
                    : 'retensi '.$this->rupiah($withheld).' masih tertahan pada invoice dan belum dicairkan',
            ),
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function check(string $key, string $level, bool $passed, string $label, string $detail): array
    {
        return [
            'key' => $key,
            'level' => $level,
            'passed' => $passed,
            'label' => $label,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<int, array<string, mixed>>
     */
    private function failed(array $checks, string $level): array
    {
        return array_values(array_filter(
            $checks,
            fn (array $check): bool => $check['level'] === $level && $check['passed'] === false,
        ));
    }

    /**
     * The failing items as one Indonesian clause — the refusal IS the UI.
     *
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function sentence(array $checks): string
    {
        return implode('; ', array_map(fn (array $check): string => (string) $check['detail'], $checks));
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function named(array $codes, int $total): string
    {
        $shown = array_slice($codes, 0, self::MAX_NAMED);
        $rest = $total - count($shown);

        return implode(', ', $shown).($rest > 0 ? ", dan {$rest} lainnya" : '');
    }

    private function rupiah(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
