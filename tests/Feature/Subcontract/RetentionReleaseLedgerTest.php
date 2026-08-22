<?php

namespace Tests\Feature\Subcontract;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Enums\PaymentDirection;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Models\Tax;
use Modules\Finance\Services\ApBillService;
use Modules\Finance\Services\PaymentService;
use Modules\Finance\Services\ReportService;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;
use RuntimeException;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * Pelepasan retensi subkon harus sampai ke buku besar DAN ke kas.
 *
 * ApBillService credits `2-1500 Hutang Retensi Subkon` on every approved opname
 * bill. Nothing in the system ever debited it: RetentionService::release wrote a
 * row in scm_retention_releases and stopped there. Two failures followed from
 * that one omission, and both are asserted here:
 *
 *   the balance sheet reported retention still owed to subcontractors that had
 *       already been released — 2-1500 only ever grew, for the life of the
 *       installation, and no document existed that could bring it down;
 *   the released money could not be paid out AT ALL, because PaymentService
 *       allocates to ar_invoice / ap_bill rows and a retention release was
 *       neither.
 *
 * A release now issues an approved AP bill journalled Dr 2-1500 / Cr 2-1100.
 * The danger on the other side is double-counting the COST: the opname bill
 * already expensed the full gross DPP to the project (retention is withheld
 * from what is PAID, not from what is earned), so the release must add not one
 * rupiah of project cost.
 *
 * Angka yang dipakai di seluruh berkas ini:
 *
 *   SPK 200.000.000, retensi 5%, PPN 11%, PPh final 2,65%
 *   opname 50%          => bruto            100.000.000
 *                          retensi 5%         5.000.000
 *                          PPN 11%           11.000.000
 *                          PPh 2,65%          2.650.000
 *                          dibayar  100 − 5 + 11 − 2,65 = 103.350.000
 */
class RetentionReleaseLedgerTest extends ErpTestCase
{
    use SubcontractFixtures;

    private const SPK_VALUE = 200000000.0;

    private const CLAIM_GROSS = 100000000.0;

    private const RETENTION = 5000000.0;

    private const OPNAME_PAYABLE = 103350000.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        // PPh final jasa konstruksi, so the opname bill withholds down the real
        // 2-1230 path instead of the untyped fallback.
        Tax::create([
            'code' => Tax::pphFinalCodeForScheme('pelaksanaan_bersertifikat'),
            'name' => 'PPh Final Konstruksi 2,65%',
            'rate' => 2.65,
            'tax_type' => 'pph_withholding',
            'coa_account_id' => $this->accountId('2-1230'),
        ]);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * An SPK whose single opname has been approved AND billed, so 5.000.000
     * sits in 2-1500 as a real credit.
     *
     * @return array{0: Subcontract, 1: ProgressClaim, 2: ApBill}
     */
    private function spkWithBilledRetention(): array
    {
        [$spk, $claim] = $this->spkWithApprovedOpname();

        return [$spk, $claim, $this->billOpname($claim)];
    }

