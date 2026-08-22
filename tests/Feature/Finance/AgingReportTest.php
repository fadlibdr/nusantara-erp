<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Enums\PaymentDirection;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Umur piutang / hutang. Buckets are cut at 0 / 30 / 60 / 90 days overdue
 * against today:
 *
 *   current  = not yet due (0 days overdue)
 *   1_30     = 1..30 days late
 *   31_60    = 31..60
 *   61_90    = 61..90
 *   over_90  = 91+
 *
 * Only approved, not-fully-paid documents may appear, and the buckets must add
 * up to the reported total outstanding.
 *
 * "Not fully paid" is measured AS AT THE REPORT DATE, from allocations of
 * posted payments — never from the lifetime fin_ar_invoices.amount_paid. The
 * aging is read next to a dated control account (GL 1-1300 / 2-1100) and the
 * period-close checklist compares the two, so a receipt dated in the future
 * must not be able to retire a receivable that is still in the ledger.
 */
class AgingReportTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    private Vendor $vendor;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->customer = $this->makeCustomer();
        $this->contract = $this->makeContract($this->customer);
        $this->vendor = $this->makeVendor();
        $this->bank = $this->makeBankAccount('1-1210');
    }

    /**
     * A posted payment settling $amount of a document, built straight on the
     * models: the aging report reads documents and payment allocations only,
     * so no journal (and no open period) is involved.
     *
     * This is the shape PaymentService::settleInvoice really writes — the
     * allocation and amount_paid always move together, gross of withholding —
     * so setting amount_paid alone, as this suite used to, described a state
     * the application cannot produce.
     */
    private function settle(
        ArInvoice|ApBill $document,
        float $amount,
        string $paymentDate,
    ): Payment {
        $isInvoice = $document instanceof ArInvoice;

        $payment = Payment::create([
            'direction' => $isInvoice ? PaymentDirection::In : PaymentDirection::Out,
            'payment_date' => $paymentDate,
            'bank_account_id' => $this->bank->id,
            'amount' => $amount,
            'reference' => 'UJI/'.$paymentDate,
            'status' => PaymentStatus::Posted,
        ]);

        $payment->allocations()->create([
            'payable_type' => $isInvoice
                ? PaymentAllocation::TYPE_AR_INVOICE
                : PaymentAllocation::TYPE_AP_BILL,
            'payable_id' => $document->id,
            'amount' => $amount,
        ]);

        $document->forceFill(['amount_paid' => round((float) $document->amount_paid + $amount, 2)])->save();

        return $payment;
    }

    /**
     * An AR invoice built straight on the model: the aging report reads the
     * document table only, so no journal (and no open period) is involved.
     */
    private function invoiceDueDaysAgo(int $daysOverdue, float $total, array $attributes = []): ArInvoice
    {
        $due = Carbon::today()->subDays($daysOverdue);

        return ArInvoice::create(array_merge([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => $due->copy()->subDays(30)->toDateString(),
            'due_date' => $due->toDateString(),
            'description' => "Penagihan jatuh tempo {$due->toDateString()}",
            'dpp' => $total,
            'ppn_rate' => 0,
            'ppn_amount' => 0,
            'retention_withheld' => 0,
            'total' => $total,
            'amount_paid' => 0,
            'terbilang' => 'Terbilang uji',
            'status' => DocumentStatus::Approved,
        ], $attributes));
    }

    private function billDueDaysAgo(int $daysOverdue, float $payable, array $attributes = []): ApBill
    {
        $due = Carbon::today()->subDays($daysOverdue);

        return ApBill::create(array_merge([
            'vendor_id' => $this->vendor->id,
            'bill_date' => $due->copy()->subDays(30)->toDateString(),
            'due_date' => $due->toDateString(),
            'description' => "Tagihan jatuh tempo {$due->toDateString()}",
            'dpp' => $payable,
            'ppn_amount' => 0,
            'pph_amount' => 0,
            'total_payable' => $payable,
            'amount_paid' => 0,
            'vendor_invoice_no' => 'INV-VND-001',
            'status' => DocumentStatus::Approved,
        ], $attributes));
    }

    /**
     * @return array<string, string> bucket keyed by document code
     */
    private function bucketsByCode(array $report): array
    {
        $buckets = [];

        foreach ($report['rows'] as $row) {
            $buckets[$row['code']] = $row['bucket'];
        }

        return $buckets;
    }

    public function test_the_bucket_boundaries_sit_at_zero_thirty_sixty_and_ninety_days(): void
    {
        $notDue = $this->invoiceDueDaysAgo(-10, 5000000);  // jatuh tempo 10 hari lagi
        $dueToday = $this->invoiceDueDaysAgo(0, 10000000);
        $late1 = $this->invoiceDueDaysAgo(1, 20000000);
        $late30 = $this->invoiceDueDaysAgo(30, 1000000);
        $late31 = $this->invoiceDueDaysAgo(31, 30000000);
        $late60 = $this->invoiceDueDaysAgo(60, 2000000);
        $late61 = $this->invoiceDueDaysAgo(61, 40000000);
        $late90 = $this->invoiceDueDaysAgo(90, 3000000);
        $late91 = $this->invoiceDueDaysAgo(91, 50000000);

        $buckets = $this->bucketsByCode($this->reports()->agingReport('ar'));

        $this->assertSame('current', $buckets[$notDue->code]);
        $this->assertSame('current', $buckets[$dueToday->code]); // tepat jatuh tempo = belum telat
        $this->assertSame('1_30', $buckets[$late1->code]);
        $this->assertSame('1_30', $buckets[$late30->code]);
        $this->assertSame('31_60', $buckets[$late31->code]);
        $this->assertSame('31_60', $buckets[$late60->code]);
        $this->assertSame('61_90', $buckets[$late61->code]);
        $this->assertSame('61_90', $buckets[$late90->code]);
        $this->assertSame('over_90', $buckets[$late91->code]);
    }

    public function test_the_buckets_sum_to_the_total_outstanding(): void
    {
        $this->invoiceDueDaysAgo(-10, 5000000);
        $this->invoiceDueDaysAgo(0, 10000000);
        $this->invoiceDueDaysAgo(1, 20000000);
        $this->invoiceDueDaysAgo(30, 1000000);
        $this->invoiceDueDaysAgo(31, 30000000);
        $this->invoiceDueDaysAgo(60, 2000000);
        $this->invoiceDueDaysAgo(61, 40000000);
        $this->invoiceDueDaysAgo(90, 3000000);
        $this->invoiceDueDaysAgo(91, 50000000);

        $report = $this->reports()->agingReport('ar');

        // current 5.000.000 + 10.000.000 = 15.000.000
        $this->assertSame(15000000.0, $report['buckets']['current']);
        // 1_30: 20.000.000 + 1.000.000 = 21.000.000
        $this->assertSame(21000000.0, $report['buckets']['1_30']);
        // 31_60: 30.000.000 + 2.000.000 = 32.000.000
        $this->assertSame(32000000.0, $report['buckets']['31_60']);
        // 61_90: 40.000.000 + 3.000.000 = 43.000.000
        $this->assertSame(43000000.0, $report['buckets']['61_90']);
        // over_90: 50.000.000
        $this->assertSame(50000000.0, $report['buckets']['over_90']);
        // 15 + 21 + 32 + 43 + 50 = 161.000.000
        $this->assertSame(161000000.0, $report['total_outstanding']);
        $this->assertSame(array_sum($report['buckets']), $report['total_outstanding']);
        $this->assertCount(9, $report['rows']);
    }

    public function test_only_approved_documents_are_aged(): void
    {
        $approved = $this->invoiceDueDaysAgo(10, 100000000);
        $this->invoiceDueDaysAgo(10, 999000000, ['status' => DocumentStatus::Draft]);
        $this->invoiceDueDaysAgo(10, 888000000, ['status' => DocumentStatus::Submitted]);
        $this->invoiceDueDaysAgo(10, 777000000, ['status' => DocumentStatus::Cancelled]);

        $report = $this->reports()->agingReport('ar');

        $this->assertCount(1, $report['rows']);
        $this->assertSame($approved->code, $report['rows'][0]['code']);
        $this->assertSame(100000000.0, $report['total_outstanding']);
    }

    public function test_a_fully_paid_document_drops_out_and_a_partly_paid_one_shows_its_remainder(): void
    {
        $paid = $this->invoiceDueDaysAgo(40, 100000000);
        $partly = $this->invoiceDueDaysAgo(40, 100000000);

        $this->settle($paid, 100000000, Carbon::today()->subDays(5)->toDateString());
        $this->settle($partly, 25000000, Carbon::today()->subDays(5)->toDateString());

        $report = $this->reports()->agingReport('ar');

        $this->assertCount(1, $report['rows']);
        $this->assertSame($partly->code, $report['rows'][0]['code']);
        // 100.000.000 - 25.000.000 = 75.000.000
        $this->assertSame(75000000.0, $report['rows'][0]['outstanding']);
        $this->assertSame(25000000.0, $report['rows'][0]['amount_paid']);
        $this->assertSame(75000000.0, $report['buckets']['31_60']);
        $this->assertSame(40, $report['rows'][0]['days_overdue']);
    }

    /**
     * A post-dated giro: keyed and posted today, dated six weeks out. The
     * money is not in the bank and the receivable is not out of 1-1300, so it
     * must still be chased. Before this was date-bounded the invoice vanished
     * from the aging the same afternoon while the balance sheet of the
     * identical date still carried it — a Rp 300.000.000 disagreement between
     * two reports both stamped with today.
     */
    public function test_a_receipt_dated_after_the_report_date_does_not_retire_the_receivable(): void
    {
        $invoice = $this->invoiceDueDaysAgo(20, 300000000);
        $this->settle($invoice, 300000000, Carbon::today()->addDays(43)->toDateString());

        $report = $this->reports()->agingReport('ar');

        $this->assertCount(1, $report['rows']);
        $this->assertSame(300000000.0, $report['rows'][0]['outstanding']);
        $this->assertSame(0.0, $report['rows'][0]['amount_paid']);
        $this->assertSame(300000000.0, $report['total_outstanding']);
        // Lifetime amount_paid really did move — that is what made the old
        // basis wrong, not a missing write.
        $this->assertSame(300000000.0, (float) $invoice->fresh()->amount_paid);
    }

    public function test_a_receipt_dated_on_the_report_date_does_retire_the_receivable(): void
    {
        $invoice = $this->invoiceDueDaysAgo(20, 300000000);
        $this->settle($invoice, 300000000, Carbon::today()->toDateString());

        $report = $this->reports()->agingReport('ar');

        $this->assertSame([], $report['rows']);
        $this->assertSame(0.0, $report['total_outstanding']);
    }

    /**
     * The same receipt seen from the future: an aging asked for a date on or
     * after the giro clears reports the invoice settled. The report is a
     * function of its date, not of when it is run.
     */
    public function test_an_as_of_date_reports_the_aging_as_it_stood_on_that_day(): void
    {
        $invoice = $this->invoiceDueDaysAgo(20, 300000000);
        $clearing = Carbon::today()->addDays(43);
        $this->settle($invoice, 300000000, $clearing->toDateString());

        $before = $this->reports()->agingReport('ar', $clearing->copy()->subDay()->toDateString());
        $after = $this->reports()->agingReport('ar', $clearing->toDateString());

        $this->assertSame(300000000.0, $before['total_outstanding']);
        $this->assertSame($clearing->copy()->subDay()->toDateString(), $before['as_of']);
        $this->assertSame(0.0, $after['total_outstanding']);
        $this->assertSame($clearing->toDateString(), $after['as_of']);
    }

    /**
     * The document side is bounded too. ArInvoiceStoreRequest allows any
     * invoice_date, so without this an invoice dated next month would age
     * against a control account that does not carry it yet — the same tie
     * broken in the opposite direction.
     */
    public function test_a_document_dated_after_the_report_date_is_not_aged_yet(): void
    {
        $future = $this->invoiceDueDaysAgo(-60, 250000000, [
            'invoice_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);
        $today = $this->invoiceDueDaysAgo(5, 40000000);

        $report = $this->reports()->agingReport('ar');

        $this->assertCount(1, $report['rows']);
        $this->assertSame($today->code, $report['rows'][0]['code']);

        // The works-pair: once its own date arrives it ages normally.
        $later = $this->reports()->agingReport('ar', Carbon::today()->addDays(10)->toDateString());
        $codes = array_column($later['rows'], 'code');
        $this->assertContains($future->code, $codes);
    }

    public function test_each_row_carries_its_partner_dates_and_days_overdue(): void
    {
        $invoice = $this->invoiceDueDaysAgo(45, 100000000);

        $row = $this->reports()->agingReport('ar')['rows'][0];

        $this->assertSame($invoice->code, $row['code']);
        $this->assertSame('PT Graha Sentosa Propertindo', $row['partner']);
        $this->assertSame($invoice->due_date->toDateString(), $row['due_date']);
        $this->assertSame($invoice->invoice_date->toDateString(), $row['document_date']);
        $this->assertSame(100000000.0, $row['total']);
        $this->assertSame(0.0, $row['amount_paid']);
        $this->assertSame(45, $row['days_overdue']);
        $this->assertSame(Carbon::today()->toDateString(), $this->reports()->agingReport('ar')['as_of']);
    }

    public function test_the_payables_side_ages_vendor_bills(): void
    {
        $current = $this->billDueDaysAgo(-5, 12000000);
        $late = $this->billDueDaysAgo(95, 8000000);
        // Faktur pelanggan tidak boleh bocor ke sisi hutang.
        $this->invoiceDueDaysAgo(95, 999000000);

        $report = $this->reports()->agingReport('ap');
        $buckets = $this->bucketsByCode($report);

        $this->assertSame('ap', $report['side']);
        $this->assertCount(2, $report['rows']);
        $this->assertSame('current', $buckets[$current->code]);
        $this->assertSame('over_90', $buckets[$late->code]);
        $this->assertSame(12000000.0, $report['buckets']['current']);
        $this->assertSame(8000000.0, $report['buckets']['over_90']);
        // 12.000.000 + 8.000.000 = 20.000.000
        $this->assertSame(20000000.0, $report['total_outstanding']);
        $this->assertSame('PT Semen Distribusi Utama', $report['rows'][0]['partner']);
    }

    public function test_an_empty_ledger_reports_zero_in_every_bucket(): void
    {
        $report = $this->reports()->agingReport('ar');

        $this->assertSame([], $report['rows']);
        $this->assertSame(
            ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0],
            $report['buckets'],
        );
        $this->assertSame(0.0, $report['total_outstanding']);
    }

    public function test_an_unknown_side_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Aging side must be 'ar' or 'ap'.");

        $this->reports()->agingReport('gl');
    }

    // ------------------------------------------------------------ the endpoint

    /**
     * The date has to be reachable from outside, or it is not a capability.
     *
     * agingReport() has taken an as_of since the receipt-dating fix, but
     * arAging()/apAging() took no Request and never passed one:
     * GET /api/finance/reports/ar-aging?as_of=2026-06-30 answered 200 with
     * as_of = today. An accountant reconciling the June aging against the June
     * balance sheet — the pair the close checklist itself compares — got
     * today's figures and no error saying the date had been ignored.
     */
    public function test_the_aging_endpoint_answers_for_the_date_it_was_asked_for(): void
    {
        $invoice = $this->invoiceDueDaysAgo(20, 300000000);
        $clearing = Carbon::today()->addDays(43);
        $this->settle($invoice, 300000000, $clearing->toDateString());

        $before = $clearing->copy()->subDay()->toDateString();
        $accountant = $this->adminUser();

        $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/finance/reports/ar-aging?as_of='.$before)
            ->assertOk()
            ->assertJsonPath('data.as_of', $before)
            ->assertJsonPath('data.total_outstanding', 300000000);

        // The same report on the day the giro clears.
        $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/finance/reports/ar-aging?as_of='.$clearing->toDateString())
            ->assertOk()
            ->assertJsonPath('data.as_of', $clearing->toDateString())
            ->assertJsonPath('data.total_outstanding', 0);
    }

    /** The payables side takes the same date, and both still default to today. */
    public function test_the_aging_endpoints_still_default_to_today(): void
    {
        $this->invoiceDueDaysAgo(20, 300000000);
        $bill = $this->billDueDaysAgo(20, 12000000);

        $today = Carbon::today()->toDateString();
        $accountant = $this->adminUser();

        $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/finance/reports/ar-aging')
            ->assertOk()
            ->assertJsonPath('data.as_of', $today)
            ->assertJsonPath('data.total_outstanding', 300000000);

        $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/finance/reports/ap-aging')
            ->assertOk()
            ->assertJsonPath('data.as_of', $today)
            ->assertJsonPath('data.total_outstanding', 12000000);

        $earlier = $bill->bill_date->copy()->subDay()->toDateString();

        // A date before the bill was raised does not age it yet.
        $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/finance/reports/ap-aging?as_of='.$earlier)
            ->assertOk()
            ->assertJsonPath('data.as_of', $earlier)
            ->assertJsonPath('data.total_outstanding', 0);
    }

    /** A date the report cannot read is refused, not silently replaced by today. */
    public function test_the_aging_endpoint_refuses_a_date_it_cannot_read(): void
    {
        $this->invoiceDueDaysAgo(20, 300000000);
        $accountant = $this->adminUser();

        $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/finance/reports/ar-aging?as_of=akhir-juni')
            ->assertStatus(422)
            ->assertJsonValidationErrors('as_of');

        $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/finance/reports/ap-aging?as_of=kemarin')
            ->assertStatus(422)
            ->assertJsonValidationErrors('as_of');
    }
}
