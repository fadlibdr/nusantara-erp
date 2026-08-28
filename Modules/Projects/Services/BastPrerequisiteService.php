<?php

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\DocumentStatus;
use Modules\Projects\Enums\BastType;
use Modules\Projects\Enums\DefectStatus;
use Modules\Projects\Exceptions\BastPrerequisiteException;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\Project;

/**
 * The checklist BAST II has to pass, and the refusal it produces.
 *
 * Approving BAST II closes the project and publishes the date on which the
 * customer's retensi — Rp 2.425.000.000 on CTR/2026/I/0001 — becomes
 * collectible. Until now the only thing standing between a draft and that
 * outcome was one click by anybody holding prj.approve: no BAST I needed to
 * exist, the date could precede the first handover, the punch list was not
 * consulted because there was no punch list, and a second BAST II could close
 * an already closed project.
 *
 * THE LINE BETWEEN A BLOCK AND A WARNING IS "CAN THE BUSINESS ALWAYS SATISFY
 * IT". Every hard block below is something a project can fix in an afternoon —
 * approve the BAST I, correct a date, verify or waive an item. Every warning is
 * something a real project legitimately fails and cannot correct after the fact:
 * warranty_months is master data that is wrong often enough (CTR/2026/III/0003
 * carries 0), and actual_progress_pct reads 55,0000 on PRJ-2026-001 four months
 * from BAST-15% billing and 0,0000 on PRJ-2026-002 with nine live leaf tasks.
 * A block on a number that stale is a block people learn to route around, and a
 * gate people route around protects Rp 2,4 miliar no better than nothing did.
 *
 * INFO IS NOT A SOFTER WARNING. The unbilled termins are info precisely because
 * the retention termin is unbilled BY DEFINITION at the moment BAST II is
 * approved — making it a warning would put a standing override on every single
 * BAST II, which is the muted-bell failure MilestoneService's docblock already
 * names.
 */
class BastPrerequisiteService
{
    /** Long enough that "ok, sudah" cannot be a reason for releasing Rp 2,4 miliar. */
    public const MIN_OVERRIDE_REASON_LENGTH = 20;

    private const BLOCK = 'block';

    private const WARNING = 'warning';

    private const INFO = 'info';

    /** Enough codes to act on, few enough to read in a toast. */
    private const MAX_NAMED_DEFECTS = 5;

    /**
     * The live checklist for one BAST.
     *
     * BAST I is not gated at all: it is the START of masa pemeliharaan, it
     * releases no retention and there is nothing yet to check it against.
     */
    public function evaluate(Bast $bast): array
    {
        $project = $bast->project ?? Project::query()->find($bast->project_id);
        $retention = $this->retentionAtStake($project);

        $payload = [
            'bast_id' => $bast->id,
            'bast_code' => $bast->code,
            'bast_type' => $bast->bast_type?->value,
            'as_of' => now()->toDateString(),
            'can_approve' => true,
            'needs_override' => false,
            'retention_at_stake' => $retention['amount'],
            'retention_source' => $retention['source'],
            'checks' => [],
        ];

        if ($project === null) {
            return $payload;
        }

        // P1-QC: BAST I — the start of masa pemeliharaan — is gated on ONE
        // thing, "no open NCR". A first handover must not proceed while a
        // nonconformance is still unresolved on the project. It is otherwise
        // ungated (it releases no retention and there is nothing yet to check it
        // against), so this is the whole of its checklist. defectChecks and the
        // rest stay BAST II's, where the retensi is at stake.
        if ($bast->bast_type === BastType::Bast1) {
            $checks = $this->ncrChecks($project);
            $payload['checks'] = $checks;
            $payload['can_approve'] = $this->failed($checks, self::BLOCK) === [];

            return $payload;
        }

        if ($bast->bast_type !== BastType::Bast2) {
            return $payload;
        }

        $checks = array_merge(
            $this->handoverChecks($bast, $project),
            $this->defectChecks($bast, $project),
            $this->progressCheck($project),
            $this->informationalChecks($project, $retention),
        );

        $payload['checks'] = $checks;
        $payload['can_approve'] = $this->failed($checks, self::BLOCK) === [];
        $payload['needs_override'] = $this->failed($checks, self::WARNING) !== [];

        return $payload;
    }