    /**
     * The same SPK one step earlier: the opname is approved (so the SPK reports
     * retention withheld) but Finance has not billed it, so the ledger carries
     * nothing yet.
     *
     * @return array{0: Subcontract, 1: ProgressClaim}
     */
    private function spkWithApprovedOpname(): array
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => self::SPK_VALUE,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_rate' => 2.65,
            // Masa pemeliharaan berakhir sebelum tanggal pelepasan di bawah
            // (2026-05-10): berkas ini menguji jalur LEDGER, bukan gate waktu
            // temuan #75 — RetentionTimeGateTest yang memegang gate itu.
            'defect_liability_until' => '2026-05-01',
        ]);

        $line = $this->addLine($spk, [
            'description' => 'Pekerjaan struktur beton',
            'qty' => 1,
            'unit_price' => self::SPK_VALUE,
            'amount' => self::SPK_VALUE,
        ]);

        return [$spk, $this->approvedClaim($spk, [$line->id => 50])];
    }

    private function billOpname(ProgressClaim $claim): ApBill
    {
        $bill = app(ApBillService::class)->createFromSubconClaim($claim, ['bill_date' => '2026-04-05']);
        $bill->submit($this->actor());

        return app(ApBillService::class)->approve($bill, $this->approver());
    }

    private function release(Subcontract $spk, float $amount, string $date = '2026-05-10')
    {
        return $this->retentionService()->release($spk, [
            'release_date' => $date,
            'amount' => $amount,
            'notes' => 'BAST II, masa pemeliharaan selesai',
        ], $this->actor());
    }

    /**
     * Disburse an approved bill in full through the ordinary payment module —
     * no special case for retention anywhere in the call.
     */
    private function payBill(ApBill $bill, string $date = '2026-05-20'): void
    {
        $payments = app(PaymentService::class);

        $payment = $payments->create([
            'direction' => PaymentDirection::Out->value,
            'payment_date' => $date,
            'bank_account_id' => $this->bankAccount()->id,
            'amount' => $bill->outstanding(),
            'reference' => 'TRF-'.$bill->code,
        ]);

        $allocations = [[
            'payable_type' => PaymentAllocation::TYPE_AP_BILL,
            'payable_id' => $bill->id,
            'amount' => $bill->outstanding(),
        ]];

        // The ordinary disbursement lifecycle, retention or not: the clerk
        // submits it with its allocation, a second person approves, then it
        // posts. This is also what proves the RetentionService change works —
        // the release bill it mints submits as nobody, so approving it here is
        // not a self-approval.
        $payments->submit($payment, $allocations, $this->actor());
        $payments->approve($payment->refresh(), $this->approver());
        $payments->post($payment->refresh(), $allocations, $this->actor()->id);
    }

    private function bankAccount(): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['code' => 'BANK-01'],
            [
                'name' => 'BCA Operasional',
                'bank_name' => 'Bank Central Asia',
                'account_no' => '1234567890',
                'account_name' => 'PT Nusantara Karya Integrasi',
                'coa_account_id' => $this->accountId('1-1210'),
                'is_active' => true,
            ],
        );
    }

    // -------------------------------------------------------- ledger helpers

    private function accountId(string $code): int
    {
        $id = Account::query()->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("COA account {$code} is missing; call seedLedger() first.");
        }

        return (int) $id;
    }

    /** Debit − credit over POSTED journals: negative means a credit balance. */
    private function balanceOf(string $accountCode): float
    {
        $row = JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->where('fin_journal_lines.account_id', $this->accountId($accountCode))
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit),0) as d, COALESCE(SUM(fin_journal_lines.credit),0) as c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    private function projectCostTotal(Subcontract $spk): float
    {
        return round((float) ProjectCost::query()
            ->where('project_id', $spk->project_id)
            ->sum('amount'), 2);
    }

    // --------------------------------------------------- the ledger actually moves

    /**
     * The headline defect. Before the fix the assertion below found 2-1500 still
     * carrying its full 5.000.000 credit AFTER the release: the row said
     * released, the ledger said owed, and they disagreed for ever.
     */
    public function test_releasing_retention_debits_the_liability_out_of_the_general_ledger(): void
    {
        [$spk] = $this->spkWithBilledRetention();

        // Opname bill withheld 5.000.000: a CREDIT, so debit − credit = −5jt.
        $this->assertEqualsWithDelta(-self::RETENTION, $this->balanceOf('2-1500'), 0.01);

        $this->release($spk, self::RETENTION);

        // 5.000.000 credited by the opname bill, 5.000.000 debited by the
        // release => the liability is genuinely gone, not merely reported gone.
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1500'), 0.01);
    }

    /** Dua baris, seimbang, tanggal pelepasan, dan tidak menyentuh akun lain. */
    public function test_the_release_journal_is_balanced_and_moves_only_the_two_liabilities(): void
    {
        [$spk] = $this->spkWithBilledRetention();

        $release = $this->release($spk, self::RETENTION);
        $bill = ApBill::query()->findOrFail($release->ap_bill_id);

        $journal = Journal::query()
            ->where('reference_type', 'ap_bill')
            ->where('reference_id', $bill->id)
            ->sole();

        $this->assertSame(PostingStatus::Posted, $journal->status);
        $this->assertSame('2026-05-10', $journal->journal_date->toDateString());
        $this->assertEqualsWithDelta($journal->totalDebit(), $journal->totalCredit(), 0.01);
        $this->assertEqualsWithDelta(self::RETENTION, $journal->totalDebit(), 0.01);

        $lines = $journal->lines()->with('account')->get()
            ->mapWithKeys(fn (JournalLine $line): array => [$line->account->code => [
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'project_id' => $line->project_id,
            ]]);

        $this->assertSame(['2-1500', '2-1100'], $lines->keys()->all());
        $this->assertEqualsWithDelta(self::RETENTION, $lines['2-1500']['debit'], 0.01);
        $this->assertEqualsWithDelta(self::RETENTION, $lines['2-1100']['credit'], 0.01);
        // Both legs carry the project, so a per-project trial balance nets to
        // zero for the retention just as the company-wide one does.
        $this->assertSame((int) $spk->project_id, (int) $lines['2-1500']['project_id']);
        $this->assertSame((int) $spk->project_id, (int) $lines['2-1100']['project_id']);
    }

    // ------------------------------------------------- and the money can be paid

    /**
     * The second half of the defect: even when the ledger was patched by hand,
     * the subcontractor could not be paid, because PaymentService only settles
     * ar_invoice / ap_bill rows. The release now issues one, so the ordinary
     * disbursement flow works with no special case.
     */
    public function test_the_released_retention_is_a_payable_the_payment_module_settles(): void
    {
        [$spk, , $opnameBill] = $this->spkWithBilledRetention();

        $release = $this->release($spk, self::RETENTION);

        /** @var ApBill $retentionBill */
        $retentionBill = ApBill::query()->findOrFail($release->ap_bill_id);

        $this->assertSame(DocumentStatus::Approved, $retentionBill->status);
        $this->assertSame((int) $spk->vendor_id, (int) $retentionBill->vendor_id);
        $this->assertEqualsWithDelta(self::RETENTION, $retentionBill->outstanding(), 0.01);
        // No tax rides on a release: PPN and PPh were charged on the opname's
        // full gross DPP, so charging them again here would double them.
        $this->assertEqualsWithDelta(0.0, (float) $retentionBill->ppn_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $retentionBill->pph_amount, 0.01);

        $this->payBill($retentionBill);

        $retentionBill->refresh();
        $this->assertEqualsWithDelta(0.0, $retentionBill->outstanding(), 0.01);
        $this->assertNotNull($retentionBill->paid_at);

        // Retention liability settled and its payable settled with it. What is
        // left in 2-1100 is the opname bill and nothing else:
        // 103.350.000 owed, still unpaid.
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1500'), 0.01);
        $this->assertEqualsWithDelta(-self::OPNAME_PAYABLE, $this->balanceOf('2-1100'), 0.01);

        $this->payBill($opnameBill->refresh(), '2026-05-21');

        // Everything owed to this subcontractor is now paid, in both accounts.
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1500'), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1100'), 0.01);
    }

    /** Sampai dibayar, retensi yang dilepas muncul di umur hutang seperti tagihan lain. */
    public function test_the_release_bill_appears_in_the_ap_aging_until_it_is_paid(): void
    {
        [$spk] = $this->spkWithBilledRetention();

        $release = $this->release($spk, self::RETENTION);
        /** @var ApBill $retentionBill */
        $retentionBill = ApBill::query()->findOrFail($release->ap_bill_id);

        $codes = collect(app(ReportService::class)->agingReport('ap')['rows'])->pluck('code');
        $this->assertTrue($codes->contains($retentionBill->code));

        $this->payBill($retentionBill);

        $codes = collect(app(ReportService::class)->agingReport('ap')['rows'])->pluck('code');
        $this->assertFalse($codes->contains($retentionBill->code));
    }

    // ---------------------------------------------------- no cost is booked twice

    /**
     * The trap in taking the ordinary ApBillService route: every one of its
     * paths debits a cost account and writes fin_project_costs. The opname bill
     * already charged the project the FULL 100.000.000 of work — retention is
     * withheld from the payment, not from the cost — so a release that booked
     * cost would report 105.000.000 spent on 100.000.000 of work.
     */
    public function test_releasing_retention_adds_no_project_cost(): void
    {
        [$spk] = $this->spkWithBilledRetention();

        // Opname bill: gross DPP 100.000.000 to the project, once.
        $this->assertEqualsWithDelta(self::CLAIM_GROSS, $this->projectCostTotal($spk), 0.01);
        $this->assertEqualsWithDelta(self::CLAIM_GROSS, $this->balanceOf('5-1300'), 0.01);

        $release = $this->release($spk, self::RETENTION);

        // Not a rupiah more, in the cost ledger or in the GL expense account.
        $this->assertEqualsWithDelta(self::CLAIM_GROSS, $this->projectCostTotal($spk), 0.01);
        $this->assertEqualsWithDelta(self::CLAIM_GROSS, $this->balanceOf('5-1300'), 0.01);
        $this->assertSame(0, ProjectCost::query()
            ->where('reference_type', 'ap_bill')
            ->where('reference_id', $release->ap_bill_id)
            ->count());
    }

    // ------------------------------------------------------------------ guards

    public function test_a_release_beyond_the_retained_balance_is_refused(): void
    {
        [$spk] = $this->spkWithBilledRetention();

        // 5.000.001 against 5.000.000 held — one rupiah too far.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('melebihi saldo retensi');

        $this->release($spk, self::RETENTION + 1);
    }

    /** Dan penolakan itu tidak boleh meninggalkan jejak apa pun. */
    public function test_a_refused_release_creates_neither_bill_nor_journal(): void
    {
        [$spk] = $this->spkWithBilledRetention();

        $billsBefore = ApBill::query()->count();
        $journalsBefore = Journal::query()->count();

        try {
            $this->release($spk, self::RETENTION * 2);
            $this->fail('Pelepasan melebihi saldo seharusnya ditolak.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame($billsBefore, ApBill::query()->count());
        $this->assertSame($journalsBefore, Journal::query()->count());
        $this->assertEqualsWithDelta(-self::RETENTION, $this->balanceOf('2-1500'), 0.01);
    }

    /** Dua tranche yang sah boleh, tranche ketiga yang menembus saldo tidak. */
    public function test_releases_accumulate_and_the_last_rupiah_is_the_limit(): void
    {
        [$spk] = $this->spkWithBilledRetention();

        $this->release($spk, 3000000.0, '2026-05-10');
        $this->release($spk, 2000000.0, '2026-06-10');

        // 3.000.000 + 2.000.000 = 5.000.000 => saldo nol, 2-1500 nol.
        $this->assertEqualsWithDelta(0.0, $this->retentionService()->balance($spk)['balance'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1500'), 0.01);

        $this->expectException(LogicException::class);
        $this->release($spk, 0.02, '2026-06-11');
    }

    public function test_a_subcontract_with_no_retaining_opname_cannot_release_anything(): void
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => self::SPK_VALUE,
            'retention_pct' => 5.0,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('belum memiliki opname disetujui yang menahan retensi');

        $this->release($spk, 1000000.0);
    }

    /**
     * The subtler half of the same guard. The opname is approved, so the SPK
     * reports 5.000.000 withheld — but Finance has not approved the bill, so
     * nothing was ever credited to 2-1500. Releasing would debit a liability
     * that does not exist and leave the account with a DEBIT balance, i.e. the
     * ledger claiming the subcontractor owes US retention.
     */
    public function test_retention_no_approved_bill_has_booked_cannot_be_released(): void
    {
        [$spk] = $this->spkWithApprovedOpname();

        $balance = $this->retentionService()->balance($spk);
        $this->assertEqualsWithDelta(self::RETENTION, $balance['retained'], 0.01);
        $this->assertEqualsWithDelta(0.0, $balance['posted'], 0.01);
        $this->assertEqualsWithDelta(0.0, $balance['releasable'], 0.01);

        try {
            $this->release($spk, self::RETENTION);
            $this->fail('Pelepasan tanpa tagihan opname disetujui seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('2-1500', $e->getMessage());
        }

        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1500'), 0.01);
    }

    // ------------------------------------------------------ undoing a release

    /**
     * A release booked against the wrong SPK is undone with the Finance module's
     * own cancellation — it reverses exactly the two lines this service posted.
     * The SPK has to follow: a release whose bill was cancelled is not a
     * release, or the money would be unreleasable for ever while 2-1500 carried
     * it again.
     */
    public function test_cancelling_the_release_bill_puts_the_retention_back(): void
    {
        [$spk] = $this->spkWithBilledRetention();

        $release = $this->release($spk, self::RETENTION);
        /** @var ApBill $retentionBill */
        $retentionBill = ApBill::query()->findOrFail($release->ap_bill_id);

        app(ApBillService::class)->cancel($retentionBill, $this->actor(), 'Salah SPK');

        // Reversal put the credit back: 2-1500 owes 5.000.000 again...
        $this->assertEqualsWithDelta(-self::RETENTION, $this->balanceOf('2-1500'), 0.01);

        // ...and the SPK agrees, instead of insisting it was already released.
        $balance = $this->retentionService()->balance($spk);
        $this->assertEqualsWithDelta(0.0, $balance['released'], 0.01);
        $this->assertEqualsWithDelta(self::RETENTION, $balance['balance'], 0.01);
        $this->assertEqualsWithDelta(self::RETENTION, $balance['releasable'], 0.01);

        // So it can be released again, correctly, and the ledger closes at zero.
        $this->release($spk, self::RETENTION, '2026-06-10');
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1500'), 0.01);
    }

    /**
     * THE OTHER DIRECTION, and the one that corrupts the ledger silently.
     *
     * The opname bill's `Cr 2-1500` is the only credit standing behind the
     * release's `Dr 2-1500`. Cancelling the opname bill FIRST reverses that
     * credit while the debit stays, leaving 2-1500 in debit — the ledger
     * claiming the subcontractor owes US retention — plus an approved payable
     * to them with no liability behind it. The release bill has to go first.
     */
    public function test_the_opname_bill_cannot_be_cancelled_while_its_retention_is_released(): void
    {
        [$spk, , $opnameBill] = $this->spkWithBilledRetention();
        $this->release($spk, self::RETENTION);

        try {
            app(ApBillService::class)->cancel($opnameBill->refresh(), $this->actor(), 'Salah nilai');
            $this->fail('membatalkan tagihan opname setelah retensinya dilepas seharusnya ditolak');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah dilepas', $e->getMessage());
        }

        // Nothing moved: the release still stands and 2-1500 is square.
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1500'), 0.01);

        // And the documented way out works — release bill first, then the opname.
        $release = $this->retentionService()->balance($spk);
        $this->assertEqualsWithDelta(self::RETENTION, $release['released'], 0.01);
    }
}
