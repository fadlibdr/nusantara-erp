<?php

namespace Tests\Feature\Finance;

use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Exceptions\ApprovalLevelException;
use Modules\Core\Models\Approval;
use Modules\Core\Support\ApprovalPolicy;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\Journal;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * SEBUAH PERSETUJUAN YANG BERHENTI DI TENGAH MEMPOSTING JURNALNYA DUA KALI —
 * dan ini uji yang memakunya (verifikasi F-1, 7 Sep 2026).
 *
 * Yang terukur pada F-1 yang dikirim: dengan
 * approvals.ar_invoice.mode = extra_level dan ambang Rp 1, sebuah invoice
 * termin Rp 2,22 miliar
 *
 *   persetujuan ke-1 → status masih `submitted`, TETAPI 1 jurnal terposting
 *                      (piutang, pendapatan, PPN keluaran pada dokumen yang
 *                      belum disetujui);
 *   persetujuan ke-2 → status `approved` dan jurnal KEDUA terposting —
 *                      JV/2026/09/0001 dan JV/2026/09/0002, keduanya posted.
 *
 * Sebabnya ada di dua tempat sekaligus: ArInvoiceService memperlakukan
 * approve() sebagai terminal dan memanggil autoPost di baris berikutnya tanpa
 * syarat, dan JournalService::autoPost tidak idempoten (create + post setiap
 * kali). Perbaikannya menutup jalannya di hulu — layar tidak lagi menawarkan
 * mode itu di sini — dan menaruh satu penolakan di hilir untuk stempel yang
 * sudah terlanjur tertulis. Uji ini mengambil jalan hilir itu, karena jalan
 * itulah yang harus tetap menolak ketika seseorang menulis barisnya dengan
 * tangan.
 */
class ArInvoiceExtraLevelRefusalTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->customer = $this->makeCustomer();
        $this->contract = $this->makeContract($this->customer, ['value' => 10000000000]);
    }

    public function test_a_stamp_that_asks_for_a_second_level_refuses_instead_of_posting_the_journal(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);
        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
            'withhold_retention' => true,
        ]);

        $invoice->submit($this->financeUser());

        // Stempel yang TIDAK BISA lagi dibuat lewat layar, ditulis dengan
        // tangan persis seperti instalasi yang sempat memasang F-1 sebelum
        // putaran verifikasi ini akan memilikinya.
        $submission = Approval::query()
            ->where('approvable_type', $invoice->getMorphClass())
            ->where('approvable_id', $invoice->getKey())
            ->where('action', 'submitted')
            ->latest('id')
            ->firstOrFail();
        $submission->forceFill(['policy' => [
            'v' => ApprovalPolicy::STAMP_VERSION,
            'type' => 'ar_invoice',
            'prefix' => 'fin',
            'amount' => 2220000000.0,
            'threshold' => 1.0,
            'mode' => ApprovalPolicy::MODE_EXTRA_LEVEL,
            'third_level_threshold' => null,
            'levels' => 2,
            'director' => false,
        ]])->save();

        $this->assertSame(2, $invoice->fresh()->requiredApprovalLevels());

        try {
            $this->arInvoices()->approve($invoice->fresh(), $this->financeApprover());
            $this->fail('a levelled stamp on an AR invoice must be refused, not half-approved');
        } catch (ApprovalLevelException $e) {
            $this->assertStringContainsString('tidak dapat berhenti di tengah persetujuan', $e->getMessage());
            $this->assertStringContainsString('Matriks Persetujuan', $e->getMessage());
        }

        // TIDAK SATU JURNAL PUN, dan dokumennya tetap di meja penyetujunya.
        $this->assertSame(0, Journal::query()
            ->where('reference_type', 'ar_invoice')
            ->where('reference_id', $invoice->id)
            ->count());
        $this->assertSame(DocumentStatus::Submitted, $invoice->fresh()->status);
    }

    /**
     * Dan jalur biasa tidak berubah: tanpa stempel bertingkat, satu
     * persetujuan tetap membukukan tepat satu jurnal.
     */
    public function test_the_ordinary_single_approval_still_books_exactly_one_journal(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);
        $invoice = $this->approveInvoice($this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
            'withhold_retention' => true,
        ]));

        $this->assertSame(DocumentStatus::Approved, $invoice->status);
        $this->assertSame(1, Journal::query()
            ->where('reference_type', 'ar_invoice')
            ->where('reference_id', $invoice->id)
            ->count());
    }
}
