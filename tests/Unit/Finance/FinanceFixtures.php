<?php

namespace Tests\Unit\Finance;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\Tax;
use Modules\Finance\Services\ApBillService;
use Modules\Finance\Services\ArInvoiceService;
use Modules\Finance\Services\JournalService;
use Modules\Finance\Services\PaymentService;
use Modules\Finance\Services\ProjectCostService;
use Modules\Finance\Services\ReportService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;

/**
 * Hand-built Finance fixtures shared by the unit (tests/Unit/Finance) and the
 * posting/report (tests/Feature/Finance) suites.
 *
 * Deliberately dumb: it only assembles rows and reshapes journals. It never
 * computes an expected number — every expectation is spelled out, with its
 * arithmetic, in the test that asserts it.
 */
trait FinanceFixtures
{
    // ---------------------------------------------------------------- services

    protected function journals(): JournalService
    {
        return app(JournalService::class);
    }

    protected function arInvoices(): ArInvoiceService
    {
        return app(ArInvoiceService::class);
    }

    protected function apBills(): ApBillService
    {
        return app(ApBillService::class);
    }

    protected function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    protected function reports(): ReportService
    {
        return app(ReportService::class);
    }

    protected function projectCosts(): ProjectCostService
    {
        return app(ProjectCostService::class);
    }

    // ---------------------------------------------------------------- actors