    /**
     * Refuse the approval, or return the checklist to be snapshotted.
     *
     * The override lifts WARNINGS ONLY. A gate whose blocks can be talked past
     * with one free-text field is a warning system wearing a gate's clothes, so
     * supplying a reason against a block changes nothing at all.
     *
     * @throws BastPrerequisiteException
     */
    public function assertApprovable(Bast $bast, ?string $overrideReason = null): array
    {
        $evaluation = $this->evaluate($bast);
        $reason = $overrideReason === null ? null : trim($overrideReason);
        $label = $this->documentLabel($bast);

        $blocked = $this->failed($evaluation['checks'], self::BLOCK);

        if ($blocked !== []) {
            throw new BastPrerequisiteException(
                "{$label} belum dapat disetujui — ".$this->sentence($blocked).'.',
                $blocked,
            );
        }

        $warnings = $this->failed($evaluation['checks'], self::WARNING);

        if ($warnings === []) {
            return $evaluation;
        }

        if ($reason === null || $reason === '') {
            throw new BastPrerequisiteException(
                "{$label} belum dapat disetujui — ".$this->sentence($warnings)
                    .'; sertakan alasan (minimal '.self::MIN_OVERRIDE_REASON_LENGTH.' karakter) bila tetap disetujui.',
                $warnings,
            );
        }

        if (mb_strlen($reason) < self::MIN_OVERRIDE_REASON_LENGTH) {
            throw new BastPrerequisiteException(
                'Alasan melewati prasyarat harus dijelaskan, minimal '.self::MIN_OVERRIDE_REASON_LENGTH.' karakter.',
                $warnings,
            );
        }

        return $evaluation;
    }

    // ------------------------------------------------------------------ checks

