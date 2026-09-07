<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Modules\Core\Exceptions\ApprovalLevelException;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\ApprovalPolicy;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Payment;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * AMBANG DIREKTUR PADA "PEMBAYARAN KELUAR" DITEGAKKAN (verifikasi F-1).
 *
 * Cacat yang ditutup: matriks persetujuan menawarkan ambang pada baris ini dan
 * stempelnya berbunyi `director: true`, tetapi satu-satunya penegak stempel di
 * aplikasi ini ada di dalam trait Approvable — dan Payment tidak memakai trait
 * itu (PaymentStatus bukan DocumentStatus). Terukur pada F-1 yang dikirim:
 * pembayaran Rp 111.000.000 dengan ambang Rp 1 disetujui oleh orang yang tidak
 * memegang fin.approve-director, dan jejaknya mencatat bahwa direktur
 * dituntut. Itulah kegagalan yang docblock DirectorApproval ditulis untuk.
 *
 * Yang dipaku di bawah adalah kedua arahnya — ditolak tanpa izin, diterima
 * dengan izin — plus jaminan bahwa instalasi yang belum menyentuh ambangnya
 * tidak berubah sama sekali.
 */
class PaymentDirectorThresholdTest extends ErpTestCase
{
    use FinanceFixtures;

    private Vendor $vendor;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        ApprovalPolicy::flushSchemaMemo();

        $this->seedLedger(2026);
        $this->vendor = $this->makeVendor();
        $this->bank = $this->makeBankAccount('1-1210');
    }

    private function userHolding(string $email, string ...$permissions): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas '.$email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** DPP 100.000.000 + PPN 11.000.000 = terutang 111.000.000. */
    private function approvedBill(): ApBill
    {
        return $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Tagihan vendor',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]));
    }

    private function submittedPayment(): Payment
    {
        $bill = $this->approvedBill();

        $payment = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-04-05',
            'bank_account_id' => $this->bank->id,
            'amount' => 111000000,
        ]);

        return $this->payments()->submit(
            $payment,
            [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 111000000]],
            $this->financeUser(),
        );
    }

    public function test_a_payment_over_the_threshold_is_refused_to_an_approver_without_the_director_right(): void
    {
        app(SettingService::class)->set('approvals.payment.threshold_two_level', 1);

        $payment = $this->submittedPayment();

        $stamp = ApprovalPolicy::stampedFor($payment);
        $this->assertTrue((bool) $stamp['director'], 'the submission must be stamped director: true');
        $this->assertSame(111000000.0, (float) $stamp['amount']);

        $approver = $this->userHolding('kasir@t.local', 'fin.approve');

        try {
            $this->payments()->approve($payment, $approver);
            $this->fail('a stamped director threshold on an outgoing payment must be enforced');
        } catch (ApprovalLevelException $e) {
            $this->assertStringContainsString('fin.approve-director', $e->getMessage());
            $this->assertStringContainsString('Rp 111.000.000', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Submitted, $payment->fresh()->status);
    }

    public function test_the_same_payment_is_approved_by_a_holder_of_the_director_right(): void
    {
        app(SettingService::class)->set('approvals.payment.threshold_two_level', 1);

        $payment = $this->submittedPayment();
        $director = $this->userHolding('direktur@t.local', 'fin.approve', 'fin.approve-director');

        $approved = $this->payments()->approve($payment, $director);

        $this->assertSame(PaymentStatus::Approved, $approved->status);
    }

    /**
     * DAN INSTALASI YANG BELUM MENYENTUH AMBANGNYA TIDAK BERUBAH. Bawaan baris
     * ini adalah "tanpa ambang", jadi penjaga baru ini diam — memasang paket
     * ini tidak mengubah satu pun keputusan.
     */
    public function test_with_no_threshold_set_the_new_guard_is_silent(): void
    {
        $this->assertNull(ApprovalPolicy::forType('payment')->threshold);

        $payment = $this->submittedPayment();
        $stamp = ApprovalPolicy::stampedFor($payment);

        $this->assertFalse((bool) $stamp['director']);

        $approved = $this->payments()->approve($payment, $this->userHolding('kasir@t.local', 'fin.approve'));

        $this->assertSame(PaymentStatus::Approved, $approved->status);
    }
}