    /**
     * A plain user for approve()/post() stamps. Services never check
     * permissions, so no role seeding is needed here.
     *
     * This one is the MAKER: it raises and submits documents.
     */
    protected function financeUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'finance@test.local'],
            ['name' => 'Dewi Lestari', 'password' => 'password', 'is_active' => true],
        );
    }

    /**
     * The CHECKER, and the reason every fixture below submits as one person and
     * approves as another: maker-checker refuses an approval by the submitter,
     * so a fixture that used one user for both ends was not modelling the flow
     * it claimed to model — it was modelling the fraud path.
     */
    protected function financeApprover(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'finance-manager@test.local'],
            ['name' => 'Ratna Kusumawardani', 'password' => 'password', 'is_active' => true],
        );
    }

    // ---------------------------------------------------------------- masters

    protected function makeCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ], $attributes));
    }

    protected function makeVendor(array $attributes = []): Vendor
    {
        return Vendor::create(array_merge([
            'code' => 'VND-'.str_pad((string) (Vendor::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => 'PT Semen Distribusi Utama',
            'is_pkp' => true,
            'is_subcontractor' => false,
            'classification' => 'material',
            'payment_term_days' => 30,
            'status' => 'active',
        ], $attributes));
    }

    /**
     * An approved contract by default — billing a termin requires it.
     */
    protected function makeContract(Customer $customer, array $attributes = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $customer->id,
            'title' => 'Gedung Kantor Graha Sentosa',
            'scope_type' => 'construction',
            'value' => 10000000000,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'warranty_months' => 6,
            'status' => DocumentStatus::Approved,
        ], $attributes));
    }

    protected function makeTermin(
        Contract $contract,
        int $terminNo,
        string $name,
        float $percent,
        float $amount = 0,
        array $attributes = [],
    ): ContractTermin {
        return ContractTermin::create(array_merge([
            'contract_id' => $contract->id,
            'termin_no' => $terminNo,
            'name' => $name,
            'percent' => $percent,
            'amount' => $amount,
        ], $attributes));
    }

    protected function makeProject(array $attributes = []): Project
    {
        return Project::create(array_merge([
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'contract_value' => 10000000000,
            'retention_pct' => 5.0,
            'status' => 'active',
        ], $attributes));
    }

    protected function makeBankAccount(string $coaCode = '1-1210', array $attributes = []): BankAccount
    {
        return BankAccount::create(array_merge([
            'code' => 'BANK-'.str_pad((string) (BankAccount::query()->count() + 1), 2, '0', STR_PAD_LEFT),
            'name' => 'BCA Operasional',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'PT Nusantara Karya Integrasi',
            'coa_account_id' => $this->accountId($coaCode),
            'is_active' => true,
        ], $attributes));
    }

    /**
     * PPh final konstruksi tax row pointing at 2-1230 Hutang PPh Final 4(2).
     */
    protected function makePphFinalTax(string $scheme = 'pelaksanaan_bersertifikat', float $rate = 2.65): Tax
    {
        return Tax::create([
            'code' => Tax::pphFinalCodeForScheme($scheme),
            'name' => 'PPh Final Konstruksi — '.$scheme,
            'rate' => $rate,
            'tax_type' => 'pph_withholding',
            'coa_account_id' => $this->accountId('2-1230'),
        ]);
    }

    /**
     * PPh 23 jasa tax row pointing at 2-1220 Hutang PPh 23.
     */
    protected function makePph23Tax(float $rate = 2.0): Tax
    {
        return Tax::create([
            'code' => 'PPH23',
            'name' => 'PPh 23 Jasa',
            'rate' => $rate,
            'tax_type' => 'pph_withholding',
            'coa_account_id' => $this->accountId('2-1220'),
        ]);
    }

    // ---------------------------------------------------------------- source documents

    protected function makePurchaseOrder(Vendor $vendor, array $attributes = []): PurchaseOrder
    {
        return PurchaseOrder::create(array_merge([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => 100000000,
            'discount_amount' => 0,
            'dpp' => 100000000,
            'ppn_rate' => 11.0,
            'ppn_amount' => 11000000,
            'total' => 111000000,
            'status' => DocumentStatus::Approved,
        ], $attributes));
    }

    protected function makeSubcontract(Vendor $vendor, array $attributes = []): Subcontract
    {
        return Subcontract::create(array_merge([
            'vendor_id' => $vendor->id,
            'title' => 'Pekerjaan struktur lantai 1-4',
            'value' => 500000000,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_scheme' => 'pelaksanaan_bersertifikat',
            'pph_rate' => 2.65,
            'status' => DocumentStatus::Approved,
        ], $attributes));
    }

    protected function makeProgressClaim(Subcontract $subcontract, array $attributes = []): ProgressClaim
    {
        return ProgressClaim::create(array_merge([
            'subcontract_id' => $subcontract->id,
            'claim_no' => (int) (ProgressClaim::query()->where('subcontract_id', $subcontract->id)->count() + 1),
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'gross_amount' => 100000000,
            'retention_amount' => 5000000,
            'net_before_tax' => 95000000,
            'ppn_amount' => 11000000,
            'pph_amount' => 2650000,
            'net_payable' => 103350000,
            'status' => DocumentStatus::Approved,
        ], $attributes));
    }

    // ---------------------------------------------------------------- document lifecycle

    /**
     * draft -> submitted -> approved, booking the AR journal on the way.
     */
    protected function approveInvoice(ArInvoice $invoice): ArInvoice
    {
        $invoice->submit($this->financeUser());

        return $this->arInvoices()->approve($invoice, $this->financeApprover());
    }

    /**
     * draft -> submitted -> approved, booking the AP journal on the way.
     */
    protected function approveBill(ApBill $bill): ApBill
    {
        $bill->submit($this->financeUser());

        return $this->apBills()->approve($bill, $this->financeApprover());
    }

    /**
     * An outgoing payment carried through its full lifecycle:
     * draft -> submitted (with its allocations) -> approved -> posted.
     *
     * The allocations are passed once and used twice on purpose — post() now
     * refuses a body that differs from the set the approver agreed to, and a
     * fixture that let the two drift apart would be testing the guard by
     * accident instead of the posting.
     *
     * @param  array<string, mixed>  $data  everything but the direction
     * @param  array<int, array<string, mixed>>  $allocations
     */
    protected function approvedOutgoingPayment(array $data, array $allocations, ?int $postedBy = null): Payment
    {
        $payment = $this->payments()->create(array_merge(['direction' => 'out'], $data));

        return $this->payments()->post(
            $this->approveOutgoingPayment($payment, $allocations),
            $allocations,
            $postedBy,
        );
    }

    /**
     * draft -> submitted -> approved on an existing outgoing payment, for the
     * tests that need to watch post() itself fail afterwards.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     */
    protected function approveOutgoingPayment(Payment $payment, array $allocations): Payment
    {
        $this->payments()->submit($payment, $allocations, $this->financeUser());

        return $this->payments()->approve($payment->refresh(), $this->financeApprover());
    }

    // ---------------------------------------------------------------- ledger helpers

    protected function accountId(string $code): int
    {
        $id = Account::query()->where('code', $code)->value('id');

        if ($id === null) {
            throw new \RuntimeException("COA account {$code} is missing; call seedLedger() first.");
        }

        return (int) $id;
    }

    /**
     * Post a journal straight into the ledger from [code, debit, credit] tuples.
     * Used to give the report tests a known opening ledger.
     *
     * @param  array<int, array{0: string, 1: float, 2: float, 3?: ?int}>  $lines  [account_code, debit, credit, project_id?]
     */
    protected function postJournal(array $lines, string $date, string $description = 'Jurnal uji'): Journal
    {
        return $this->journals()->autoPost(
            'test',
            (int) (Journal::query()->count() + 1),
            array_map(fn (array $line): array => [
                'account_code' => $line[0],
                'debit' => $line[1],
                'credit' => $line[2],
                'project_id' => $line[3] ?? null,
            ], $lines),
            $date,
            $description,
        );
    }

    /**
     * A DRAFT journal built straight through the service (no posting), so the
     * post() guards can be exercised one at a time.
     *
     * @param  array<int, array{0: string, 1: float, 2: float}>  $lines  [account_code, debit, credit]
     */
    protected function draftJournal(array $lines, string $date = '2026-03-10'): Journal
    {
        return $this->journals()->create([
            'journal_date' => $date,
            'description' => 'Jurnal draf uji',
            'lines' => array_map(fn (array $line): array => [
                'account_id' => $this->accountId($line[0]),
                'debit' => $line[1],
                'credit' => $line[2],
            ], $lines),
        ]);
    }

    /**
     * Journal lines keyed by COA code.
     *
     * @return array<string, array{debit: float, credit: float, project_id: ?int}>
     */
    protected function linesByAccount(Journal $journal): array
    {
        $lines = [];

        foreach ($journal->lines()->with('account')->get() as $line) {
            $lines[$line->account->code] = [
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'project_id' => $line->project_id !== null ? (int) $line->project_id : null,
            ];
        }

        return $lines;
    }

    /**
     * The single journal raised for a source document.
     */
    protected function singleJournalFor(string $referenceType, int $referenceId): Journal
    {
        $journals = Journal::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->get();

        $this->assertCount(1, $journals, "Expected exactly one {$referenceType} journal.");

        return $journals->first();
    }

    protected function assertPostedAndBalanced(Journal $journal, string $expectedDate): void
    {
        $this->assertSame(PostingStatus::Posted, $journal->status);
        $this->assertNotNull($journal->posted_at);
        $this->assertSame($expectedDate, $journal->journal_date->toDateString());
        $this->assertSame($journal->totalDebit(), $journal->totalCredit());
        $this->assertGreaterThan(0.0, $journal->totalDebit());
    }
}
