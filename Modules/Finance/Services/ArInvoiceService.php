<?php

namespace Modules\Finance\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\Erp;
use Modules\Core\Support\Money;
use Modules\Core\Support\Terbilang;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ArInvoice;
use Modules\Projects\Models\Project;

/**
 * AR termin invoicing:
 *
 *   dpp       = termin amount (or contract value * percent / 100), or — P3 —
 *               the period value of an APPROVED owner opname
 *   recovery  = potongan uang muka (OwnerAdvanceService; 0 without a billed DP)
 *   penalty   = denda, manual, reason mandatory
 *   ppn       = (dpp - recovery) * ppn_rate / 100   (PMK 131/2024 effective 11%;
 *               see recalc for why the recovered slice is out of the base)
 *   retention = optional per-termin retensi withheld by the customer
 *   total     = dpp + ppn - retention - recovery - penalty
 *
 * Approval books the revenue journal, records the retention receivable and
 * stamps the termin as billed.
 *
 * P3 — THE OWNER CLAIM. A claim carrying measurement_id is assembled from a
 * signed opname instead of a termin percentage: its DPP is measured volume at
 * contract prices, and two gates ride on it. The first is kriteria #6 — a claim
 * REFUSES to bill work in a zone whose latest BAPP says "Nunggu perbaikan",
 * because billing a zone we have already written down as defective is how a
 * retention gets released against work nobody accepted. The second is the DP:
 * an opname claim bills the FULL value of the work, so the uang muka paid
 * before any of it was built comes back out proportionally (OwnerAdvanceService,
 * the mirror of the SPK side).
 */
class ArInvoiceService
{
    /** Pendapatan Diterima Dimuka (Uang Muka) — the customer advance liability. */
    private const CUSTOMER_ADVANCE_ACCOUNT = '2-1400';

    /** Beban Denda & Potongan Lain-lain — denda the owner deducts from a claim. */
    private const PENALTY_ACCOUNT = '7-2400';

    /**
     * The BAPP mark an owner claim refuses to bill, BY VALUE.
     *
     * Projects\Enums\ZoneCertificateStatus::WaitingRepair is the definition;
     * this literal is the same read BastPrerequisiteService makes of qc_ncr's
     * two open statuses, and for the same reason — Finance reading one project
     * fact must not make Finance depend on the Projects module at runtime
     * (TerminBillingService's rule, applied to prj_milestones). A test asserts
     * the two still say the same word.
     */
    private const ZONE_BLOCKS_BILLING = 'waiting_repair';

    public function __construct(
        private readonly JournalService $journals,
        private readonly OwnerAdvanceService $advances,
    ) {}

    /**
     * Store entry point: from an approved owner opname when measurement_id is
     * given, from a contract termin when termin_id is, manual otherwise.
     */
    public function create(array $data): ArInvoice
    {
        if (! empty($data['measurement_id'])) {
            return $this->createFromMeasurement((int) $data['measurement_id'], $data);
        }

        if (! empty($data['termin_id'])) {
            /** @var ContractTermin $termin */
            $termin = ContractTermin::query()->findOrFail($data['termin_id']);

            return $this->createFromTermin($termin, $data);
        }

        return $this->createManual($data);
    }