    /**
     * The three checks about the handover itself.
     *
     * @return array<int, array<string, mixed>>
     */
    private function handoverChecks(Bast $bast, Project $project): array
    {
        $firstHandover = Bast::query()
            ->where('project_id', $project->id)
            ->where('bast_type', BastType::Bast1->value)
            ->where('status', DocumentStatus::Approved->value)
            ->orderBy('handover_date')
            ->first();

        $otherSecond = Bast::query()
            ->where('project_id', $project->id)
            ->where('bast_type', BastType::Bast2->value)
            ->where('status', DocumentStatus::Approved->value)
            ->whereKeyNot($bast->id)
            ->first();

        $handover = $bast->handover_date;
        $firstDate = $firstHandover?->handover_date;
        $releaseDue = $firstHandover?->retention_release_due;

        return [
            $this->check(
                'bast_pertama',
                self::BLOCK,
                $firstHandover !== null,
                'BAST I sudah disetujui',
                $firstHandover !== null
                    ? "BAST I {$firstHandover->code} disetujui, serah terima ".$firstDate?->format('d-m-Y').'.'
                    : 'belum ada BAST I yang disetujui untuk proyek ini, sehingga masa pemeliharaan belum pernah dimulai',
            ),
            $this->check(
                'bast_kedua_tunggal',
                self::BLOCK,
                $otherSecond === null,
                'Belum ada BAST II lain yang disetujui',
                $otherSecond === null
                    ? 'Belum ada BAST II lain pada proyek ini.'
                    : "BAST II {$otherSecond->code} sudah disetujui dan menutup proyek ini",
            ),
            $this->check(
                'urutan_tanggal',
                self::BLOCK,
                $firstDate === null || $handover === null || $handover->gte($firstDate),
                'Tanggal BAST II tidak mendahului BAST I',
                $firstDate === null || $handover === null || $handover->gte($firstDate)
                    ? 'Urutan tanggal serah terima wajar.'
                    : 'tanggal BAST II '.$handover->format('d-m-Y').' mendahului serah terima pertama '.$firstDate->format('d-m-Y'),
            ),
            // A WARNING, not a block. warranty_months is master data nobody can
            // correct after the fact (CTR/2026/III/0003 carries 0) and addendums
            // do shorten warranties. Approving early costs the CUSTOMER their
            // security, not the contractor — a recorded reason is proportionate.
            //
            // A BAST I WITH NO RELEASE DATE FAILS THE CHECK — unknown is never
            // satisfied. When this passed on null, a BAST II dated one day after
            // BAST I raised no warning at all, and approval then stamped its own
            // handover date as the retention release date: Rp 2.425.000.000
            // published as collectible roughly twelve months early, silently.
            $this->check(
                'masa_pemeliharaan',
                self::WARNING,
                $releaseDue !== null && ($handover === null || $handover->gte($releaseDue)),
                'Masa pemeliharaan sudah berakhir',
                $releaseDue === null
                    ? 'BAST I tidak mencantumkan akhir masa pemeliharaan.'
                    : (($handover === null || $handover->gte($releaseDue))
                        ? 'Masa pemeliharaan berakhir '.$releaseDue->format('d-m-Y').'.'
                        : 'masa pemeliharaan baru berakhir '.$releaseDue->format('d-m-Y')
                            .', BAST II bertanggal '.$handover->format('d-m-Y')),
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defectChecks(Bast $bast, Project $project): array
    {
        $open = Defect::query()
            ->where('project_id', $project->id)
            ->whereIn('status', $this->openStatuses())
            ->orderBy('code')
            ->get();

        $blocking = $open->filter(fn (Defect $defect): bool => $defect->severity->blocksHandover());
        $minor = $open->count() - $blocking->count();
        $recorded = Defect::query()->where('project_id', $project->id)->count();

        return [
            $this->check(
                'defect_berat',
                self::BLOCK,
                $blocking->isEmpty(),
                'Tidak ada temuan kritis/mayor yang terbuka',
                $blocking->isEmpty()
                    ? 'Tidak ada temuan kritis atau mayor yang masih terbuka.'
                    : $blocking->count().' temuan kritis/mayor masih terbuka ('.$this->namedCodes($blocking).')',
            ),
            // A WARNING. Sisa cat, sealant, list plafon: these linger and
            // customers sign BAST II with a snagging note every day. Hard-blocking
            // here would train people to delete rows, which is worse than having
            // no register at all.
            $this->check(
                'defect_minor',
                self::WARNING,
                $minor === 0,
                'Tidak ada temuan minor yang terbuka',
                $minor === 0
                    ? 'Tidak ada temuan minor yang masih terbuka.'
                    : "{$minor} temuan minor masih terbuka",
            ),
            // Info, and the honest one: the gate is only as strong as the
            // register is used, so an empty punch list has to be visible at the
            // moment somebody releases Rp 2,4 miliar.
            $this->check(
                'defect_tercatat',
                self::INFO,
                true,
                'Temuan tercatat',
                $recorded === 0
                    ? 'Belum ada satu pun temuan tercatat pada proyek ini.'
                    : "{$recorded} temuan tercatat, {$open->count()} masih terbuka.",
            ),
        ];
    }

    /**
     * P1-QC — the ONE check on a BAST I: no open NCR on the project.
     *
     * A hard BLOCK, and satisfiable the same way every hard block here is — an
     * open NCR is verified or closed in QC, an afternoon's work, not a number
     * nobody can correct. Reads qc_ncr behind Schema::hasTable exactly as
     * retentionAtStake reads fin_ar_retentions: Projects must NOT depend on
     * Quality (the dependency arrow is Quality → Projects), so there is no
     * Quality model here and the two "open" status strings are literals,
     * NcrStatus::openValues() by value — the Quality tests assert they match.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ncrChecks(Project $project): array
    {
        $open = $this->openNcrCodes($project);
        $count = count($open);
        $named = array_slice($open, 0, self::MAX_NAMED_DEFECTS);
        $rest = $count - count($named);
        $list = implode(', ', $named).($rest > 0 ? ", dan {$rest} lainnya" : '');

        return [
            $this->check(
                'ncr_terbuka',
                self::BLOCK,
                $count === 0,
                'Tidak ada NCR yang masih terbuka',
                $count === 0
                    ? 'Tidak ada NCR yang masih terbuka pada proyek ini.'
                    : "{$count} NCR masih terbuka ({$list}); verifikasi atau tutup dahulu sebelum serah terima pertama",
            ),
        ];
    }

    /**
     * Open NCR codes on the project, or an empty list when Quality is not
     * installed. `open` = status in (open, under_correction) — NcrStatus's own
     * definition, by value.
     *
     * @return list<string>
     */
    private function openNcrCodes(Project $project): array
    {
        if (! Schema::hasTable('qc_ncr')) {
            return [];
        }

        return DB::table('qc_ncr')
            ->where('project_id', $project->id)
            ->whereIn('status', ['open', 'under_correction'])
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function progressCheck(Project $project): array
    {
        $actual = round((float) $project->actual_progress_pct, 4);

        return [
            // A WARNING and never a block. The demo settles it: PRJ-2026-001 sits
            // at 55,0000% four months from BAST-15% billing and PRJ-2026-002
            // reports 0,0000% on a live project with nine leaf tasks. The WBS is
            // a planning instrument here, not a certificate, and by BAST II time
            // it is months stale — blocking on it would make BAST II unobtainable
            // and teach people to fake progress instead.
            $this->check(
                'progres_fisik',
                self::WARNING,
                $actual >= 100.0,
                'Progres fisik 100%',
                $actual >= 100.0
                    ? 'Progres fisik 100,00%.'
                    : 'progres fisik pada WBS baru '.number_format($actual, 2, ',', '.').'%',
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function informationalChecks(Project $project, array $retention): array
    {
        $termins = $this->unbilledTermins($project);

        return [
            // The rupiah this click unlocks, in front of the person clicking.
            $this->check(
                'retensi',
                self::INFO,
                true,
                'Retensi yang dilepas',
                $this->rupiah($retention['amount']).' ('.($retention['source'] === 'fin_ar_retentions'
                    ? 'dari retensi yang benar-benar ditahan'
                    : 'perkiraan '.number_format((float) $project->retention_pct, 2, ',', '.').'% dari nilai kontrak').').',
            ),
            $this->check(
                'termin_belum_ditagih',
                self::INFO,
                true,
                'Termin belum ditagih',
                $termins['count'] === 0
                    ? 'Tidak ada termin kontrak yang belum ditagih.'
                    : $termins['count'].' termin belum ditagih senilai '.$this->rupiah($termins['amount']).'.',
            ),
        ];
    }

    // ------------------------------------------------------------- cross-module

    /**
     * What this approval actually releases.
     *
     * fin_ar_retentions is the truth when rows exist — that is money genuinely
     * withheld on issued invoices. It holds 0 rows on the demo today, so the
     * fallback is the contractual 5% of the contract value, which is the number
     * the site would quote anyway. Read behind Schema::hasTable exactly as
     * ProjectService::openPurchaseOrderCount reads prc_purchase_orders.
     *
     * Public because ProjectClosureService quotes the same fact on the Tutup
     * proyek checklist — one reader per cross-module number, however many
     * gates cite it.
     *
     * @return array{amount: float, source: string}
     */
    public function retentionAtStake(?Project $project): array
    {
        if ($project === null) {
            return ['amount' => 0.0, 'source' => 'project_retention_pct'];
        }

        if (Schema::hasTable('fin_ar_retentions')) {
            $withheld = (float) DB::table('fin_ar_retentions')
                ->where('project_id', $project->id)
                ->where('released', false)
                ->sum('amount');

            if (round($withheld, 2) > 0) {
                return ['amount' => round($withheld, 2), 'source' => 'fin_ar_retentions'];
            }
        }

        return ['amount' => $project->retentionAmount(), 'source' => 'project_retention_pct'];
    }

    /**
     * Public for the same reason as retentionAtStake above.
     *
     * @return array{count: int, amount: float}
     */
    public function unbilledTermins(Project $project): array
    {
        if ($project->contract_id === null || ! Schema::hasTable('crm_contract_termins')) {
            return ['count' => 0, 'amount' => 0.0];
        }

        $rows = DB::table('crm_contract_termins')
            ->where('contract_id', $project->contract_id)
            ->whereNull('billed_at')
            ->get(['amount']);

        return [
            'count' => $rows->count(),
            'amount' => round((float) $rows->sum('amount'), 2),
        ];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<int, string>
     */
    private function openStatuses(): array
    {
        return array_values(array_map(
            fn (DefectStatus $status): string => $status->value,
            array_filter(DefectStatus::cases(), fn (DefectStatus $status): bool => $status->isOpen()),
        ));
    }

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
     * The failing items as one Indonesian clause. This is the whole UI.
     *
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function sentence(array $checks): string
    {
        return implode('; ', array_map(fn (array $check): string => (string) $check['detail'], $checks));
    }

    private function namedCodes($defects): string
    {
        $codes = $defects->take(self::MAX_NAMED_DEFECTS)->pluck('code')->all();
        $rest = $defects->count() - count($codes);

        return implode(', ', $codes).($rest > 0 ? ", dan {$rest} lainnya" : '');
    }

    private function documentLabel(Bast $bast): string
    {
        return ($bast->isBast2() ? 'BAST II' : 'BAST I').' '.$bast->code;
    }

    private function rupiah(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
