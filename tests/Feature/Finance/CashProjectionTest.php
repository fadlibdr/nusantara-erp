<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Enums\PaymentDirection;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\Tax;
use Modules\Finance\Services\CashFlowService;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Proyeksi kas 90 hari. The pinned clock is 2026-08-15, so week 1 runs
 * 15–21 Agu and the horizon (90 days) ends 12 Nov.
 *
 * The load-bearing rules under test: the overdue ASYMMETRY (late AR is shown
 * but never enters the running balance, late AP is charged to week 1), the
 * double-count guards (a pending payment's allocations reduce the AP and tax
 * lanes they cover), the payroll basis (latest APPROVED regular run, THR
 * never), and the projection's own arithmetic — weekly nets must sum to
 * ending − opening.
 */
class CashProjectionTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;
    use PettyCashFixtures;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-15 09:00:00');
        $this->seedLedger(2026);
        $this->bank = $this->makeBankAccount('1-1210');
    }

    private function cashFlows(): CashFlowService
    {
        return app(CashFlowService::class);
    }

    private function invoiceDue(string $dueDate, float $total): ArInvoice
    {
        $customer = $this->makeCustomer(['name' => 'PT Graha '.str()->random(4)]);

        return ArInvoice::create([
            'customer_id' => $customer->id,
            'contract_id' => $this->makeContract($customer)->id,
            'invoice_date' => Carbon::parse($dueDate)->subDays(30)->toDateString(),
            'due_date' => $dueDate,
            'description' => "Termin jatuh tempo {$dueDate}",
            'dpp' => $total,
            'ppn_rate' => 0,
            'ppn_amount' => 0,
            'retention_withheld' => 0,
            'total' => $total,
            'amount_paid' => 0,
            'terbilang' => 'Terbilang uji',
            'status' => DocumentStatus::Approved,
        ]);
    }

    private function billDue(string $dueDate, float $payable): ApBill
    {
        return ApBill::create([
            'vendor_id' => $this->makeVendor(['name' => 'PT Vendor '.str()->random(4)])->id,
            'bill_date' => Carbon::parse($dueDate)->subDays(30)->toDateString(),
            'due_date' => $dueDate,
            'description' => "Tagihan jatuh tempo {$dueDate}",
            'dpp' => $payable,
            'ppn_amount' => 0,
            'pph_amount' => 0,
            'total_payable' => $payable,
            'amount_paid' => 0,
            'vendor_invoice_no' => 'INV-VND-'.str()->random(4),
            'status' => DocumentStatus::Approved,
        ]);
    }

    /** Bucket berminggu (tanpa baris overdue), keyed by its from date. */
    private function weeksByKey(array $projection): array
    {
        $weeks = [];

        foreach ($projection['buckets'] as $bucket) {
            if ($bucket['key'] !== 'overdue') {
                $weeks[$bucket['key']] = $bucket;
            }
        }

        return $weeks;
    }

    private function sumColumn(array $projection, string $column): float
    {
        $total = 0.0;

        foreach ($this->weeksByKey($projection) as $week) {
            $total = round($total + $week[$column], 2);
        }

        return $total;
    }

    /** A posted payment settling a document on $paymentDate, allocation and all. */
    private function settle(ArInvoice|ApBill $document, float $amount, string $paymentDate): Payment
    {
        $isInvoice = $document instanceof ArInvoice;

        $payment = Payment::create([
            'direction' => $isInvoice ? PaymentDirection::In : PaymentDirection::Out,
            'payment_date' => $paymentDate,
            'bank_account_id' => $this->bank->id,
            'amount' => $amount,
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
     * A post-dated giro erased Rp 100.000.000 from the projection outright:
     * absent from the opening balance because poolBalances only reads the pool
     * through today, and absent from the AR lane because the lifetime
     * amount_paid had already moved. Cash that has not arrived cannot retire a
     * receivable.
     */
    public function test_a_receipt_dated_ahead_of_today_does_not_erase_the_receivable_from_the_ar_lane(): void
    {
        $invoice = $this->invoiceDue('2026-08-20', 100000000);
        $this->settle($invoice, 100000000, '2026-09-15');

        $projection = $this->cashFlows()->projection(90);
        $weeks = $this->weeksByKey($projection);

        $this->assertSame(100000000.0, $weeks['2026-08-15']['inflow_ar']);
        $this->assertSame(100000000.0, $this->sumColumn($projection, 'inflow_ar'));
        // Lifetime amount_paid really did move — that is what made the old
        // basis wrong, not a missing write.
        $this->assertSame(100000000.0, (float) $invoice->fresh()->amount_paid);
    }

    /** The works-pair: money that has actually arrived is gone from the lane. */
    public function test_a_receipt_dated_today_does_retire_the_receivable(): void
    {
        $invoice = $this->invoiceDue('2026-08-20', 100000000);
        $this->settle($invoice, 100000000, '2026-08-15');

        $projection = $this->cashFlows()->projection(90);

        $this->assertSame(0.0, $this->sumColumn($projection, 'inflow_ar'));
    }

    /**
     * The AP side matters in the dangerous direction: a disbursement posted
     * today but dated next month left the payable behind on the amount_paid
     * basis while its cash was still in the opening pool, so the projection
     * showed money the company had already committed as money it could spend.
     */
    public function test_a_disbursement_dated_ahead_of_today_does_not_erase_the_payable_from_the_ap_lane(): void
    {
        $bill = $this->billDue('2026-08-25', 40000000);
        $this->settle($bill, 40000000, '2026-09-20');

        $projection = $this->cashFlows()->projection(90);
        $weeks = $this->weeksByKey($projection);

        $this->assertSame(40000000.0, $weeks['2026-08-22']['outflow_ap']);
        $this->assertSame(40000000.0, $this->sumColumn($projection, 'outflow_ap'));

        // The works-pair: dated today, the payable is settled and the lane is
        // empty.
        $this->settle($this->billDue('2026-08-25', 15000000), 15000000, '2026-08-14');
        $this->assertSame(40000000.0, $this->sumColumn($this->cashFlows()->projection(90), 'outflow_ap'));
    }

    public function test_overdue_ar_sits_in_its_own_bucket_and_never_enters_the_running_balance(): void
    {
        $this->invoiceDue('2026-07-20', 100000000); // 26 hari lewat tempo
        $this->invoiceDue('2026-08-20', 50000000);  // minggu pertama horizon

        $projection = $this->cashFlows()->projection(90);

        $this->assertSame(1, $projection['overdue']['ar']['count']);
        $this->assertSame(100000000.0, $projection['overdue']['ar']['total']);
        $this->assertSame(26, $projection['overdue']['ar']['oldest_days']);

        $overdueBucket = $projection['buckets'][0];
        $this->assertSame('overdue', $overdueBucket['key']);
        $this->assertSame(100000000.0, $overdueBucket['inflow_ar']);
        $this->assertFalse($overdueBucket['in_running_balance']);

        // Hanya piutang 50.000.000 yang masuk saldo berjalan; yang telat
        // TIDAK — kapan cairnya tidak diketahui.
        $weeks = $this->weeksByKey($projection);
        $this->assertSame(50000000.0, $weeks['2026-08-15']['inflow_ar']);
        $this->assertSame(50000000.0, $this->sumColumn($projection, 'inflow_ar'));
        $this->assertSame(
            round($projection['opening']['total'] + 50000000.0, 2),
            $projection['ending_balance'],
        );
    }

    public function test_overdue_ap_is_shown_and_still_charged_to_week_one(): void
    {
        $this->billDue('2026-07-25', 30000000);

        $projection = $this->cashFlows()->projection(90);

        $this->assertSame(1, $projection['overdue']['ap']['count']);
        $this->assertSame(30000000.0, $projection['overdue']['ap']['total']);
        $this->assertSame(30000000.0, $projection['buckets'][0]['outflow_ap']);

        // Asimetri yang disengaja: kewajiban tidak menguap — minggu pertama
        // menanggungnya dan saldo akhir turun.
        $weeks = $this->weeksByKey($projection);
        $this->assertSame(30000000.0, $weeks['2026-08-15']['outflow_ap']);
        $this->assertSame(
            round($projection['opening']['total'] - 30000000.0, 2),
            $projection['ending_balance'],
        );
    }

    public function test_the_latest_approved_regular_payroll_recurs_monthly_and_thr_is_never_the_basis(): void
    {
        $regular = PayrollRun::create([
            'period_year' => 2026, 'period_month' => 6,
            'run_type' => 'regular', 'payment_date' => '2026-06-25',
            'total_gross' => 196270346.83, 'total_deductions' => 29631365.40,
            'total_net' => 166638981.43, 'status' => DocumentStatus::Approved,
        ]);
        // THR lebih BARU dari run reguler — tetap bukan basis: memakainya
        // berarti memproyeksikan gaji ketiga belas setiap bulan.
        PayrollRun::create([
            'period_year' => 2026, 'period_month' => 7,
            'run_type' => 'thr', 'payment_date' => '2026-07-10',
            'total_gross' => 148500000, 'total_deductions' => 35994050,
            'total_net' => 112505950, 'status' => DocumentStatus::Approved,
        ]);

        $projection = $this->cashFlows()->projection(90);
        $weeks = $this->weeksByKey($projection);

        // Tanggal 25 berulang: 25 Agu, 25 Sep, 25 Okt (25 Nov di luar 90 hari).
        $this->assertSame(166638981.43, $weeks['2026-08-22']['outflow_payroll']);
        $this->assertSame(166638981.43, $weeks['2026-09-19']['outflow_payroll']);
        $this->assertSame(166638981.43, $weeks['2026-10-24']['outflow_payroll']);
        // 3 × 166.638.981,43 = 499.916.944,29 — dan bukan sepeser pun dari THR.
        $this->assertSame(499916944.29, $this->sumColumn($projection, 'outflow_payroll'));

        $named = false;

        foreach ($projection['assumptions'] as $assumption) {
            if (str_contains($assumption, $regular->code)) {
                $named = true;
                $this->assertStringContainsString('THR tidak dipakai sebagai basis', $assumption);
            }
        }

        $this->assertTrue($named, 'The assumptions must name the payroll run used as basis.');
    }

    public function test_tax_balances_hit_their_statutory_deadlines(): void
    {
        // Akrual PPh 21 Rp 20.000.000 masa Agustus, jam 15 Agu (lewat tanggal
        // 10) — tenggat tanggal 10 terdekat yang belum lewat: 10 September.
        $this->postJournal([
            ['6-1100', 20000000, 0],
            ['2-1210', 0, 20000000],
        ], '2026-08-10', 'Akrual PPh 21 Agustus');

        // PPN Keluaran 110.000.000 vs Masukan 24.000.000 — neto 86.000.000
        // disetor akhir bulan BERJALAN (31 Agu): saldo "sampai hari ini"
        // didominasi masa lalu, dan asimetri "kewajiban tidak menguap"
        // memilih tanggal yang lebih awal, bukan yang lebih lambat.
        $this->postJournal([
            ['1-1300', 110000000, 0],
            ['2-1300', 0, 110000000],
        ], '2026-08-05', 'PPN keluaran');
        $this->postJournal([
            ['1-1600', 24000000, 0],
            ['2-1100', 0, 24000000],
        ], '2026-08-06', 'PPN masukan');

        $projection = $this->cashFlows()->projection(90);
        $weeks = $this->weeksByKey($projection);

        // 10 Sep jatuh di minggu 5–11 Sep; 31 Agu di minggu 29 Agu–4 Sep.
        $this->assertSame(20000000.0, $weeks['2026-09-05']['outflow_tax']);
        $this->assertSame(86000000.0, $weeks['2026-08-29']['outflow_tax']);
        $this->assertSame(106000000.0, $this->sumColumn($projection, 'outflow_tax'));
    }

    /**
     * Temuan #1: run the projection early in the month and the balance is the
     * PRIOR masa's, whose deadlines are days away — the old addMonth()
     * deferred them a full month past their legal dates (demo per 2026-08-02:
     * net PPN Rp 1.043.955.000 masa Juli, due 31 Agu, was charged to the week
     * of 27 Sep, overstating four weekly running balances).
     */
    public function test_prior_masa_balances_hit_the_nearest_deadline_when_run_early_in_the_month(): void
    {
        Carbon::setTestNow('2026-08-02 08:00:00');

        $this->postJournal([
            ['6-1100', 20000000, 0],
            ['2-1210', 0, 20000000],
        ], '2026-07-31', 'Akrual PPh 21 Juli');
        $this->postJournal([
            ['1-1300', 110000000, 0],
            ['2-1300', 0, 110000000],
        ], '2026-07-15', 'PPN keluaran Juli');
        $this->postJournal([
            ['1-1600', 24000000, 0],
            ['2-1100', 0, 24000000],
        ], '2026-07-16', 'PPN masukan Juli');

        $projection = $this->cashFlows()->projection(90);
        $weeks = $this->weeksByKey($projection);

        // PPh Juli disetor 10 Agustus INI (minggu 9–15 Agu), bukan 10 Sep;
        // PPN neto Juli 86.000.000 disetor 31 Agustus (minggu 30 Agu–5 Sep).
        $this->assertSame(20000000.0, $weeks['2026-08-09']['outflow_tax']);
        $this->assertSame(86000000.0, $weeks['2026-08-30']['outflow_tax']);
        $this->assertSame(106000000.0, $this->sumColumn($projection, 'outflow_tax'));
    }

    public function test_input_vat_larger_than_output_vat_projects_no_ppn_payment(): void
    {
        $this->postJournal([
            ['1-1600', 50000000, 0],
            ['2-1100', 0, 50000000],
        ], '2026-08-06', 'PPN masukan besar');
        $this->postJournal([
            ['1-1300', 11000000, 0],
            ['2-1300', 0, 11000000],
        ], '2026-08-07', 'PPN keluaran kecil');

        $projection = $this->cashFlows()->projection(90);

        // max(0, 11.000.000 − 50.000.000) — lebih bayar tidak DISETOR.
        $this->assertSame(0.0, $this->sumColumn($projection, 'outflow_tax'));
    }

    public function test_a_pending_payments_allocations_reduce_the_bills_they_cover(): void
    {
        $bill = $this->billDue('2026-09-01', 100000000);

        $payment = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-08-18',
            'bank_account_id' => $this->bank->id,
            'amount' => 40000000,
        ]);
        $this->payments()->submit($payment, [
            ['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 40000000],
        ], $this->financeUser());

        $projection = $this->cashFlows()->projection(90);
        $weeks = $this->weeksByKey($projection);

        // Pembayaran 40.000.000 di minggu pertama (18 Agu)…
        $this->assertSame(40000000.0, $weeks['2026-08-15']['outflow_payments_approved']);
        // …dan tagihannya hanya memproyeksikan SISANYA pada jatuh tempo:
        // 100.000.000 − 40.000.000 = 60.000.000 (1 Sep, minggu 29 Agu–4 Sep).
        $this->assertSame(60000000.0, $weeks['2026-08-29']['outflow_ap']);
        // Total kedua lane = 100.000.000 — satu tagihan, satu kali.
        $this->assertSame(
            100000000.0,
            round($this->sumColumn($projection, 'outflow_ap')
                + $this->sumColumn($projection, 'outflow_payments_approved'), 2),
        );
    }

    /**
     * Temuan #2: the month whose salary payment is already submitted — the
     * exact flow the month-end ceiling exists to allow (accrual dated 31 Agu,
     * PAY submitted before the 25th payday) — was charged twice, once by the
     * recurrence and once by outflow_payments_approved: Rp 333.277.962,86
     * for one salary, enough to fire a false deficit warning near a genuine
     * low point.
     */
    public function test_a_submitted_payroll_payment_is_charged_once_and_the_recurrence_resumes_next_month(): void
    {
        PayrollRun::create([
            'period_year' => 2026, 'period_month' => 6,
            'run_type' => 'regular', 'payment_date' => '2026-06-25',
            'total_gross' => 196270346.83, 'total_deductions' => 29631365.40,
            'total_net' => 166638981.43, 'status' => DocumentStatus::Approved,
        ]);
        $this->postJournal([
            ['6-1100', 166638981.43, 0],
            ['2-1110', 0, 166638981.43],
        ], '2026-08-31', 'Akrual gaji Agustus');

        // Works-pair dulu: tanpa pembayaran, rekuren membebani 3 kejadian
        // penuh (25 Agu, 25 Sep, 25 Okt).
        $before = $this->cashFlows()->projection(90);
        $this->assertSame(499916944.29, $this->sumColumn($before, 'outflow_payroll'));

        $payment = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-08-25',
            'bank_account_id' => $this->bank->id,
            'amount' => 166638981.43,
        ]);
        $this->payments()->submit($payment, [
            ['payable_type' => 'gl_account', 'payable_id' => $this->accountId('2-1110'), 'amount' => 166638981.43],
        ], $this->financeUser());

        $after = $this->cashFlows()->projection(90);
        $weeks = $this->weeksByKey($after);

        // Satu gaji satu lane: minggu 22–28 Agu memikul PAY yang diajukan…
        $this->assertSame(166638981.43, $weeks['2026-08-22']['outflow_payments_approved']);
        // …dan kejadian gaji TERDEKAT (25 Agu, minggu yang sama) dinolkan.
        $this->assertSame(0.0, $weeks['2026-08-22']['outflow_payroll']);
        // Bulan-bulan berikutnya kembali ke estimasi penuh.
        $this->assertSame(166638981.43, $weeks['2026-09-19']['outflow_payroll']);
        $this->assertSame(166638981.43, $weeks['2026-10-24']['outflow_payroll']);
        // 2 × 166.638.981,43 — bukan 3: gaji Agustus sudah di lane pembayaran.
        $this->assertSame(333277962.86, $this->sumColumn($after, 'outflow_payroll'));
    }

    public function test_a_pending_gl_account_payment_empties_the_tax_lane_it_covers(): void
    {
        $this->postJournal([
            ['6-1100', 50000000, 0],
            ['2-1210', 0, 50000000],
        ], '2026-08-10', 'Akrual PPh 21');

        // Works-pair dulu: tanpa pembayaran, lane pajak menanggung 50 juta.
        $before = $this->cashFlows()->projection(90);
        $this->assertSame(50000000.0, $this->sumColumn($before, 'outflow_tax'));

        $payment = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-08-20',
            'bank_account_id' => $this->bank->id,
            'amount' => 50000000,
        ]);
        $this->payments()->submit($payment, [
            ['payable_type' => 'gl_account', 'payable_id' => $this->accountId('2-1210'), 'amount' => 50000000],
        ], $this->financeUser());

        $after = $this->cashFlows()->projection(90);

        // Satu kewajiban satu lane: SSP-nya pindah ke lane pembayaran.
        $this->assertSame(0.0, $this->sumColumn($after, 'outflow_tax'));
        $this->assertSame(50000000.0, $this->sumColumn($after, 'outflow_payments_approved'));
    }

    public function test_billing_ready_termins_arrive_after_the_lag_grossed_with_ppn_and_billed_ones_do_not(): void
    {
        Tax::create([
            'code' => 'PPN', 'name' => 'PPN', 'rate' => 11,
            'tax_type' => 'ppn', 'coa_account_id' => $this->accountId('2-1300'),
        ]);

        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer);
        // Jatuh tempo 10 Agu, belum ditagih => siap tagih hari ini, cair
        // 15 Agu + 30 hari = 14 Sep (minggu 12–18 Sep). 100 jt × 1,11 = 111 jt.
        $this->makeTermin($contract, 1, 'Termin jatuh tempo', 20, 100000000, ['due_date' => '2026-08-10']);
        // Terjadwal 1 Sep => cair 1 Okt (minggu 26 Sep–2 Okt). 200 jt × 1,11.
        $this->makeTermin($contract, 2, 'Termin terjadwal', 40, 200000000, ['due_date' => '2026-09-01']);
        // Sudah DITAGIH — piutangnya hidup sebagai AR, bukan di lane termin.
        $this->makeTermin($contract, 3, 'Termin sudah ditagih', 40, 400000000, [
            'due_date' => '2026-08-01', 'billed_at' => '2026-08-01',
        ]);

        $projection = $this->cashFlows()->projection(90);
        $weeks = $this->weeksByKey($projection);

        $this->assertSame(111000000.0, $weeks['2026-09-12']['inflow_termin']);
        $this->assertSame(222000000.0, $weeks['2026-09-26']['inflow_termin']);
        // 111 jt + 222 jt — termin yang sudah ditagih TIDAK ikut.
        $this->assertSame(333000000.0, $this->sumColumn($projection, 'inflow_termin'));
    }

    public function test_a_petty_cash_replenishment_payment_is_information_not_outflow(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-08-01');
        $this->vouchers()->post(
            $this->makeVoucher($fund, ['voucher_date' => '2026-08-10', 'amount' => 2000000]),
            $this->custodianUser(),
        );

        // Isi ulang laci: PAY 2.000.000 bank → laci, sudah diajukan.
        $payment = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-08-18',
            'bank_account_id' => $this->bank->id,
            'amount' => 2000000,
            'petty_cash_fund_id' => $fund->id,
        ]);
        $this->payments()->submit($payment, [
            ['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => 2000000],
        ], $this->financeUser());

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), [
            'advance_date' => '2026-08-12', 'amount' => 1000000,
        ]);
        $this->kasbons()->issue($kasbon, $this->custodianUser());

        $projection = $this->cashFlows()->projection(90);

        // Laci ada DI DALAM pool kas: isi ulang memindahkan uang di dalam
        // pool, bukan mengeluarkannya — nol di lane pembayaran.
        $this->assertSame(0.0, $this->sumColumn($projection, 'outflow_payments_approved'));

        // …tapi kebutuhannya terlihat. Laci: float 5 jt − saldo 2 jt − kasbon
        // beredar 1 jt = 2.000.000 yang perlu diganti — HANYA bonnya. Kasbon
        // 1.000.000 adalah uang laci yang keluar sebagai UANG MUKA, masih
        // milik laci di 1-1370 atas nama karyawan dan belum dibuktikan bon
        // apa pun; mengganti uang muka berarti bank mentransfer 1.000.000
        // lebih besar daripada bukti yang dibaca penyetuju. Kebutuhannya tetap
        // terlihat di baris kasbon berjalan di sebelahnya.
        $this->assertSame(2000000.0, $projection['kas_kecil']['replenishment_due_total']);
        $this->assertSame(1000000.0, $projection['kas_kecil']['outstanding_kasbon_total']);
        $this->assertSame($fund->code, $projection['kas_kecil']['funds'][0]['code']);

        $mentioned = array_filter(
            $projection['assumptions'],
            fn (string $sentence): bool => str_contains($sentence, 'Isi ulang kas kecil'),
        );
        $this->assertNotEmpty($mentioned);
    }

    /**
     * The projection's own arithmetic: weekly nets must sum to
     * ending − opening, and each week's running balance must roll forward.
     */
    public function test_the_weekly_buckets_sum_to_the_projections_own_totals(): void
    {
        $this->invoiceDue('2026-07-20', 100000000); // telat — di luar saldo
        $this->invoiceDue('2026-08-20', 50000000);
        $this->billDue('2026-07-25', 30000000);     // telat — minggu pertama
        $this->billDue('2026-09-01', 45000000);
        PayrollRun::create([
            'period_year' => 2026, 'period_month' => 6,
            'run_type' => 'regular', 'payment_date' => '2026-06-25',
            'total_gross' => 196270346.83, 'total_deductions' => 29631365.40,
            'total_net' => 166638981.43, 'status' => DocumentStatus::Approved,
        ]);
        $this->postJournal([
            ['6-1100', 20000000, 0],
            ['2-1210', 0, 20000000],
        ], '2026-08-10', 'Akrual PPh 21');

        $projection = $this->cashFlows()->projection(90);

        $running = $projection['opening']['total'];
        $netTotal = 0.0;

        foreach ($this->weeksByKey($projection) as $week) {
            $expectedNet = round(
                $week['inflow_ar'] + $week['inflow_termin']
                - $week['outflow_ap'] - $week['outflow_payroll']
                - $week['outflow_tax'] - $week['outflow_payments_approved'],
                2,
            );
            $this->assertSame($expectedNet, $week['net'], "Week {$week['key']} net does not add up.");

            $running = round($running + $week['net'], 2);
            $this->assertSame($running, $week['running_balance'], "Week {$week['key']} does not roll forward.");

            $netTotal = round($netTotal + $week['net'], 2);
        }

        $this->assertSame($running, $projection['ending_balance']);
        $this->assertSame(
            $projection['ending_balance'],
            round($projection['opening']['total'] + $netTotal, 2),
        );
        $this->assertNotNull($projection['lowest']);
    }

    public function test_the_horizon_is_clamped_to_between_seven_and_one_hundred_eighty_days(): void
    {
        $this->assertSame(7, $this->cashFlows()->projection(3)['days']);
        $this->assertSame(180, $this->cashFlows()->projection(365)['days']);
        $this->assertSame(90, $this->cashFlows()->projection(90)['days']);
    }

    // ------------------------------------------------------------- endpoint

    public function test_the_endpoint_requires_fin_view_and_validates_days(): void
    {
        $this->actingAs($this->userWith([]), 'sanctum')
            ->getJson('/api/finance/reports/cash-projection')
            ->assertForbidden();

        $viewer = $this->userWith(['fin.view']);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/finance/reports/cash-projection?days=5')
            ->assertStatus(422);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/finance/reports/cash-projection')
            ->assertOk();

        $this->assertSame(90, $response->json('data.days'));
        $this->assertSame('2026-08-15', $response->json('data.as_of'));
        $this->assertIsArray($response->json('data.assumptions'));
        $this->assertNotEmpty($response->json('data.assumptions'));
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pengguna Uji',
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
