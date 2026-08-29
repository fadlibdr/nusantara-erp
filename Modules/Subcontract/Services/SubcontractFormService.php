<?php

namespace Modules\Subcontract\Services;

use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Terbilang;
use Modules\Procurement\Models\Vendor;
use Modules\Subcontract\Models\LaborClaim;
use Modules\Subcontract\Models\LaborContract;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractAddendum;

/**
 * The body of the three Subkontrak house forms, in the taste of
 * Modules\Projects\Services\LaporanFormService.
 *
 * Almost every cell on an SPK, an addendum and a berita acara opname is
 * already a stored column — SubcontractService, AddendumService and
 * ClaimService computed and saved them, and re-deriving any of them here would
 * be a second answer to a question the ledger has already answered once. So
 * this class holds only the four answers that are NOT a straight read, each of
 * them a decision that needs saying out loud:
 *
 *   NO PPN IS NOT AN UNKNOWN PPN. scm_subcontracts.ppn_rate is 0 exactly when
 *   the subcontractor is not PKP (see the table's own comment), so a ruled
 *   blank there would invite somebody to add PPN to a bill that must not carry
 *   any. The sheet states the fact in words instead.
 *
 *   NILAI SPK SEBELUM / SETELAH ADDENDUM is reconstructible in two cases and
 *   in no others; see addendumValues(). Where it is not, both come back null
 *   and the sheet rules them, because a pair of numbers that LOOK derived is
 *   worse on a signed berita acara than an honest gap.
 *
 *   A DP CLAIM IS NOT AN OPNAME. Both live in scm_progress_claims, and a DP
 *   has no progress lines at all — printing it through the opname body would
 *   produce a sheet of zero percentages, which reads as a period in which
 *   nothing was built rather than as a prepayment.
 *
 *   THE PAYMENT SCHEDULE IS NOT RECORDED ANYWHERE. Not partially — nowhere.
 *   prc_vendors.payment_term_days is a master-data default for invoicing that
 *   vendor, not a term of this SPK, so the registry rules that line for the
 *   pen rather than borrowing a number from the vendor card.
 */
class SubcontractFormService
{
    /**
     * The four values scm_subcontract_addenda.reason accepts (see
     * AddendumStoreRequest), as the paper spells them.
     *
     * A value outside this list is printed AS STORED rather than mapped to
     * "Lainnya": if a fifth reason is ever added to the request and not here,
     * the sheet should show the unfamiliar word and prompt the question, not
     * quietly file it under something else.
     */
    private const ADDENDUM_REASONS = [
        'permintaan_pemberi_kerja' => 'Permintaan pemberi kerja',
        'kondisi_lapangan' => 'Kondisi lapangan',
        'desain' => 'Perubahan desain',
        'lainnya' => 'Lainnya',
    ];

    // ------------------------------------------------------------ spk subkon

    /**
     * What the SPK's notes block says: the scope narrative, whatever was typed
     * into notes, and the reason the prakualifikasi gate was overridden when
     * it was (temuan #35).
     *
     * In the notes rather than an identity line for the reason spelled out in
     * ProcurementFormService::orderNotes — an identity line prints its label
     * with a ruled value, so a clean SPK would carry an empty rule inviting an
     * override reason to be written onto it after the fact.
     */
    public function spkNotes(Subcontract $subcontract): ?string
    {
        return $this->paragraphs([
            $this->labelled('Lingkup pekerjaan', $subcontract->scope),
            $subcontract->notes,
            $this->labelled('Override prakualifikasi vendor', $subcontract->qualification_override_reason),
        ]);
    }

    /**
     * The PPN line of the identity block, in words.
     *
     * A rate of 0 means the subcontractor is not PKP and this SPK carries no
     * PPN at all — a fact worth stating, and the one cell on the sheet where a
     * ruled blank would be actively dangerous.
     */
    public function ppnLine(Subcontract $subcontract): string
    {
        $rate = (float) $subcontract->ppn_rate;

        return $rate > 0
            ? $this->percent($rate).' dari DPP'
            : 'Tidak dikenakan PPN (subkontraktor non-PKP)';
    }

    /** "PPN 11%", or a label that does not claim a rate when none applies. */
    public function ppnLabel(Subcontract $subcontract): string
    {
        $rate = (float) $subcontract->ppn_rate;

        return $rate > 0 ? 'PPN '.$this->percent($rate) : 'PPN (tidak dikenakan)';
    }

    public function ppnAmount(Subcontract $subcontract): float
    {
        return round((float) $subcontract->value * (float) $subcontract->ppn_rate / 100, 2);
    }

