<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\Maintenance;
use Modules\Core\Models\Notification;
use Modules\Core\Services\NotificationService;
use Modules\Core\Support\WatchedDeadlines;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Guarantee;
use Modules\Crm\Models\Quotation;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Payment;
use Modules\Finance\Services\ReportService;
use Modules\HrPayroll\Models\Certificate;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\SafetyIncident;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * erp:deadline-watch — every date that can slide past in silence, one loop.
 *
 * Beyond the works/refused pair for each scope, this file pins three things
 * the watcher's correctness quietly depends on:
 *
 *  - the STATUS STRINGS of other modules' tables ('approved', 'active',
 *    'kontrak', 'open') — Core queries them via DB::table with literals, so a
 *    rename in another team's lane must fail here, not empty a scope silently;
 *  - the DATE STORAGE footgun: model date casts store "2026-08-15 00:00:00",
 *    which sorts AFTER "2026-08-15" — the boundary test proves the last lead
 *    day and today itself land in the right tiers anyway;
 *  - the REPEAT design: unread suppresses, read + renag window suppresses,
 *    window expiry re-fires, and a tier change fires immediately because it
 *    is a different stable title.
 */
class DeadlineWatchTest extends ErpTestCase
{
    private const TODAY = '2026-08-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
    }

    // -------------------------------------------------------------- fixtures

    private function watch(): void
    {
        $this->artisan('erp:deadline-watch')->assertExitCode(0);
    }

    /** @return Collection<int, Notification> */
    private function alarms(?string $title = null): Collection
    {
        return Notification::query()
            ->where('event', Notification::SYSTEM)
            ->when($title !== null, fn ($query) => $query->where('title', $title))
            ->get();
    }

    private function userWith(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pemegang '.implode(' ', $permissions),
            'email' => substr(md5(implode('|', $permissions)), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);
    }

    private function quotation(array $overrides = []): Quotation
    {
        return Quotation::query()->create(array_merge([
            'customer_id' => $this->customer()->id,
            'title' => 'Penawaran Upgrade CCTV Gudang',
            'scope_type' => 'system_integration',
            'total' => 33_970_000,
            'status' => 'approved',
        ], $overrides));
    }

    private function approvedContract(array $overrides = []): Contract
    {
        return Contract::query()->create(array_merge([
            'customer_id' => $this->customer()->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
            'scope_type' => 'construction',
            'value' => 48_500_000_000,
            'status' => 'approved',
        ], $overrides));
    }

    private function purchaseOrder(array $overrides = []): PurchaseOrder
    {
        $vendor = Vendor::query()->firstOr(fn () => Vendor::query()->create([
            'name' => 'PT Sumber Makmur Elektrindo',
            'is_subcontractor' => false,
            'classification' => 'material',
            'status' => 'active',
        ]));

        return PurchaseOrder::query()->create(array_merge([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-02-10',
            'expected_date' => '2026-03-01',
            'total' => 232_500_000,
            'status' => 'approved',
        ], $overrides));
    }

    private function vendor(): Vendor
    {
        return Vendor::query()->firstOr(fn () => Vendor::query()->create([
            'name' => 'PT Sumber Makmur Elektrindo',
            'is_subcontractor' => false,
            'classification' => 'material',
            'status' => 'active',
        ]));
    }

    /** BIL/2026/VII/0002's live shape: approved, Rp 48,5 jt, due 27 Jun 2026, nothing paid. */
    private function apBill(array $overrides = []): ApBill
    {
        return ApBill::query()->create(array_merge([
            'vendor_id' => $this->vendor()->id,
            'bill_date' => '2026-05-28',
            'due_date' => '2026-06-27',
            'description' => 'Tagihan kamera & NVR paket 2',
            'vendor_invoice_no' => 'INV-VND-0002',
            'dpp' => 48_500_000,
            'total_payable' => 48_500_000,
            'amount_paid' => 0,
            'status' => 'approved',
        ], $overrides));
    }

    /**
     * A payment settling $amount of the bill in the shape
     * PaymentService::settleBill really writes — allocation and lifetime
     * amount_paid together, the AgingReportTest fixture — so the watcher is
     * tested against a state the application can produce. Only a POSTED
     * payment moves amount_paid, as in the service.
     */
    private function settleBill(ApBill $bill, float $amount, string $paymentDate, string $status = 'posted'): Payment
    {
        $bank = BankAccount::query()->firstOr(function (): BankAccount {
            $account = Account::query()->create([
                'code' => '1-1210',
                'name' => 'Bank BCA Operasional',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
            ]);

            return BankAccount::query()->create([
                'code' => 'BANK-BCA-OPS',
                'name' => 'BCA Operasional',
                'bank_name' => 'Bank Central Asia',
                'account_no' => '1234567890',
                'account_name' => 'PT Nusantara Karya Integrasi',
                'coa_account_id' => $account->id,
                'is_active' => true,
            ]);
        });

        $payment = Payment::query()->create([
            'direction' => 'out',
            'payment_date' => $paymentDate,
            'bank_account_id' => $bank->id,
            'amount' => $amount,
            'reference' => 'TRF '.$paymentDate,
            'status' => $status,
        ]);
        $payment->allocations()->create([
            'payable_type' => 'ap_bill', // PaymentAllocation::TYPE_AP_BILL
            'payable_id' => $bill->id,
            'amount' => $amount,
        ]);

        if ($status === 'posted') {
            $bill->forceFill(['amount_paid' => round((float) $bill->amount_paid + $amount, 2)])->save();
        }

        return $payment;
    }

    private function employee(array $overrides = []): Employee
    {
        static $n = 0;
        $n++;

        return Employee::query()->create(array_merge([
            'code' => 'EMP-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Joko Susilo',
            'nik_ktp' => str_pad((string) $n, 16, '3', STR_PAD_LEFT),
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'ptkp_status' => 'TK/0',
            'join_date' => '2025-01-01',
            'employment_type' => 'kontrak',
            'position' => 'Pelaksana',
            'department' => 'proyek',
            'base_salary' => 0,
            'status' => 'active',
        ], $overrides));
    }

    private function certificate(Employee $employee, array $overrides = []): Certificate
    {
        return Certificate::query()->create(array_merge([
            'employee_id' => $employee->id,
            'certificate_type' => 'skk',
            'name' => 'SKK Ahli Madya Teknik Bangunan Gedung',
            'issuer' => 'LPJK',
            'issued_date' => '2023-09-01',
            'expiry_date' => '2026-09-01',
        ], $overrides));
    }

    private function guarantee(array $overrides = []): Guarantee
    {
        return Guarantee::query()->create(array_merge([
            'guarantee_type' => 'advance_payment_bond',
            'number' => 'BG-2026-0917-001',
            'issuer' => 'Bank Mandiri',
            'contract_id' => $this->approvedContract()->id,
            'value' => 9_700_000_000,
            'start_date' => '2026-02-01',
            'end_date' => '2026-08-20',
            'status' => 'active',
        ], $overrides));
    }

    private function asset(): Asset
    {
        $category = AssetCategory::query()->create([
            'code' => 'CAT-'.str()->random(4),
            'name' => 'Alat Berat',
        ]);

        return Asset::query()->create([
            'code' => 'AST-'.str()->random(5),
            'name' => 'Excavator Uji',
            'category_id' => $category->id,
            'acquisition_date' => '2025-01-01',
            'acquisition_cost' => 96_000_000,
            'useful_life_months' => 96,
            'book_value' => 96_000_000,
            'status' => 'available',
        ]);
    }

    private function project(array $overrides = []): Project
    {
        return Project::query()->create(array_merge([
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
        ], $overrides));
    }

    // ------------------------------------------- lead window: in, out, past

    public function test_a_quotation_nearing_its_valid_until_notifies_sales(): void
    {
        $this->adminUser();
        $quotation = $this->quotation(['valid_until' => '2026-08-10']); // 9 of 14 lead days

        $this->watch();

        $alarm = $this->alarms('Penawaran mendekati akhir masa berlaku')->sole();
        $this->assertStringContainsString($quotation->code, $alarm->body);
        $this->assertStringContainsString('berlaku s/d 10 Agu 2026', $alarm->body);
        $this->assertStringContainsString('9 hari lagi', $alarm->body);
        $this->assertSame('r/crm/quotations', $alarm->link);
    }

    public function test_a_quotation_outside_the_lead_window_stays_silent(): void
    {
        $this->adminUser();
        $this->quotation(['valid_until' => '2026-08-21']); // 20 days out, lead is 14

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    public function test_an_open_quotation_past_its_valid_until_alarms_as_overdue(): void
    {
        $this->adminUser();
        $quotation = $this->quotation(['valid_until' => '2026-07-20']);

        $this->watch();

        $alarm = $this->alarms('Penawaran lewat masa berlaku')->sole();
        $this->assertStringContainsString($quotation->code, $alarm->body);
        $this->assertStringContainsString('12 hari lalu', $alarm->body);
    }

    public function test_a_won_quotation_past_its_valid_until_stays_silent(): void
    {
        $this->adminUser();
        $this->quotation(['valid_until' => '2026-07-20', 'won_at' => '2026-07-01 10:00:00']);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /**
     * The storage footgun, pinned: a date cast writes "2026-08-15 00:00:00",
     * which sorts AFTER the bare string "2026-08-15". Naive BETWEEN/equality
     * comparisons drop the last day of the window and count today as
     * "approaching" — exactly the rows that matter most.
     */
    public function test_the_last_lead_day_is_included_and_today_counts_as_overdue(): void
    {
        $this->adminUser();
        $lastDay = $this->quotation(['valid_until' => '2026-08-15']); // today + 14, the boundary
        $today = $this->quotation(['valid_until' => self::TODAY]);

        $this->watch();

        $menipis = $this->alarms('Penawaran mendekati akhir masa berlaku')->sole();
        $this->assertStringContainsString($lastDay->code, $menipis->body);
        $this->assertStringNotContainsString($today->code, $menipis->body);

        $lewat = $this->alarms('Penawaran lewat masa berlaku')->sole();
        $this->assertStringContainsString($today->code, $lewat->body);
        $this->assertStringContainsString('hari ini', $lewat->body);
    }

    // --------------------------------------------- purchase orders (lead 0)

    public function test_purchase_orders_past_their_expected_date_alarm_procurement_in_one_notification(): void
    {
        $this->adminUser();
        $this->purchaseOrder(); // Rp 232,5 jt promised 2026-03-01: 153 days late
        $this->purchaseOrder(['expected_date' => '2026-03-23', 'total' => 128_300_000]);

        $this->watch();

        $alarm = $this->alarms('Pesanan pembelian lewat tanggal terima')->sole();
        $this->assertStringContainsString('senilai Rp 232,5 jt', $alarm->body);
        $this->assertStringContainsString('dijanjikan 1 Mar 2026', $alarm->body);
        $this->assertStringContainsString('153 hari lalu', $alarm->body);
        $this->assertStringContainsString('131 hari lalu', $alarm->body);
        $this->assertStringContainsString('Total 2 PO.', $alarm->body);
    }

    public function test_a_closed_purchase_order_stays_silent(): void
    {
        $this->adminUser();
        $this->purchaseOrder(['closed_at' => '2026-04-01 08:00:00']);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /** lead 0 by design: a future expected date on a PO is normal, not news. */
    public function test_a_purchase_order_expected_in_the_future_stays_silent(): void
    {
        $this->adminUser();
        $this->purchaseOrder(['expected_date' => '2026-08-05']);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    // ------------------------------------------------ purchase requisitions

    /**
     * PR/2026/III/0002 sat in 'submitted' with needed_date 2026-04-01 — 122
     * days past — and the approved-only scope kept it invisible: the site
     * needed the material a quarter ago and every screen stayed green. A
     * submitted PR past its needed date is just as unpurchased, so it alarms.
     */
    public function test_a_submitted_pr_past_its_needed_date_alarms_procurement(): void
    {
        $this->adminUser();
        $requisition = PurchaseRequisition::query()->create([
            'needed_date' => '2026-04-01',
            'status' => 'submitted',
        ]);

        $this->watch();

        $alarm = $this->alarms('Permintaan pembelian lewat tanggal dibutuhkan')->sole();
        $this->assertStringContainsString($requisition->code, $alarm->body);
        $this->assertStringContainsString('122 hari lalu', $alarm->body);
    }

    /** Draft is still the requester's desk — not yet procurement's silence to break. */
    public function test_a_draft_pr_past_its_needed_date_stays_silent(): void
    {
        $this->adminUser();
        PurchaseRequisition::query()->create([
            'needed_date' => '2026-04-01',
            'status' => 'draft',
        ]);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /** The NOT-EXISTS PO cover works for the widened scope too (PR/2026/II/0001's case). */
    public function test_a_submitted_pr_already_covered_by_a_po_stays_silent(): void
    {
        $this->adminUser();
        $requisition = PurchaseRequisition::query()->create([
            'needed_date' => '2026-04-01',
            'status' => 'submitted',
        ]);
        $this->purchaseOrder([
            'purchase_requisition_id' => $requisition->id,
            'expected_date' => '2026-09-01',
        ]);

        $this->watch();

        $this->assertCount(0, $this->alarms('Permintaan pembelian lewat tanggal dibutuhkan'));
    }

    // ------------------------------------------ tagihan vendor (AP, lead 7)

    /**
     * BIL/2026/VII/0002 (Rp 48,5 jt) on production: approved, due 27 Jun
     * 2026, nothing paid, 69 days past due on 4 Sep 2026 and no screen said
     * so (ANALISIS-PROSES-BISNIS-2026-09 §2, celah B1). An approved bill has
     * no owner for the "buat pembayaran" step — this alarm is that owner, and
     * it reaches fin.create (who prepares the PAY), not the approver.
     */
    public function test_an_approved_unpaid_ap_bill_past_its_due_date_alarms_finance(): void
    {
        $finance = $this->userWith('fin.create');
        $this->userWith('prc.update');
        $bill = $this->apBill(); // due 27 Jun 2026: 35 days past on TODAY

        $this->watch();

        $alarm = $this->alarms('Tagihan vendor lewat jatuh tempo')->sole();
        $this->assertStringContainsString($bill->code, $alarm->body);
        $this->assertStringContainsString('senilai Rp 48,5 jt', $alarm->body);
        $this->assertStringContainsString('jatuh tempo 27 Jun 2026', $alarm->body);
        $this->assertStringContainsString('35 hari lalu', $alarm->body);
        $this->assertSame('r/finance/ap-bills', $alarm->link);
        $this->assertSame([$finance->id], $this->alarms('Tagihan vendor lewat jatuh tempo')->pluck('user_id')->all());
    }

    public function test_an_ap_bill_inside_its_seven_day_lead_window_alarms_as_menipis(): void
    {
        $this->adminUser();
        $bill = $this->apBill(['due_date' => '2026-08-05']); // 4 of 7 lead days
        $this->apBill(['due_date' => '2026-08-09']); // day 8: outside the window

        $this->watch();

        $alarm = $this->alarms('Tagihan vendor mendekati jatuh tempo')->sole();
        $this->assertStringContainsString($bill->code, $alarm->body);
        $this->assertStringContainsString('4 hari lagi', $alarm->body);
        $this->assertStringNotContainsString('Total', $alarm->body);
        $this->assertCount(0, $this->alarms('Tagihan vendor lewat jatuh tempo'));
    }

    /**
     * "Not fully paid" is the AP aging's definition, not the model's:
     * total_payable minus allocations of POSTED payments dated on or before
     * today (OutstandingAsOf::settled). fin_ap_bills.amount_paid is a lifetime
     * figure a post-dated giro moves weeks before the money leaves — the aging
     * refused it for that reason (ReportService::agingReport), and a watcher
     * that read it would fall silent on a bill the same morning's aging still
     * lists. Pinned by running both against the same four bills.
     */
    public function test_only_posted_payments_dated_by_today_settle_a_bill_the_way_the_aging_does(): void
    {
        $this->adminUser();
        $paid = $this->apBill(['vendor_invoice_no' => 'INV-PAID']);
        $this->settleBill($paid, 48_500_000, '2026-07-01');
        $partly = $this->apBill(['vendor_invoice_no' => 'INV-PARTLY']);
        $this->settleBill($partly, 20_000_000, '2026-07-01');
        $postDated = $this->apBill(['vendor_invoice_no' => 'INV-GIRO']);
        $this->settleBill($postDated, 48_500_000, '2026-08-15'); // giro dated two weeks ahead, already posted
        $unposted = $this->apBill(['vendor_invoice_no' => 'INV-DRAFT-PAY']);
        $this->settleBill($unposted, 48_500_000, '2026-07-01', 'draft');

        $this->watch();

        $alarm = $this->alarms('Tagihan vendor lewat jatuh tempo')->sole();
        $this->assertStringNotContainsString($paid->code, $alarm->body);
        $this->assertStringContainsString($partly->code, $alarm->body);
        $this->assertStringContainsString($postDated->code, $alarm->body);
        $this->assertStringContainsString($unposted->code, $alarm->body);
        $this->assertStringContainsString('Total 3 tagihan.', $alarm->body);

        $aging = array_column(app(ReportService::class)->agingReport('ap')['rows'], 'code');
        $expected = [$partly->code, $postDated->code, $unposted->code];
        sort($aging);
        sort($expected);
        $this->assertSame($expected, $aging);
    }

    public function test_a_draft_submitted_cancelled_or_deleted_ap_bill_stays_silent(): void
    {
        $this->adminUser();
        $this->apBill(['status' => 'draft']);
        $this->apBill(['status' => 'submitted']);
        $this->apBill([
            'status' => 'cancelled',
            'cancelled_at' => '2026-07-10 09:00:00',
            'cancellation_reason' => 'Salah tagih, diganti tagihan baru.',
        ]);
        $this->apBill()->delete();

        $this->watch();

        $this->assertCount(0, $this->alarms('Tagihan vendor lewat jatuh tempo'));
        $this->assertCount(0, $this->alarms('Tagihan vendor mendekati jatuh tempo'));
    }

    /**
     * --dry-run: the scan, printed with the rows it would name, nothing
     * written. The RECAP's T3.1 acceptance ("production dry-run lists
     * BIL/2026/VII/0002") and the Phase 3 gate name it; erp:approval-watch has
     * carried the same flag since the 2 Sep patch. Reading what the 08:30 run
     * WOULD send must not itself send it.
     */
    public function test_a_dry_run_prints_the_findings_and_writes_no_notification(): void
    {
        $this->adminUser();
        $bill = $this->apBill();

        $this->artisan('erp:deadline-watch --dry-run')
            ->expectsOutputToContain('ap_due [lewat]: 1 row(s) -> fin.create')
            ->expectsOutputToContain($bill->code.' senilai Rp 48,5 jt jatuh tempo 27 Jun 2026 — 35 hari lalu.')
            ->assertExitCode(0);

        $this->assertCount(0, $this->alarms());
    }

    // ------------------------------------------------- PKWT (register baru)

    public function test_a_kontrak_employee_without_a_pkwt_end_date_alarms_hr_on_day_one(): void
    {
        $this->adminUser();
        $employee = $this->employee(); // kontrak, pkwt_end_date NULL — EMP-0007's live state

        $this->watch();

        $alarm = $this->alarms('PKWT tanpa tanggal berakhir')->sole();
        $this->assertStringContainsString($employee->name, $alarm->body);
        $this->assertStringContainsString('tanpa tanggal akhir PKWT tercatat', $alarm->body);
        $this->assertSame('r/hr/employees', $alarm->link);
    }

    public function test_a_tetap_employee_without_a_pkwt_end_date_stays_silent(): void
    {
        $this->adminUser();
        $this->employee(['employment_type' => 'tetap']);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /**
     * PP 35/2021 Pasal 9: a PKWT berdasarkan selesainya pekerjaan tertentu has
     * no calendar end date BY LAW. Nagging HR about it weekly pushes them to
     * invent one — the exact corruption the pkwt_basis column exists to stop.
     */
    public function test_a_completion_based_pkwt_without_an_end_date_stays_silent(): void
    {
        $this->adminUser();
        $this->employee(['pkwt_basis' => 'selesainya_pekerjaan']);

        $this->watch();

        $this->assertCount(0, $this->alarms('PKWT tanpa tanggal berakhir'));
    }

    /** An unrecorded basis is its own omission — the alarm stays. */
    public function test_a_kontrak_employee_with_no_recorded_basis_still_alarms(): void
    {
        $this->adminUser();
        $employee = $this->employee(['pkwt_basis' => null]);

        $this->watch();

        $alarm = $this->alarms('PKWT tanpa tanggal berakhir')->sole();
        $this->assertStringContainsString($employee->name, $alarm->body);
    }

    public function test_a_pkwt_end_date_inside_the_lead_window_alarms(): void
    {
        $this->adminUser();
        $employee = $this->employee(['pkwt_end_date' => '2026-09-15']); // 45 of 60 lead days

        $this->watch();

        $alarm = $this->alarms('PKWT mendekati tanggal berakhir')->sole();
        $this->assertStringContainsString($employee->name, $alarm->body);
        $this->assertStringContainsString('45 hari lagi', $alarm->body);
    }

    // ------------------------------------------ certificates (register baru)

    public function test_an_expiring_certificate_alarms_hr(): void
    {
        $this->adminUser();
        $this->certificate($this->employee(['employment_type' => 'tetap'])); // expires 2026-09-01, lead 60

        $this->watch();

        $alarm = $this->alarms('Sertifikat mendekati kedaluwarsa')->sole();
        $this->assertStringContainsString('SKK Ahli Madya', $alarm->body);
        $this->assertStringContainsString('kedaluwarsa 1 Sep 2026', $alarm->body);
    }

    public function test_a_soft_deleted_certificate_stays_silent(): void
    {
        $this->adminUser();
        $this->certificate($this->employee(['employment_type' => 'tetap']))->delete();

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    // ------------------------------------------- guarantees (register baru)

    public function test_an_expiring_active_guarantee_alarms_the_director(): void
    {
        $this->adminUser();
        $guarantee = $this->guarantee(); // active, ends 2026-08-20, lead 30

        $this->watch();

        $alarm = $this->alarms('Jaminan/asuransi mendekati tanggal berakhir')->sole();
        $this->assertStringContainsString($guarantee->number, $alarm->body);
        $this->assertStringContainsString('senilai Rp 9,7 M', $alarm->body);
        $this->assertSame('r/crm/guarantees', $alarm->link);
    }

    public function test_a_released_guarantee_stays_silent(): void
    {
        $this->adminUser();
        $this->guarantee(['status' => 'released']);

        $this->watch();

        $this->assertCount(0, $this->alarms('Jaminan/asuransi mendekati tanggal berakhir'));
        $this->assertCount(0, $this->alarms('Jaminan/asuransi lewat tanggal berakhir'));
    }

    /**
     * A guarantee is valid THROUGH its end date (berlaku s/d): on the end day
     * itself the register shows Berlaku, 0 days left, is_expired false
     * (Guarantee::isExpired is strictly-before-today) — so the same morning's
     * notification must not say "lewat" about a bond that is still claimable.
     * valid_through_end puts the last valid day in MENIPIS as "hari ini".
     */
    public function test_a_guarantee_on_its_end_day_is_menipis_hari_ini_not_lewat(): void
    {
        $this->adminUser();
        $guarantee = $this->guarantee(['end_date' => self::TODAY]);

        $this->watch();

        $alarm = $this->alarms('Jaminan/asuransi mendekati tanggal berakhir')->sole();
        $this->assertStringContainsString($guarantee->number, $alarm->body);
        $this->assertStringContainsString('hari ini', $alarm->body);
        $this->assertCount(0, $this->alarms('Jaminan/asuransi lewat tanggal berakhir'));

        // The two shipped semantics now agree on the exact end day.
        $this->assertFalse($guarantee->fresh()->isExpired());
    }

    public function test_a_guarantee_the_day_after_its_end_date_alarms_as_lewat(): void
    {
        $this->adminUser();
        $this->guarantee(['end_date' => '2026-07-31']); // yesterday

        $this->watch();

        $alarm = $this->alarms('Jaminan/asuransi lewat tanggal berakhir')->sole();
        $this->assertStringContainsString('1 hari lalu', $alarm->body);
        $this->assertCount(0, $this->alarms('Jaminan/asuransi mendekati tanggal berakhir'));
    }

    /**
     * The registry invariant behind valid_through_end: without a lead window
     * the end day would land in NO tier at all — silent on the one day acting
     * still helps.
     */
    public function test_every_valid_through_end_entry_has_a_lead_window(): void
    {
        foreach (WatchedDeadlines::entries() as $entry) {
            if ($entry['valid_through_end'] ?? false) {
                $this->assertGreaterThan(0, $entry['lead_days'], "{$entry['key']} needs lead_days > 0 for its end day to report as MENIPIS.");
            }
        }
    }

    // ---------------------------------------------------------------- assets

    public function test_a_maintenance_next_due_inside_the_window_alarms(): void
    {
        $this->adminUser();
        Maintenance::query()->create([
            'asset_id' => $this->asset()->id,
            'maintenance_date' => '2026-06-14',
            'maintenance_type' => 'service_rutin',
            'next_due_date' => '2026-08-10',
        ]);

        $this->watch();

        $this->assertCount(1, $this->alarms('Servis aset mendekati jadwal berikut'));
    }

    /**
     * next_due_date is manual entry and nothing rolls it forward: recording
     * the newer service is the only way the old reminder dies, so it must.
     */
    public function test_a_newer_maintenance_record_silences_the_older_next_due_date(): void
    {
        $this->adminUser();
        $asset = $this->asset();
        Maintenance::query()->create([
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-02-10',
            'maintenance_type' => 'service_rutin',
            'next_due_date' => '2026-08-10', // would alarm, but is superseded
        ]);
        Maintenance::query()->create([
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-07-20',
            'maintenance_type' => 'service_rutin',
            'next_due_date' => '2026-12-14',
        ]);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /**
     * The PKWT pattern for assets: a mechanic records the December service on
     * the Excavator Komatsu PC200-8 and leaves next_due_date blank. The new
     * row supersedes the old dated reminder (latest_per_group) — without the
     * missing-date alarm the asset would drop off the watch list forever,
     * NULL read as "no more service" when it means "forgot to schedule".
     */
    public function test_the_newest_maintenance_without_a_next_due_date_alarms_instead_of_going_dark(): void
    {
        $this->adminUser();
        $asset = $this->asset();
        Maintenance::query()->create([
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-06-14',
            'maintenance_type' => 'service_rutin',
            'next_due_date' => '2026-08-10', // would alarm, but is superseded
        ]);
        $latest = Maintenance::query()->create([
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-07-28',
            'maintenance_type' => 'service_rutin',
            'next_due_date' => null, // the forgotten field
        ]);

        $this->watch();

        $alarm = $this->alarms('Servis aset tanpa jadwal berikut')->sole();
        $this->assertStringContainsString($latest->code, $alarm->body);
        $this->assertStringContainsString('tanpa jadwal berikut', $alarm->body);
        // The superseded reminder stays silent — the alarm is about the miss.
        $this->assertCount(0, $this->alarms('Servis aset mendekati jadwal berikut'));
    }

    public function test_an_active_deployment_nearing_its_planned_return_alarms(): void
    {
        $this->adminUser();
        Deployment::query()->create([
            'asset_id' => $this->asset()->id,
            'project_id' => $this->project()->id,
            'deployed_from' => '2026-05-01',
            'planned_until' => '2026-08-04',
            'status' => 'active',
        ]);

        $this->watch();

        $this->assertCount(1, $this->alarms('Penempatan aset mendekati rencana kembali'));
    }

    public function test_a_returned_deployment_stays_silent(): void
    {
        $this->adminUser();
        Deployment::query()->create([
            'asset_id' => $this->asset()->id,
            'project_id' => $this->project()->id,
            'deployed_from' => '2026-05-01',
            'planned_until' => '2026-08-04',
            'returned_at' => '2026-07-30',
            'status' => 'returned',
        ]);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    // ------------------------------------- cross-module scopes worth pinning

    public function test_an_unbilled_termin_on_an_approved_contract_alarms_finance(): void
    {
        $this->adminUser();
        ContractTermin::query()->create([
            'contract_id' => $this->approvedContract()->id,
            'termin_no' => 2,
            'name' => 'Triwulan II 25%',
            'percent' => 25,
            'amount' => 120_000_000,
            'due_date' => '2026-08-06',
        ]);

        $this->watch();

        $alarm = $this->alarms('Termin kontrak mendekati jadwal tagih')->sole();
        $this->assertStringContainsString('Triwulan II 25%', $alarm->body);
        $this->assertStringContainsString('senilai Rp 120 jt', $alarm->body);
        $this->assertSame('siap-tagih', $alarm->link);
    }

    public function test_a_billed_termin_stays_silent(): void
    {
        $this->adminUser();
        ContractTermin::query()->create([
            'contract_id' => $this->approvedContract()->id,
            'termin_no' => 2,
            'name' => 'Triwulan II 25%',
            'percent' => 25,
            'amount' => 120_000_000,
            'due_date' => '2026-08-06',
            'billed_at' => '2026-07-01',
        ]);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /**
     * On live data all 13 crm_contract_termins rows have due_date NULL —
     * including the RS Medika "Triwulan II 25%" (Rp 120 jt) whose quarter
     * passed unbilled. A watcher that matches nothing because there are NO
     * dates to watch must say so on the CLI instead of impersonating health.
     * No dates are invented; the operator is told to enter them.
     */
    public function test_termins_that_all_lack_due_dates_report_blind_instead_of_silent(): void
    {
        $this->adminUser();
        $contract = $this->approvedContract();

        foreach ([['Triwulan II 25%', 2], ['Triwulan III 25%', 3]] as [$name, $no]) {
            ContractTermin::query()->create([
                'contract_id' => $contract->id,
                'termin_no' => $no,
                'name' => $name,
                'percent' => 25,
                'amount' => 120_000_000,
                'due_date' => null,
            ]);
        }

        $this->artisan('erp:deadline-watch')
            ->expectsOutputToContain('BLIND termin_due: 2 row(s) in scope but every crm_contract_termins.due_date is NULL')
            ->assertExitCode(0);

        // Honesty is a CLI line, never an invented notification.
        $this->assertCount(0, $this->alarms());
    }

    public function test_a_dated_termin_beside_undated_ones_alarms_and_is_not_blind(): void
    {
        $this->adminUser();
        $contract = $this->approvedContract();

        ContractTermin::query()->create([
            'contract_id' => $contract->id,
            'termin_no' => 2,
            'name' => 'Triwulan II 25%',
            'percent' => 25,
            'amount' => 120_000_000,
            'due_date' => '2026-08-06',
        ]);
        ContractTermin::query()->create([
            'contract_id' => $contract->id,
            'termin_no' => 3,
            'name' => 'Triwulan III 25%',
            'percent' => 25,
            'amount' => 120_000_000,
            'due_date' => null,
        ]);

        $this->artisan('erp:deadline-watch')
            ->doesntExpectOutputToContain('BLIND termin_due')
            ->assertExitCode(0);

        $this->assertCount(1, $this->alarms('Termin kontrak mendekati jadwal tagih'));
    }

    public function test_a_milestone_of_an_active_project_nearing_its_due_date_alarms(): void
    {
        $this->adminUser();
        Milestone::query()->create([
            'project_id' => $this->project()->id,
            'name' => 'Progres fisik 80%',
            'due_date' => '2026-08-05',
        ]);

        $this->watch();

        $alarm = $this->alarms('Milestone proyek mendekati jatuh tempo')->sole();
        $this->assertStringContainsString('Progres fisik 80%', $alarm->body);
    }

    /**
     * An unachieved milestone of an on-hold or closed project will never be
     * achieved — contracts get terminated mid-build in this industry, and
     * nagging prj.update every 3 days with no action left is exactly the
     * cancelled-document noise every other scope was designed to avoid.
     */
    public function test_a_milestone_of_an_on_hold_or_closed_project_stays_silent(): void
    {
        $this->adminUser();

        foreach (['on_hold', 'closed'] as $status) {
            Milestone::query()->create([
                'project_id' => $this->project(['status' => $status])->id,
                'name' => "Progres fisik 80% ({$status})",
                'due_date' => '2026-07-01', // long overdue, still silent
            ]);
        }

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    public function test_a_milestone_of_a_soft_deleted_project_stays_silent(): void
    {
        $this->adminUser();
        $project = $this->project();
        Milestone::query()->create([
            'project_id' => $project->id,
            'name' => 'Progres fisik 80%',
            'due_date' => '2026-07-01',
        ]);
        $project->delete();

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    public function test_an_open_safety_incident_nearing_its_due_date_alarms_the_site(): void
    {
        $this->adminUser();
        SafetyIncident::query()->create([
            'project_id' => $this->project()->id,
            'occurred_at' => '2026-07-25 14:00:00',
            'severity' => 'first_aid',
            'category' => 'fall_from_height',
            'description' => 'Terpeleset di perancah lantai 3.',
            'due_date' => '2026-08-03',
            'status' => 'open',
        ]);

        $this->watch();

        $this->assertCount(1, $this->alarms('Tindak lanjut insiden K3 mendekati batas waktu'));
    }

    public function test_a_closed_safety_incident_stays_silent(): void
    {
        $this->adminUser();
        SafetyIncident::query()->create([
            'project_id' => $this->project()->id,
            'occurred_at' => '2026-07-25 14:00:00',
            'severity' => 'first_aid',
            'category' => 'fall_from_height',
            'description' => 'Terpeleset di perancah lantai 3.',
            'due_date' => '2026-07-28',
            'status' => 'closed',
            'closed_at' => '2026-07-28',
        ]);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    // ------------------------------------------------------------ recipients

    public function test_an_alarm_reaches_only_the_holders_of_its_permission(): void
    {
        $procurement = $this->userWith('prc.update');
        $this->userWith('hr.update');
        $this->purchaseOrder();

        $this->watch();

        $this->assertSame(
            [$procurement->id],
            $this->alarms('Pesanan pembelian lewat tanggal terima')->pluck('user_id')->all(),
        );
    }

    // ----------------------------------------------------------- degradation

    /**
     * Two other teams migrate this repository daily; a table that is not
     * there yet is a SKIP line and a SUCCESS exit, never a crash — and the
     * remaining watchers still run.
     */
    public function test_a_dropped_table_is_skipped_and_the_command_still_succeeds(): void
    {
        $this->adminUser();
        $this->quotation(['valid_until' => '2026-08-10']);
        Schema::drop('crm_guarantees');
        // The per-process schema memo was already primed by setUp()'s flush +
        // earlier queries in THIS test; the drop invalidates it mid-test.
        WatchedDeadlines::flushSchemaMemo();

        $this->artisan('erp:deadline-watch')
            ->expectsOutputToContain('table crm_guarantees does not exist')
            ->assertExitCode(0);

        $this->assertCount(1, $this->alarms('Penawaran mendekati akhir masa berlaku'));
    }

    /**
     * Same degradation one level down: the columns a SCOPE filters on are
     * declared per entry and schema-checked too. Finance consolidating
     * fin_ar_retentions.released into released_at would pass hasTable — and
     * without this check the retention EXISTS would throw QueryException
     * mid-loop, killing every watcher after it and the API with it.
     */
    public function test_a_dropped_scope_column_is_skipped_and_the_rest_still_run(): void
    {
        $this->adminUser();
        $this->quotation(['valid_until' => '2026-08-10']);
        Schema::table('fin_ar_retentions', function (Blueprint $table): void {
            $table->dropColumn('released');
        });

        $this->artisan('erp:deadline-watch')
            ->expectsOutputToContain('column fin_ar_retentions.released does not exist')
            ->assertExitCode(0);

        $this->assertCount(1, $this->alarms('Penawaran mendekati akhir masa berlaku'));
    }

    // --------------------------------------------------- repeats, honestly

    public function test_an_unread_reminder_is_not_duplicated_the_next_day(): void
    {
        $this->adminUser();
        $this->quotation(['valid_until' => '2026-08-10']);

        $this->watch();
        Carbon::setTestNow('2026-08-02 09:00:00');
        $this->watch();

        $this->assertCount(1, $this->alarms('Penawaran mendekati akhir masa berlaku'));
    }

    public function test_a_read_reminder_stays_silent_inside_the_renag_window_and_refires_after_it(): void
    {
        $this->adminUser();
        $this->quotation(['valid_until' => '2026-08-11']);

        $this->watch();
        Notification::query()->update(['read_at' => now()]);

        // Read two days ago, MENIPIS renag window is 7 — still quiet.
        Carbon::setTestNow('2026-08-03 09:00:00');
        $this->watch();
        $this->assertCount(1, $this->alarms('Penawaran mendekati akhir masa berlaku'));

        // Day 8: the window has passed, the deadline has not — nag again.
        Carbon::setTestNow('2026-08-09 09:00:00');
        $this->watch();
        $this->assertCount(2, $this->alarms('Penawaran mendekati akhir masa berlaku'));
    }

    /**
     * Escalation needs no machinery: MENIPIS and LEWAT are different stable
     * titles, so crossing the line fires at once even while the softer
     * warning sits unread — the one moment a repeat must NOT be suppressed.
     */
    public function test_a_tier_change_fires_immediately_even_while_the_old_tier_sits_unread(): void
    {
        $this->adminUser();
        $this->quotation(['valid_until' => '2026-08-03']);

        $this->watch();
        $this->assertCount(1, $this->alarms('Penawaran mendekati akhir masa berlaku'));

        Carbon::setTestNow('2026-08-05 09:00:00');
        $this->watch();

        $this->assertCount(1, $this->alarms('Penawaran lewat masa berlaku'));
        $this->assertCount(1, $this->alarms('Penawaran mendekati akhir masa berlaku'));
    }

    /**
     * The dedupe compares a content fingerprint (which rows, how many), not
     * the title alone: a third PO going overdue the day after "Total 2 PO."
     * was delivered must not hide behind the stale unread copy while the
     * inbox actively understates.
     */
    public function test_a_new_row_joining_an_unread_alarm_group_fires_a_fresh_notification(): void
    {
        $this->adminUser();
        $this->purchaseOrder(); // Rp 232,5 jt, promised 1 Mar

        $this->watch();
        $this->assertCount(1, $this->alarms('Pesanan pembelian lewat tanggal terima'));

        // Next morning a second PO is past its promise; the first copy is
        // still unread. Same stable title, different fingerprint — fire.
        Carbon::setTestNow('2026-08-02 09:00:00');
        $this->purchaseOrder(['expected_date' => '2026-03-23', 'total' => 128_300_000]);
        $this->watch();

        $alarms = $this->alarms('Pesanan pembelian lewat tanggal terima');
        $this->assertCount(2, $alarms);
        $this->assertStringContainsString('Total 2 PO.', $alarms->sortByDesc('id')->first()->body);
    }

    public function test_an_unchanged_group_stays_inside_the_renag_window_but_a_changed_one_breaks_it(): void
    {
        $this->adminUser();
        $this->purchaseOrder();

        $this->watch();
        Notification::query()->update(['read_at' => now()]);

        // Read yesterday, LEWAT renag is 3 days, same composition: quiet.
        Carbon::setTestNow('2026-08-02 09:00:00');
        $this->watch();
        $this->assertCount(1, $this->alarms('Pesanan pembelian lewat tanggal terima'));

        // Still inside the window, but a new PO joined the group: fire now,
        // exactly like the tier-change escalation.
        Carbon::setTestNow('2026-08-03 09:00:00');
        $this->purchaseOrder(['expected_date' => '2026-03-23', 'total' => 128_300_000]);
        $this->watch();
        $this->assertCount(2, $this->alarms('Pesanan pembelian lewat tanggal terima'));
    }

    /**
     * The renag parameter defaults to null, which must keep CloseWatch and
     * BackupWatch byte-identical: the moment a copy is read, the next run
     * inserts a fresh one.
     */
    public function test_system_without_a_renag_window_refires_the_moment_a_copy_is_read(): void
    {
        $this->adminUser();
        $notifications = app(NotificationService::class);

        $notifications->system('core.approve', 'Periode 2026-06 belum ditutup', 'Isi uji.');
        Notification::query()->update(['read_at' => now()]);
        $notifications->system('core.approve', 'Periode 2026-06 belum ditutup', 'Isi uji.');

        $this->assertCount(2, $this->alarms('Periode 2026-06 belum ditutup'));
    }
}