    /**
     * Build the invoice for one termin of a contract.
     *
     * $options: invoice_date?, due_date?, description?, retention_withheld?,
     *           withhold_retention? (bool — compute from contract retention_pct)
     */
    public function createFromTermin(ContractTermin $termin, array $options = []): ArInvoice
    {
        return DB::transaction(function () use ($termin, $options): ArInvoice {
            /** @var ContractTermin $termin */
            $termin = ContractTermin::query()->whereKey($termin->id)->lockForUpdate()->firstOrFail();

            if ($termin->isBilled()) {
                throw new LogicException(
                    "Termin \"{$termin->name}\" of contract {$termin->contract->code} is already billed."
                );
            }

            if (ArInvoice::query()
                ->where('termin_id', $termin->id)
                ->whereNot('status', DocumentStatus::Cancelled->value)
                ->exists()) {
                throw new LogicException(
                    "An invoice already exists for termin \"{$termin->name}\"."
                );
            }

            /** @var Contract $contract */
            $contract = $termin->contract;

            if ($contract->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "Contract {$contract->code} is {$contract->status->value}; only approved contracts can be billed."
                );
            }

            // Temuan #32 — syarat penagihan dilewati SADAR, tidak diam-diam.
            $confirmationNote = $this->milestoneConfirmationNote($termin, $options);

            $dpp = (float) $termin->amount > 0
                ? round((float) $termin->amount, 2)
                : round((float) $contract->value * (float) $termin->percent / 100, 2);

            $ppnRate = (float) ($contract->ppn_rate ?? Erp::float('tax.ppn_rate', 11.0));

            // Retensi per termin: explicit amount wins, otherwise the contract
            // percentage when the caller opts in.
            $retention = isset($options['retention_withheld'])
                ? round((float) $options['retention_withheld'], 2)
                : (! empty($options['withhold_retention'])
                    ? round($dpp * (float) $contract->retention_pct / 100, 2)
                    : 0.0);

            // Temuan #73 — dua pola retensi, satu kontrak. A schedule that
            // carries an is_retention termin ("Retensi 5%" as the closing
            // termin of a 100% schedule) collects its retention BY BILLING
            // that termin; withholding per invoice on top of it records the
            // retention twice — 1-1350 doubles by ~5% of the contract value
            // and the customer is effectively billed 105%. Existing termin
            // rows all carry is_retention=false (the column is forward-only,
            // never backfilled), so live contracts already withholding per
            // invoice pass through here exactly as before.
            $this->assertRetentionNotDoubled($contract, $retention);

            $invoiceDate = $options['invoice_date'] ?? now()->toDateString();

            $description = $options['description']
                ?? "Penagihan {$termin->name} — {$contract->title} ({$contract->code})";

            return $this->build([
                'customer_id' => (int) $contract->customer_id,
                'contract_id' => (int) $contract->id,
                'termin_id' => (int) $termin->id,
                'project_id' => $this->projectIdForContract((int) $contract->id),
                'invoice_date' => $invoiceDate,
                'due_date' => $options['due_date']
                    ?? $this->defaultDueDate($invoiceDate, (int) $contract->customer_id),
                // The confirmation note rides the DESCRIPTION, not a side
                // table: the description is printed on the invoice and shown
                // on every list, so the deviation stays readable exactly where
                // the document is read. The column is 500 — the base text
                // yields to the note, never the other way around, because the
                // note is the audit fact this whole gate exists to record.
                //
                // Until the note itself reaches 500: a longer note flipped the
                // computed length NEGATIVE, and mb_substr then cut the BASE
                // from its END (emptying it outright once the note passed
                // base+500) while the overlong note still blew the column —
                // silently on SQLite, as a save error on strict MySQL. In that
                // pathological case the NOTE is what yields: the base names
                // the document, so it stays whole and the note keeps what
                // fits.
                'description' => mb_strlen($confirmationNote) < 500
                    ? mb_substr($description, 0, 500 - mb_strlen($confirmationNote)).$confirmationNote
                    : mb_substr($description.$confirmationNote, 0, 500),
                'dpp' => $dpp,
                'ppn_rate' => $ppnRate,
                'retention_withheld' => $retention,
            ]);
        });
    }

    /**
     * P3 — the owner claim, assembled from a SIGNED opname.
     *
     * The opname must be APPROVED. A claim built from a draft measurement is a
     * number nobody has agreed to, and the whole point of measuring volume per
     * BOQ item is that somebody signed for it.
     *
     * The termin is deliberately NOT stamped billed here even when one is
     * named: an opname claim bills MEASURED WORK against the contract, and a
     * termin's billed_at is what stops a percentage termin being invoiced
     * twice. Mixing the two schemes on one contract would let the same value
     * out through both doors.
     *
     * $options: invoice_date?, due_date?, description?, retention_withheld?,
     *           withhold_retention?, penalty_amount?, penalty_reason?
     */
    public function createFromMeasurement(int $measurementId, array $options = []): ArInvoice
    {
        return DB::transaction(function () use ($measurementId, $options): ArInvoice {
            $measurement = $this->measurementRow($measurementId);

            if ($measurement === null) {
                throw new LogicException("Opname #{$measurementId} tidak ditemukan.");
            }

            if ($measurement->status !== DocumentStatus::Approved->value) {
                throw new LogicException(
                    "Opname {$measurement->code} berstatus {$measurement->status}; "
                    .'hanya opname yang sudah disetujui dapat ditagihkan.'
                );
            }

            $existing = ArInvoice::query()
                ->where('measurement_id', $measurementId)
                ->whereNot('status', DocumentStatus::Cancelled->value)
                ->value('code');

            if ($existing !== null) {
                throw new LogicException(
                    "Opname {$measurement->code} sudah ditagihkan lewat invoice {$existing}."
                );
            }

            /** @var Contract $contract */
            $contract = Contract::query()->findOrFail($measurement->contract_id);

            if ($contract->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "Contract {$contract->code} is {$contract->status->value}; only approved contracts can be billed."
                );
            }

            // KRITERIA #6 — before anything is priced.
            $this->assertNoBlockedZone($measurement);

            $dpp = round((float) $measurement->period_amount, 2);

            if ($dpp <= 0.0) {
                throw new LogicException(
                    "Opname {$measurement->code} tidak mengukur volume apa pun pada periodenya; tidak ada yang dapat ditagih."
                );
            }

            $retention = isset($options['retention_withheld'])
                ? round((float) $options['retention_withheld'], 2)
                : (! empty($options['withhold_retention'])
                    ? round($dpp * (float) $contract->retention_pct / 100, 2)
                    : 0.0);

            $this->assertRetentionNotDoubled($contract, $retention);

            $invoiceDate = $options['invoice_date'] ?? now()->toDateString();

            return $this->build([
                'customer_id' => (int) $contract->customer_id,
                'contract_id' => (int) $contract->id,
                'termin_id' => null,
                'measurement_id' => $measurementId,
                'project_id' => $measurement->project_id !== null
                    ? (int) $measurement->project_id
                    : $this->projectIdForContract((int) $contract->id),
                'invoice_date' => $invoiceDate,
                'due_date' => $options['due_date']
                    ?? $this->defaultDueDate($invoiceDate, (int) $contract->customer_id),
                'description' => $options['description'] ?? sprintf(
                    'Penagihan opname %s periode %s s/d %s — %s (%s)',
                    $measurement->code,
                    $measurement->period_start,
                    $measurement->period_end,
                    $contract->title,
                    $contract->code,
                ),
                'dpp' => $dpp,
                'ppn_rate' => (float) ($contract->ppn_rate ?? Erp::float('tax.ppn_rate', 11.0)),
                'retention_withheld' => $retention,
                'penalty_amount' => round((float) ($options['penalty_amount'] ?? 0), 2),
                'penalty_reason' => $options['penalty_reason'] ?? null,
            ]);
        });
    }

    /**
     * KRITERIA #6 — an owner claim refuses to include a zone whose latest BAPP
     * says "Nunggu perbaikan", and the refusal NAMES the zone.
     *
     * "Latest" is certified_at, then id: a zone is inspected more than once
     * (BAPP I nunggu perbaikan → repair → BAPP II selesai) and only the last
     * sheet describes it now. That is the same rule
     * Projects\Services\ZoneCertificateService::statusFor applies; both tables
     * are read here BY VALUE rather than through that service, the way
     * TerminBillingService reads prj_milestones, so Finance keeps no runtime
     * dependency on Projects. A test pins the two answers together.
     *
     * Lines with NO location are not blocked and not silently passed either —
     * they are simply not zone work, and the BAPP has nothing to say about
     * them. Refusing them would make location_id mandatory by the back door,
     * which the spec explicitly says it is not.
     */
    private function assertNoBlockedZone(object $measurement): void
    {
        $locationIds = DB::table('prj_progress_measurement_items')
            ->where('progress_measurement_id', $measurement->id)
            ->whereNotNull('location_id')
            ->distinct()
            ->pluck('location_id')
            ->all();

        if ($locationIds === []) {
            return;
        }

        $blocked = [];

        foreach ($locationIds as $locationId) {
            $status = DB::table('prj_zone_certificates')
                ->where('project_id', $measurement->project_id)
                ->where('location_id', $locationId)
                ->whereNull('deleted_at')
                ->orderByRaw('certified_at IS NULL')
                ->orderByDesc('certified_at')
                ->orderByDesc('id')
                ->value('status');

            if ($status !== self::ZONE_BLOCKS_BILLING) {
                continue;
            }

            $zone = DB::table('core_locations')->where('id', $locationId)->first(['code', 'name']);

            $blocked[] = $zone === null
                ? "lokasi #{$locationId}"
                : "{$zone->code} — {$zone->name}";
        }

        if ($blocked === []) {
            return;
        }

        throw ValidationException::withMessages(['measurement_id' => sprintf(
            'Zona %s pada opname %s masih berstatus "Nunggu perbaikan"; '
            .'pekerjaan di zona itu tidak dapat ditagihkan sampai BAPP-nya menyatakan selesai.',
            implode('; ', $blocked),
            $measurement->code,
        )]);
    }

    /**
     * The opname row, read as a RAW TABLE — see assertNoBlockedZone for why
     * Finance does not reach for the Projects model here.
     */
    private function measurementRow(int $measurementId): ?object
    {
        return DB::table('prj_progress_measurements')
            ->where('id', $measurementId)
            ->whereNull('deleted_at')
            ->first(['id', 'code', 'project_id', 'contract_id', 'status', 'period_start', 'period_end', 'period_amount']);
    }

    /**
     * Approve + auto-journal:
     *
     *   Dr 1-1300 Piutang Usaha        total
     *   Dr 1-1350 Piutang Retensi      retention_withheld
     *   Dr 2-1400 Uang Muka            advance_recovery_amount   (P3)
     *   Dr 7-2400 Beban Denda          penalty_amount            (P3)
     *   Cr 4-xxxx Pendapatan           dpp        (account by project/contract type)
     *   Cr 2-1300 PPN Keluaran         ppn_amount
     *
     * Balanced by construction: total + retention + recovery + penalty = dpp + ppn.
     * Zero legs are dropped by autoPost, so an ordinary termin invoice posts
     * exactly the four lines it always did.
     *
     * AN ADVANCE INVOICE CREDITS 2-1400, NOT REVENUE. An uang muka is money
     * received for work not yet done — a contract liability, not income — and
     * the opname claims that follow bill the full value of the work and recover
     * it out of them. Crediting revenue here and again on those claims would
     * report the DP's value twice. Under the old termin-percentage scheme
     * nothing was recovered (DP 20 % + progres 80 % = the contract), so
     * is_advance defaults false and every existing invoice keeps posting to
     * revenue exactly as it always has. Forward-only: no historical journal is
     * restated by this.
     */
    public function approve(ArInvoice $invoice, User $by, ?string $note = null): ArInvoice
    {
        return DB::transaction(function () use ($invoice, $by, $note): ArInvoice {
            // KRITERIA #6 re-checked at the money moment: a zone can be marked
            // "Nunggu perbaikan" between drafting the claim and approving it,
            // and approving is what books the receivable.
            //
            // Only on a SUBMITTED invoice, the ordering ProjectService::
            // approveBast and Approvable's own maker-checker guard both use: a
            // draft must still fail with the trait's "while status is draft",
            // which is the more fundamental error and the message several
            // suites assert verbatim.
            if ($invoice->status === DocumentStatus::Submitted && $invoice->measurement_id !== null) {
                $measurement = $this->measurementRow((int) $invoice->measurement_id);

                if ($measurement !== null) {
                    $this->assertNoBlockedZone($measurement);
                }
            }

            $invoice->approve($by, $note); // Approvable: submitted -> approved

            $retention = (float) $invoice->retention_withheld;

            $this->journals->autoPost(
                'ar_invoice',
                (int) $invoice->id,
                [
                    [
                        'account_code' => '1-1300',
                        'debit' => (float) $invoice->total,
                        'description' => "Piutang {$invoice->code}",
                        'project_id' => $invoice->project_id,
                    ],
                    [
                        'account_code' => '1-1350',
                        'debit' => $retention,
                        'description' => "Retensi ditahan {$invoice->code}",
                        'project_id' => $invoice->project_id,
                    ],
                    [
                        'account_code' => self::CUSTOMER_ADVANCE_ACCOUNT,
                        'debit' => (float) $invoice->advance_recovery_amount,
                        'description' => "Potongan uang muka {$invoice->code}",
                        'project_id' => $invoice->project_id,
                    ],
                    [
                        'account_code' => self::PENALTY_ACCOUNT,
                        'debit' => (float) $invoice->penalty_amount,
                        'description' => trim("Denda {$invoice->code} — ".(string) $invoice->penalty_reason),
                        'project_id' => $invoice->project_id,
                    ],
                    [
                        'account_code' => $invoice->is_advance
                            ? self::CUSTOMER_ADVANCE_ACCOUNT
                            : $this->revenueAccountCode($invoice),
                        'credit' => (float) $invoice->dpp,
                        'description' => $invoice->description,
                        'project_id' => $invoice->project_id,
                    ],
                    [
                        'account_code' => '2-1300',
                        'credit' => (float) $invoice->ppn_amount,
                        'description' => "PPN Keluaran {$invoice->code}",
                        'project_id' => $invoice->project_id,
                    ],
                ],
                $invoice->invoice_date->toDateString(),
                "Invoice {$invoice->code} — {$invoice->description}",
                (int) $by->id,
            );

            if ($retention > 0) {
                $invoice->retentions()->updateOrCreate(
                    ['source_invoice_id' => $invoice->id],
                    [
                        'contract_id' => $invoice->contract_id,
                        'project_id' => $invoice->project_id,
                        'amount' => $retention,
                        'released' => false,
                        'released_at' => null,
                    ],
                );
            }

            if ($invoice->termin_id !== null) {
                ContractTermin::query()
                    ->whereKey($invoice->termin_id)
                    ->update(['billed_at' => $invoice->invoice_date]);
            }

            return $invoice->refresh();
        });
    }

    /**
     * Membatalkan invoice yang terlanjur disetujui (dan berjurnal).
     *
     * Until this existed a wrong termin invoice was permanent: the receivable
     * stayed in the aging report forever, the termin kept its billed_at stamp
     * so createFromTermin() refused the corrected invoice, and the only way out
     * was a manual JV — which repairs the GL and leaves the AR subledger saying
     * something different from it for good.
     *
     * Conditions: nothing has been received against it (a partly paid invoice
     * is settled, not cancelled — reverse the receipt first), a reason, and the
     * INVOICE'S OWN fiscal period still open, because the reversal is booked on
     * the invoice's date so the two legs net out inside one month. See the
     * cancellation migration for why the period rule is the document's and not
     * today's.
     *
     * Everything the approval left behind is released: the reversing journal,
     * the retention receivable this invoice raised, and the termin, which goes
     * back to unbilled so a replacement invoice can be issued.
     *
     * NOTE: a faktur pajak already issued to DJP is not withdrawn by this — the
     * operator still has to file the nota pembatalan. What is guaranteed here is
     * that the cancelled invoice drops out of the e-Faktur export, which filters
     * on approved status.
     */
    public function cancel(ArInvoice $invoice, User $by, ?string $reason = null): ArInvoice
    {
        return DB::transaction(function () use ($invoice, $by, $reason): ArInvoice {
            /** @var ArInvoice $invoice */
            $invoice = ArInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $reason = trim((string) $reason);

            if ($reason === '') {
                throw new LogicException('Alasan pembatalan wajib diisi.');
            }

            if ($invoice->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "Invoice {$invoice->code} berstatus {$invoice->status->value}; hanya invoice yang sudah disetujui yang dapat dibatalkan."
                );
            }

            // Tidak menyebut "batalkan penerimaannya lebih dulu": penerimaan
            // yang sudah diposting tidak dapat dibatalkan di mana pun dalam
            // aplikasi ini, jadi kalimat itu akan menunjuk ke operasi yang
            // tidak ada.
            if ((float) $invoice->amount_paid > 0) {
                throw new LogicException(
                    "Invoice {$invoice->code} sudah menerima pembayaran {$invoice->amount_paid}; "
                    .'hanya invoice yang belum dibayar yang dapat dibatalkan — penerimaan yang terlanjur salah alokasi dikoreksi lewat jurnal.'
                );
            }

            // Retensi yang sudah dicairkan adalah uang yang benar-benar masuk
            // melalui jurnal terpisah. Menghapus invoice sumbernya akan membuat
            // penerimaan itu menggantung tanpa dasar.
            if ($invoice->retentions()->where('released', true)->exists()) {
                throw new LogicException(
                    "Retensi dari invoice {$invoice->code} sudah dicairkan; invoice tidak dapat dibatalkan."
                );
            }

            $this->journals->reverseFor(
                'ar_invoice',
                (int) $invoice->id,
                'ar_invoice_cancellation',
                "Pembatalan invoice {$invoice->code} — {$reason}",
                (int) $by->id,
                $this->journals->reversalDate($invoice->invoice_date),
            );

            // The retention receivable was raised BY this invoice; the reversal
            // just credited 1-1350 back, so a row still claiming money is owed
            // would put the subledger above the ledger by exactly that amount.
            $invoice->retentions()->delete();

            // Termin kembali terbuka — inilah yang membuat invoice pengganti
            // bisa diterbitkan sama sekali.
            if ($invoice->termin_id !== null) {
                ContractTermin::query()
                    ->whereKey($invoice->termin_id)
                    ->update(['billed_at' => null]);
            }

            $invoice->forceFill([
                'status' => DocumentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => $reason,
            ])->save();

            // Same trail approve()/reject() write, so the document history reads
            // as one sequence. Approvable owns submit/approve/reject only.
            $invoice->approvals()->create([
                'action' => 'cancelled',
                'user_id' => $by->id,
                'note' => $reason,
            ]);

            return $invoice->refresh();
        });
    }

    /**
     * The editable check runs INSIDE the transaction, on a locked re-read.
     *
     * Asserting on the caller's instance left the same stale-instance window
     * JournalService::update() describes: a route-bound invoice is read
     * several DB round-trips before the handler reaches this line, and an
     * approval landing inside that window is invisible to the copy in hand.
     * The consequence here is worse than a wasted edit — approve() has already
     * autoPost()ed Dr 1-1300 / Cr 4-1100 + 2-1300 off dpp and ppn_rate, so
     * rewriting those fields afterwards leaves the posted journal saying one
     * number and the invoice saying another, with the AR aging and the 1-1300
     * control balance permanently apart.
     *
     * lockForUpdate() is a no-op on SQLite; the re-read plus the re-check on
     * the re-read instance is the real protection.
     */
    public function update(ArInvoice $invoice, array $data): ArInvoice
    {
        return DB::transaction(function () use ($invoice, $data): ArInvoice {
            /** @var ArInvoice $invoice */
            $invoice = ArInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($invoice);

            $invoice->fill(Arr::only($data, [
                'invoice_date', 'due_date', 'description', 'dpp', 'ppn_rate', 'retention_withheld',
                // P3 — the two manual deductions. measurement_id and is_advance
                // are deliberately NOT editable: they decide which gates ran
                // and which account the credit leg lands on, and switching
                // either on a draft would silently re-price a claim somebody
                // has already read.
                'penalty_amount', 'penalty_reason',
            ]));

            // Pagar dua-pola retensi berlaku juga di sini: invoice yang lahir
            // bersih dari kontrak ber-termin-retensi bisa DIEDIT membawa
            // retensi semenit kemudian — field-nya currency biasa pada draf,
            // dan verifier mereproduksi persis itu. Pagar di create saja
            // adalah gerbang yang dibiarkan terbuka.
            if ((float) $invoice->retention_withheld > 0 && $invoice->contract_id !== null) {
                $this->assertRetentionNotDoubled(
                    Contract::query()->findOrFail($invoice->contract_id),
                    (float) $invoice->retention_withheld,
                );
            }

            $this->recalc($invoice);
            $invoice->save();

            return $invoice->refresh();
        });
    }

    /**
     * Surat penagihan berikutnya — naikkan tingkatnya, catat tanggalnya,
     * tulis jejak auditnya (T3.7).
     *
     * Produksi 4 Sep 2026 (ANALISIS-PROSES §2 baris Q2C, celah A2):
     * INV/2026/VIII/0004 Rp 15,42 M disetujui, jatuh tempo 22 Sep — pengawas
     * ar_invoice_due akan menyebutnya keesokan paginya, lalu berhenti di
     * situ: "diawasi tetapi tanpa tindakan", karena surat penagihan tidak
     * ada di sistem. Kini surat ke-1/2/3 adalah formulir rumah, dan tombol
     * "Cetak surat penagihan ke-N" melewati metode ini SEBELUM lembarnya
     * dicetak: lembar ke-N hanya bisa dirender bila dunning_level sudah N
     * (FinanceFormService::dunningLetterDate), jadi mencetak dan mencatat
     * tidak pernah terpisah.
     *
     * Yang boleh dan tidak ditentukan SATU kali di ArInvoice::dunningRefusal
     * (status, lunas, belum jatuh tempo, sudah surat terakhir) — tombol
     * membaca jawabannya lewat dunning_next_level, dan pemanggil curl
     * mendapat kalimat yang sama sebagai 422. Kunci baris seperti update():
     * dua orang menekan tombol bersamaan tidak boleh melompat dari ke-1 ke
     * ke-3 tanpa pernah mencetak ke-2.
     *
     * Jejak audit ditulis EKSPLISIT (AuditService::event): fin_ar_invoices
     * tidak diamati observer — dokumen sengaja absen dari AuditedModels —
     * dan sebuah surat penagihan bukan transisi persetujuan, jadi tanpa baris
     * ini tidak ada yang mencatat siapa yang mengeskalasi invoice dan kapan.
     */
    public function issueDunningLetter(ArInvoice $invoice): ArInvoice
    {
        return DB::transaction(function () use ($invoice): ArInvoice {
            /** @var ArInvoice $invoice */
            $invoice = ArInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $refusal = $invoice->dunningRefusal();

            if ($refusal !== null) {
                throw new LogicException($refusal);
            }

            $from = ['dunning_level' => (int) $invoice->dunning_level, 'last_dunning_at' => $invoice->last_dunning_at];

            $invoice->forceFill([
                'dunning_level' => (int) $invoice->dunning_level + 1,
                'last_dunning_at' => Carbon::now(),
            ])->save();

            app(AuditService::class)->event($invoice, 'dunning', [
                'dunning_level' => ['from' => $from['dunning_level'], 'to' => (int) $invoice->dunning_level],
                'last_dunning_at' => ['from' => $from['last_dunning_at'], 'to' => $invoice->last_dunning_at],
            ], $invoice->code);

            return $invoice->refresh();
        });
    }

    /**
     * Same window as update(), landing on soft-delete: an invoice approved
     * between the route binding and this call would be hidden from every
     * report while its posted journal — and the receivable it created — stayed
     * in the ledger.
     */
    public function delete(ArInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            /** @var ArInvoice $invoice */
            $invoice = ArInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($invoice);

            $invoice->delete();
        });
    }

    /**
     * Register the e-Faktur number once DJP issues it (post-approval step).
     *
     * DJP issues each nomor seri exactly once, so the ERP may hold it once.
     * Status used to be the only precondition and the column carried no index:
     * a clerk copying the previous termin's number onto the next invoice — the
     * field is free text and pre-filled by nothing — produced two FK records
     * under one serial in the same e-Faktur file, Rp 1.177.000.000 of PPN
     * keluaran reported against a number issued for Rp 1.067.000.000, with
     * `blocked` still 0 and nothing on the export screen comparing the two.
     *
     * A CANCELLED invoice keeps its serial: the nota pembatalan cites it, so it
     * is spent, not released. Re-registering the same number on the SAME invoice
     * stays allowed — correcting a typo is not a duplicate.
     */
    public function registerFakturPajak(ArInvoice $invoice, string $fakturNo): ArInvoice
    {
        if ($invoice->status !== DocumentStatus::Approved) {
            throw new LogicException("Faktur pajak can only be set on an approved invoice ({$invoice->code}).");
        }

        $holder = ArInvoice::query()
            ->where('faktur_pajak_no', $fakturNo)
            ->whereKeyNot($invoice->getKey())
            ->value('code');

        if ($holder !== null) {
            throw new LogicException(
                "Nomor faktur pajak {$fakturNo} sudah dipakai invoice {$holder}; "
                .'satu nomor seri dari DJP hanya boleh dipakai satu faktur.'
            );
        }

        $invoice->forceFill(['faktur_pajak_no' => $fakturNo])->save();

        return $invoice;
    }

    /**
     * Temuan #32 — the billing condition, finally read by the module that
     * bills.
     *
     * A termin's milestones live in prj_milestones (termin_id), certified by
     * the PM; createFromTermin never looked at them, so Finance could invoice
     * 'Progress 80%' while certified progress stood at 55% — the owner's MK
     * rejects the invoice, the BAP never comes, and the customer relationship
     * takes the damage. A hard block would be wrong the other way: negotiated
     * early billings and addendum deviations are real. So the violation is an
     * EXPLICIT CONFIRMATION: a 422 on termin_id (the confirmResubmit contract
     * the SPA already speaks, temuan #72) until the payload carries
     * confirm_unachieved_milestone — and the confirmed invoice records the
     * fact in its description, where everyone who reads the document reads it.
     *
     * The gate fires only when milestones EXIST for the termin and NONE is
     * achieved — one achieved milestone makes the termin billable, the same
     * rule TerminBillingService::achievedMilestones() applies to the queue.
     * A calendar termin (no milestones) passes untouched.
     *
     * prj_milestones is read as a raw table, not through
     * Modules\Projects\Models\Milestone, for the reason TerminBillingService
     * documents: Finance reading one project fact must not make Finance
     * depend on the Projects module at runtime.
     *
     * @return string '' when nothing was overridden; otherwise the note to
     *                append to the invoice description
     */
    private function milestoneConfirmationNote(ContractTermin $termin, array $options): string
    {
        $milestones = DB::table('prj_milestones')
            ->where('termin_id', $termin->id)
            ->orderBy('due_date')
            ->get(['name', 'achieved_date']);

        if ($milestones->isEmpty()
            || $milestones->contains(fn (object $milestone): bool => $milestone->achieved_date !== null)) {
            return '';
        }

        $names = $milestones->pluck('name')->implode('", "');

        if (empty($options['confirm_unachieved_milestone'])) {
            // ValidationException, not LogicException: the SPA's confirm-then-
            // resubmit flow keys on a 422 whose errors name termin_id, and the
            // message below is exactly what the operator is asked to confirm.
            throw ValidationException::withMessages([
                'termin_id' => "Milestone \"{$names}\" — syarat penagihan termin \"{$termin->name}\" — belum tercapai. "
                    .'Menagih sekarang perlu konfirmasi eksplisit dan akan tercatat pada invoice.',
            ]);
        }

        return " [Konfirmasi: milestone \"{$names}\" belum tercapai — tetap ditagih.]";
    }

    private function createManual(array $data): ArInvoice
    {
        return DB::transaction(function () use ($data): ArInvoice {
            // Vektor yang sama tanpa termin: invoice manual pada kontrak
            // ber-termin-retensi menggandakan 1-1350 persis seperti jalur
            // termin, hanya lewat pintu lain.
            $this->assertRetentionNotDoubled(
                Contract::query()->findOrFail((int) $data['contract_id']),
                round((float) ($data['retention_withheld'] ?? 0), 2),
            );

            $invoiceDate = $data['invoice_date'] ?? now()->toDateString();

            return $this->build([
                'customer_id' => (int) $data['customer_id'],
                'contract_id' => (int) $data['contract_id'],
                'termin_id' => null,
                'project_id' => $data['project_id']
                    ?? $this->projectIdForContract((int) $data['contract_id']),
                'invoice_date' => $invoiceDate,
                'due_date' => $data['due_date']
                    ?? $this->defaultDueDate($invoiceDate, (int) $data['customer_id']),
                'description' => $data['description'],
                'dpp' => round((float) $data['dpp'], 2),
                'ppn_rate' => (float) ($data['ppn_rate'] ?? Erp::float('tax.ppn_rate', 11.0)),
                'retention_withheld' => round((float) ($data['retention_withheld'] ?? 0), 2),
                // P3 — the manual door for a DP invoice (there is no termin and
                // no opname behind an uang muka) and for the two deductions.
                'is_advance' => (bool) ($data['is_advance'] ?? false),
                'penalty_amount' => round((float) ($data['penalty_amount'] ?? 0), 2),
                'penalty_reason' => $data['penalty_reason'] ?? null,
            ]);
        });
    }

    /**
     * Dua pola retensi, satu kontrak, satu saja: kontrak yang jadwalnya memuat
     * termin ber-flag retensi menagih retensinya LEWAT termin itu, jadi
     * potongan retensi per invoice pada kontrak yang sama mencatat retensi
     * dua kali — 1-1350 menggelembung dan pencairan retensi menemukan dua
     * saldo untuk satu kewajiban. Tiga jalur bisa membawa rupiah retensi
     * (createFromTermin, createManual, update pada draf), dan ketiganya
     * melewati pagar yang satu ini.
     */
    private function assertRetentionNotDoubled(Contract $contract, float $retention): void
    {
        if ($retention > 0 && $contract->hasRetentionTermin()) {
            throw new LogicException(
                "Kontrak {$contract->code} menagih retensinya lewat termin retensi pada jadwalnya sendiri; "
                .'hapus potongan retensi pada invoice ini agar tidak tercatat dobel.'
            );
        }
    }

    private function build(array $attributes): ArInvoice
    {
        $invoice = new ArInvoice($attributes);
        $invoice->status = DocumentStatus::Draft;
        $invoice->amount_paid = 0;
        // Written explicitly rather than left to the column defaults: a DB
        // default is not hydrated on a freshly built model, and recalc() below
        // reads all three before the row exists (the WorkPermitService rule).
        $invoice->is_advance = (bool) ($attributes['is_advance'] ?? false);
        $invoice->advance_recovery_amount = 0;
        $invoice->penalty_amount = round((float) ($attributes['penalty_amount'] ?? 0), 2);

        $this->recalc($invoice);
        $invoice->save(); // HasDocumentNumber fills the INV code

        return $invoice;
    }

    /**
     * ppn, recovery, total and terbilang always derive from
     * dpp / rate / retention / penalty.
     *
     * PPN IS CHARGED ON (dpp − recovery), mirroring the SPK opname exactly: the
     * DP invoice already charged PPN on the advance, so leaving the recovered
     * slice in the base here would charge it a second time. Across the contract
     * the total PPN still comes to value × rate, which is the whole test of
     * whether the netting is right. Retention does NOT reduce the base — it
     * defers payment, not the supply.
     *
     * With no DP billed the recovery is zero and every line of this reduces to
     * the arithmetic every existing invoice was computed with.
     */
    private function recalc(ArInvoice $invoice): void
    {
        $dpp = round((float) $invoice->dpp, 2);
        $retention = round((float) $invoice->retention_withheld, 2);
        $penalty = round((float) $invoice->penalty_amount, 2);

        if ($retention > $dpp) {
            throw new LogicException('Retention withheld cannot exceed the invoice DPP.');
        }

        $this->assertPenaltyIsAccountedFor($invoice, $penalty);

        if ($invoice->is_advance && $retention > 0) {
            throw new LogicException(
                'Invoice uang muka tidak menahan retensi: retensi ditahan dari nilai pekerjaan, '
                .'dan uang muka belum membeli pekerjaan apa pun.'
            );
        }

        $recovery = $this->recoveryFor($invoice, $dpp, $retention, $penalty);
        $ppn = round(($dpp - $recovery) * (float) $invoice->ppn_rate / 100, 2);
        $total = round($dpp + $ppn - $retention - $recovery - $penalty, 2);

        if ($total < 0) {
            throw new LogicException(sprintf(
                'Potongan pada invoice ini (retensi %s, uang muka %s, denda %s) melebihi nilai tagihannya; '
                .'kurangi dendanya atau tagih pada periode berikutnya.',
                Money::format($retention),
                Money::format($recovery),
                Money::format($penalty),
            ));
        }

        $invoice->advance_recovery_amount = $recovery;
        $invoice->ppn_amount = $ppn;
        $invoice->total = $total;
        $invoice->terbilang = Terbilang::rupiah($total);
    }

    /**
     * Potongan uang muka — zero unless a DP was actually billed and approved on
     * this contract. An advance invoice recovers nothing (it IS the advance).
     */
    private function recoveryFor(ArInvoice $invoice, float $dpp, float $retention, float $penalty): float
    {
        if ($invoice->is_advance || $invoice->contract_id === null) {
            return 0.0;
        }

        $contract = Contract::query()->find($invoice->contract_id);

        if ($contract === null) {
            return 0.0;
        }

        return $this->advances->recoveryFor(
            $contract,
            $invoice->exists ? (int) $invoice->getKey() : null,
            $dpp,
            $retention,
            $penalty,
        );
    }

    /**
     * DENDA WITHOUT A REASON IS A NUMBER NOBODY CAN DEFEND.
     *
     * A deduction on a signed Risalah Pembayaran is read months later by
     * somebody reconciling why the customer paid less than the claim; an
     * unexplained one is exactly the plausible-looking cell PANDUAN §13.5
     * refuses to print, and it is also how a keying error survives review. The
     * amount is manual on purpose — nothing here pretends to compute
     * liquidated damages from a contract clause — so the reason is the only
     * evidence the figure has.
     */
    private function assertPenaltyIsAccountedFor(ArInvoice $invoice, float $penalty): void
    {
        if ($penalty < 0) {
            throw ValidationException::withMessages([
                'penalty_amount' => 'Denda tidak boleh negatif; potongan yang menambah tagihan bukan denda.',
            ]);
        }

        if ($penalty > 0 && trim((string) $invoice->penalty_reason) === '') {
            throw ValidationException::withMessages([
                'penalty_reason' => 'Denda wajib disertai alasan — sebutkan dasar pemotongannya '
                    .'(keterlambatan, pekerjaan tidak sesuai, atau kesepakatan lain).',
            ]);
        }
    }

    /**
     * Revenue account by scope: konstruksi 4-1100, integrasi 4-1200,
     * pemeliharaan 4-1300. Project type wins, contract scope is the fallback.
     */
    private function revenueAccountCode(ArInvoice $invoice): string
    {
        $scope = null;

        if ($invoice->project_id !== null) {
            $scope = Project::query()->whereKey($invoice->project_id)->value('type');
        }

        $scope ??= Contract::query()->whereKey($invoice->contract_id)->value('scope_type');

        $scope = $scope instanceof \BackedEnum ? $scope->value : $scope;

        return match ($scope) {
            'system_integration' => '4-1200',
            'maintenance' => '4-1300',
            default => '4-1100',
        };
    }

    private function projectIdForContract(int $contractId): ?int
    {
        $id = Project::query()->where('contract_id', $contractId)->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function defaultDueDate(string $invoiceDate, int $customerId): string
    {
        $termDays = (int) (Customer::query()->whereKey($customerId)->value('payment_term_days') ?? 30);

        return Carbon::parse($invoiceDate)->addDays($termDays)->toDateString();
    }

    private function assertEditable(ArInvoice $invoice): void
    {
        if (! $invoice->status->isEditable()) {
            throw new LogicException(
                "Invoice {$invoice->code} is {$invoice->status->value} and can no longer be edited."
            );
        }
    }
}