    public function totalWithPpn(Subcontract $subcontract): float
    {
        return round((float) $subcontract->value + $this->ppnAmount($subcontract), 2);
    }

    /**
     * The retention the SPK will withhold across its opnames, at the rate
     * stored on the SPK — the same multiplication ClaimService::recalcTotals
     * performs per opname, stated once on the cover sheet.
     */
    public function retentionAmount(Subcontract $subcontract): float
    {
        return round((float) $subcontract->value * (float) $subcontract->retention_pct / 100, 2);
    }

    // --------------------------------------------------------- addendum spk

    /** The reason as the paper spells it, or as stored when unfamiliar. */
    public function addendumReason(SubcontractAddendum $addendum): ?string
    {
        $reason = trim((string) ($addendum->reason ?? ''));

        return $reason === '' ? null : (self::ADDENDUM_REASONS[$reason] ?? $reason);
    }

    /**
     * What the SPK was worth before this addendum and what it is worth after —
     * or nulls, when neither can be proven.
     *
     * Three cases, and only the first two are answerable:
     *
     *   NOT YET APPROVED (draft or submitted). scm_subcontracts.value has not
     *   moved, so "before" is the live value and "after" is what approving
     *   this addendum would make it — which is precisely the arithmetic
     *   AddendumService::approve is about to perform, and precisely what the
     *   three parties are signing to authorise.
     *
     *   APPROVED, AND THE SPK'S ONLY APPROVED ADDENDUM. original_value is by
     *   definition the value before the FIRST approval, so it IS this
     *   addendum's before, and the live value is its after.
     *
     *   ANYTHING ELSE — a second approved addendum has landed, or this one was
     *   rejected. The intermediate value is recorded in no column: original_
     *   value is the value before the first, value is the value after the
     *   last, and nothing holds the steps between. Rejected is worse than
     *   unknown; it is a change that will never happen, and projecting a
     *   "setelah" for it would state a value the SPK is never going to have.
     *   Both cells come back null and the sheet rules them.
     *
     * @return array{before: ?float, after: ?float}
     */
    public function addendumValues(SubcontractAddendum $addendum): array
    {
        $subcontract = $addendum->subcontract;

        if ($subcontract === null) {
            return ['before' => null, 'after' => null];
        }

        $change = round((float) $addendum->value_change, 2);
        $value = round((float) $subcontract->value, 2);

        if (in_array($addendum->status, [DocumentStatus::Draft, DocumentStatus::Submitted], true)) {
            return ['before' => $value, 'after' => round($value + $change, 2)];
        }

        if ($addendum->status !== DocumentStatus::Approved) {
            return ['before' => null, 'after' => null];
        }

        $approved = $subcontract->addenda()
            ->where('status', DocumentStatus::Approved->value)
            ->count();

        if ($approved !== 1 || $subcontract->original_value === null) {
            return ['before' => null, 'after' => null];
        }

        return ['before' => round((float) $subcontract->original_value, 2), 'after' => $value];
    }

    // --------------------------------------------------------- opname subkon

    /**
     * Which of the two documents scm_progress_claims is holding this time.
     *
     * Printed in the identity block because the two are paid differently and
     * signed for differently: a DP withholds no retention and no PPh, an
     * opname withholds both, and a reader who cannot tell them apart at a
     * glance cannot check either.
     */
    public function claimKind(ProgressClaim $claim): string
    {
        return $claim->is_advance ? 'Uang muka (DP)' : 'Opname progres pekerjaan';
    }

    /**
     * The single-row terbilang table under the payment summary.
     *
     * Spelled by Core's own Terbilang, so the berita acara and the AP bill
     * that follows it cannot word the same amount two different ways.
     *
     * @return list<array<string, mixed>>
     */
    public function terbilangRow(ProgressClaim $claim): array
    {
        return [[
            'amount' => (float) $claim->net_payable,
            'terbilang' => Terbilang::rupiah((float) $claim->net_payable),
        ]];
    }

    /**
     * "Retensi ditahan (5%)" on an opname, "(tidak ditahan atas uang muka)" on
     * a DP — the rate is the SPK's, never a template's, and it is stated only
     * where it was actually applied.
     *
     * A DP is not retained against, so the sheet must not print a rate beside
     * the zero.
     *
     * "Retensi ditahan (5%) … 0,00" on an uang-muka sheet is arithmetic a
     * reader can check and find wrong: 5 % of 420.000.000 is 21.000.000, not
     * nothing. The rate is real — it is the SPK's — but it was deliberately
     * not applied to this claim, and a label that states it makes a correct
     * sheet look like a mistake. Same move the PPN label already makes when
     * the rate is zero.
     */
    public function retentionLabel(ProgressClaim $claim): string
    {
        if ($claim->is_advance) {
            return 'Retensi ditahan (tidak ditahan atas uang muka)';
        }

        $rate = (float) ($claim->subcontract?->retention_pct ?? 0);

        return 'Retensi ditahan ('.$this->percent($rate).')';
    }

    public function claimPpnLabel(ProgressClaim $claim): string
    {
        $rate = (float) ($claim->subcontract?->ppn_rate ?? 0);

        return $rate > 0 ? 'PPN '.$this->percent($rate) : 'PPN (tidak dikenakan)';
    }

    /**
     * Same reason as retentionLabel(): a DP withholds nothing.
     *
     * ClaimService::recalcTotals says it in so many words — "The DP claim
     * itself withholds nothing, so across the SPK the base sums to the value
     * once" — the PPh falls on the opnames that follow. Printing
     * "PPh final konstruksi 2,65% (dipotong) … 0,00" on the uang-muka sheet
     * states a deduction that was correctly not taken.
     */
    public function claimPphLabel(ProgressClaim $claim): string
    {
        if ($claim->is_advance) {
            return 'PPh final konstruksi (dipotong pada opname berikutnya)';
        }

        $rate = (float) ($claim->subcontract?->pph_rate ?? 0);

        return 'PPh final konstruksi '.$this->percent($rate).' (dipotong)';
    }

    // ------------------------------------------------------------- P4: mandor

    /**
     * Riwayat SP3 seorang mandor untuk lembar F/CVM — pengalaman kerja yang
     * BENAR-BENAR tercatat di sistem ini, tidak lebih: SP3 approved/closed,
     * proyeknya, periodenya, nilainya. Riwayat di luar sistem milik lampiran
     * CV-nya sendiri (prc_vendor_documents tipe cv_mandor), bukan tebakan
     * lembar ini; tabel kosong berkata begitu apa adanya.
     *
     * @return list<LaborContract>
     */
    public function mandorContractRows(Vendor $vendor): array
    {
        return LaborContract::query()
            ->with(['project' => fn ($query) => $query->withTrashed()])
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Baris F/RU — rekap upah PER PROYEK PER PERIODE, dijangkarkan pada satu
     * opname mandor: setiap opname mandor proyek yang sama yang periodenya
     * BERIRISAN dengan periode lembar ini, opname jangkarnya termasuk.
     *
     * Statusnya ikut tercetak per baris, karena rekap yang mencampur draf dan
     * approved tanpa berkata mana yang mana adalah angka total yang bohong —
     * kolom status membiarkan pembaca menjumlah sendiri yang sudah sah.
     *
     * Kasbonnya ikut dimuat (withTrashed, seperti setiap dokumen uang di
     * registri): aturan kejujuran P4 menuntut potongan kasbon menyebut KODE
     * kasbonnya di lembar yang ditandatangani, dan kasbon yang dihapus setelah
     * dipotong tetap harus bernama pada cetak ulangnya.
     *
     * @return list<LaborClaim>
     */
    public function rekapUpahRows(LaborClaim $claim): array
    {
        $projectId = $claim->laborContract?->project_id;

        if ($projectId === null) {
            return [$claim->loadMissing(['kasbon' => fn ($query) => $query->withTrashed()])];
        }

        return LaborClaim::query()
            ->with([
                'laborContract' => fn ($query) => $query->withTrashed(),
                'laborContract.vendor' => fn ($query) => $query->withTrashed(),
                'kasbon' => fn ($query) => $query->withTrashed(),
            ])
            ->whereHas('laborContract', fn ($query) => $query
                ->withTrashed()
                ->where('project_id', $projectId))
            ->whereDate('period_start', '<=', $claim->period_end)
            ->whereDate('period_end', '>=', $claim->period_start)
            ->orderBy('period_start')
            ->orderBy('id')
            ->get()
            ->all();
    }

    // ------------------------------------------------------------- internals

    /**
     * 5,0000 stored, "5%" printed.
     *
     * The fourth copy of this shortening in the codebase and deliberate, for
     * the reason FormPrintService::MONTHS gives about its own: these strings
     * are assembled HERE because they carry words around the number, and
     * reaching into the print service for the number alone would couple a
     * module's read model to Core's private formatting.
     */
    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',').'%';
    }

    private function labelled(string $label, mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $label.' : '.$text;
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    private function paragraphs(array $parts): ?string
    {
        $kept = array_values(array_filter(array_map(
            static fn (?string $part): string => trim((string) $part),
            $parts,
        ), static fn (string $part): bool => $part !== ''));

        return $kept === [] ? null : implode("\n\n", $kept);
    }
}
