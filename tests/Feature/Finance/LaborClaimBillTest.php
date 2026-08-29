<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Database\Seeders\TaxSeeder;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\Tax;
use Modules\Subcontract\Models\LaborClaim;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Subcontract\LaborFixtures;

/**
 * P4 — seam Finance untuk opname mandor: fin_ap_bills.labor_claim_id (cermin
 * subcontract_claim_id), potongan kasbon lewat KasbonService (kredit 1-1370),
 * PPh final UMKM 0,5% ke 2-1230, dan biaya proyek kategori Upah.
 */
class LaborClaimBillTest extends ErpTestCase
{
    use FinanceFixtures;
    use LaborFixtures;
    use PettyCashFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger();
        $this->seed(TaxSeeder::class);
    }

    /**
     * Kasbon CAIR lewat jalur produksi penuh (issue oleh pemegang laci), agar
     * offset yang diuji berdiri di atas 1-1370 yang benar-benar didebit.
     */
    private function issuedKasbon(float $amount): Kasbon
    {
        $fund = $this->makeFund(['max_kasbon_amount' => null]);
        $this->fundDrawer($fund, $amount + 1000000, '2026-02-01');

        $employeeId = DB::table('hr_employees')->insertGetId([
            'code' => 'EMP-P4F', 'name' => 'Agus Prasetyo', 'nik_ktp' => '3175010101900002',
            'gender' => 'male', 'birth_date' => '1990-01-01', 'ptkp_status' => 'TK/0',
            'join_date' => '2024-01-05', 'employment_type' => 'tetap', 'position' => 'Site Manager',
            'department' => 'proyek', 'base_salary' => 9000000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $kasbon = $this->kasbons()->create([
            'fund_id' => $fund->id,
            'employee_id' => $employeeId,
            'advance_date' => '2026-03-01',
            'amount' => $amount,
            'purpose' => 'Uang muka upah mandor',
            'project_id' => $this->defaultLaborProject()->id,
        ], $this->custodianUser());

        return $this->kasbons()->issue($kasbon, $this->custodianUser());
    }

    /** SP3 approved 100 m2 x Rp 50.000 dengan satu opname approved penuh. */
    private function approvedClaimWithKasbon(?Kasbon $kasbon, float $deduction): LaborClaim
    {
        $contract = $this->makeApprovedLaborContract(
            [],
            [['qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();

        return $this->approvedLaborClaim($contract, [$item->id => 100], array_filter([
            'kasbon_id' => $kasbon?->id,
            'kasbon_deduction_amount' => $deduction,
        ], fn ($value) => $value !== null));
    }

    public function test_tagihan_opname_mandor_jurnal_lengkap_dan_offset_kasbon_parsial(): void
    {
        $kasbon = $this->issuedKasbon(3000000);
        $claim = $this->approvedClaimWithKasbon($kasbon, 2000000);

        // gross 5.000.000 · pph 0,5% = 25.000 · potongan kasbon 2.000.000
        // dpp tagihan = 5.000.000 - 2.000.000 = 3.000.000 (NET, pola subkon)
        // total_payable = 3.000.000 - 25.000 = 2.975.000 = net_payable klaim
        $bill = $this->apBills()->create(['labor_claim_id' => $claim->id]);

        $this->assertSame('3000000.00', (string) $bill->dpp);
        $this->assertSame('25000.00', (string) $bill->pph_amount);
        $this->assertSame('2975000.00', (string) $bill->total_payable);
        $this->assertSame((int) $claim->id, (int) $bill->labor_claim_id);
        $this->assertNull($bill->subcontract_claim_id);

        // Baris pajaknya PPH4A2-UMKM (PP 55/2022), bukan skema PP 9/2022.
        $this->assertSame(
            (int) Tax::query()->where('code', 'PPH4A2-UMKM')->value('id'),
            (int) $bill->pph_tax_id,
        );

        $approved = $this->approveBill($bill);

        // Jurnal: Dr 5-1200 Upah 5.000.000 (gross, kategori Labor)
        //         Cr 1-1370 Piutang Karyawan 2.000.000 (potongan kasbon)
        //         Cr 2-1100 Hutang Usaha 2.975.000
        //         Cr 2-1230 Hutang PPh Final 25.000
        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $approved->id));
        $this->assertSame(5000000.0, $lines['5-1200']['debit']);
        $this->assertSame(2000000.0, $lines['1-1370']['credit']);
        $this->assertSame(2975000.0, $lines['2-1100']['credit']);
        $this->assertSame(25000.0, $lines['2-1230']['credit']);

        // Biaya proyek: gross penuh, kategori Upah.
        $this->assertDatabaseHas('fin_project_costs', [
            'project_id' => $this->defaultLaborProject()->id,
            'cost_category' => 'labor',
            'reference_type' => 'ap_bill',
            'reference_id' => $approved->id,
            'amount' => '5000000.00',
        ]);

        // Offset tercatat pada kasbon lewat KasbonService: sisa berkurang,
        // status tetap Issued karena baru sebagian terpulihkan.
        $fresh = $kasbon->fresh();
        $this->assertSame('2000000.00', (string) $fresh->wage_offset_total);
        $this->assertSame(KasbonStatus::Issued, $fresh->status);
        $this->assertSame(1000000.0, $fresh->outstandingAmount());
        $this->assertSame('2000000.00', (string) $approved->advance_applied_amount);

        // PPh > 0 => bukti potong bernomor, seperti tagihan lain.
        $this->assertNotNull($approved->bupot_no);
    }

    public function test_potongan_penuh_menyelesaikan_kasbon_tanpa_baris_kuitansi(): void
    {
        $kasbon = $this->issuedKasbon(2000000);
        $claim = $this->approvedClaimWithKasbon($kasbon, 2000000);

        $this->approveBill($this->apBills()->create(['labor_claim_id' => $claim->id]));

        $fresh = $kasbon->fresh();
        $this->assertSame(KasbonStatus::Settled, $fresh->status);
        $this->assertSame('0.00', (string) $fresh->cash_returned);
        $this->assertNotNull($fresh->settled_at);
        $this->assertSame(0, $fresh->lines()->count());
        $this->assertSame(0.0, $fresh->outstandingAmount());
    }

    public function test_tagihan_menolak_opname_belum_approved_dan_tagihan_ganda(): void
    {
        $contract = $this->makeApprovedLaborContract(
            [],
            [['qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();
        $draft = $this->draftLaborClaim($contract, [$item->id => 40]);

        try {
            $this->apBills()->create(['labor_claim_id' => $draft->id]);
            $this->fail('Opname draft tidak boleh ditagihkan.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah disetujui', $e->getMessage());
        }

        $draft->submit($this->laborActor());
        $approved = $this->laborClaims()->approve($draft->refresh(), $this->laborApprover());

        $this->apBills()->create(['labor_claim_id' => $approved->id]);

        try {
            $this->apBills()->create(['labor_claim_id' => $approved->id]);
            $this->fail('Satu opname satu tagihan.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah ada', $e->getMessage());
        }
    }

    public function test_balapan_kasbon_menggagalkan_persetujuan_tagihan_kedua(): void
    {
        $kasbon = $this->issuedKasbon(2500000);

        // Dua SP3 di proyek yang sama, dua opname yang menunjuk kasbon yang
        // sama: 2.000.000 + 2.000.000 > 2.500.000.
        $first = $this->approvedClaimWithKasbon($kasbon, 2000000);
        $second = $this->approvedClaimWithKasbon($kasbon, 2000000);

        $this->approveBill($this->apBills()->create(['labor_claim_id' => $first->id]));

        $bill = $this->apBills()->create(['labor_claim_id' => $second->id]);
        $bill->submit($this->financeUser());

        try {
            $this->apBills()->approve($bill->refresh(), $this->financeApprover());
            $this->fail('Potongan melebihi sisa kasbon hidup harus menggagalkan persetujuan.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi sisa kasbon', $e->getMessage());
        }

        // Persetujuan batal seutuhnya: tagihan tetap submitted, tanpa jurnal.
        $fresh = $bill->fresh();
        $this->assertSame(DocumentStatus::Submitted, $fresh->status);
        $this->assertSame(0, Journal::query()
            ->where('reference_type', 'ap_bill')->where('reference_id', $bill->id)->count());
        // Offset kasbon tetap milik tagihan pertama saja.
        $this->assertSame('2000000.00', (string) $kasbon->fresh()->wage_offset_total);
        $this->assertSame(500000.0, $kasbon->fresh()->outstandingAmount());
    }

    public function test_pembatalan_tagihan_mengembalikan_offset_kasbon(): void
    {
        $kasbon = $this->issuedKasbon(2000000);
        $claim = $this->approvedClaimWithKasbon($kasbon, 2000000);

        $bill = $this->approveBill($this->apBills()->create(['labor_claim_id' => $claim->id]));
        $this->assertSame(KasbonStatus::Settled, $kasbon->fresh()->status);

        $cancelled = $this->apBills()->cancel($bill, $this->financeApprover(), 'Volume salah input');

        $this->assertSame(DocumentStatus::Cancelled, $cancelled->status);

        // Kasbon terbuka kembali: offset dikembalikan, statusnya cair lagi.
        $fresh = $kasbon->fresh();
        $this->assertSame(KasbonStatus::Issued, $fresh->status);
        $this->assertSame('0.00', (string) $fresh->wage_offset_total);
        $this->assertSame(2000000.0, $fresh->outstandingAmount());

        // Dan opname-nya bisa ditagihkan ulang.
        $replacement = $this->apBills()->create(['labor_claim_id' => $claim->id]);
        $this->assertNotSame($bill->id, $replacement->id);
    }

    public function test_pertanggungjawaban_kuitansi_sadar_offset(): void
    {
        $kasbon = $this->issuedKasbon(3000000);
        $claim = $this->approvedClaimWithKasbon($kasbon, 2000000);

        $this->approveBill($this->apBills()->create(['labor_claim_id' => $claim->id]));
        $this->assertSame(1000000.0, $kasbon->fresh()->outstandingAmount());

        // Kuitansi Rp 600.000; sisa uang muka 1.000.000 → kembali Rp 400.000.
        $settled = $this->kasbons()->settle($kasbon->fresh(), [[
            'category' => 'material',
            'description' => 'Semen tambahan tukang',
            'amount' => 600000,
            'project_id' => $this->defaultLaborProject()->id,
        ]], '2026-04-10', $this->custodianUser());

        $this->assertSame(KasbonStatus::Settled, $settled->status);
        $this->assertSame('400000.00', (string) $settled->cash_returned);

        // Jurnal settlement mengkredit 1-1370 HANYA sisa 1.000.000 — bagian
        // 2.000.000 sudah dikredit jurnal tagihan upahnya.
        $lines = $this->linesByAccount($this->singleJournalFor('kasbon_settlement', (int) $kasbon->id));
        $this->assertSame(1000000.0, $lines['1-1370']['credit']);
    }

    public function test_pembatalan_ditolak_bila_kasbon_sudah_selesai_lewat_kuitansi(): void
    {
        $kasbon = $this->issuedKasbon(3000000);
        $claim = $this->approvedClaimWithKasbon($kasbon, 2000000);

        $bill = $this->approveBill($this->apBills()->create(['labor_claim_id' => $claim->id]));

        // Sisa 1.000.000 dipertanggungjawabkan penuh dengan kuitansi.
        $this->kasbons()->settle($kasbon->fresh(), [[
            'category' => 'material',
            'description' => 'Material habis pakai',
            'amount' => 1000000,
            'project_id' => $this->defaultLaborProject()->id,
        ]], '2026-04-10', $this->custodianUser());

        try {
            $this->apBills()->cancel($bill, $this->financeApprover(), 'Coba batalkan');
            $this->fail('Kasbon yang sudah selesai lewat kuitansi tidak boleh dibuka dari pembatalan tagihan.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('pertanggungjawaban kuitansi', $e->getMessage());
        }

        // Pembatalan batal seutuhnya — tagihan tetap approved.
        $this->assertSame(DocumentStatus::Approved, $bill->fresh()->status);
        $this->assertSame(KasbonStatus::Settled, $kasbon->fresh()->status);
    }

    public function test_dispatch_store_menerima_labor_claim_id_lewat_api(): void
    {
        Sanctum::actingAs($this->adminUser());

        $claim = $this->approvedClaimWithKasbon(null, 0);

        $response = $this->postJson('/api/finance/ap-bills', [
            'labor_claim_id' => $claim->id,
            'vendor_invoice_no' => 'OPM-TAGIH-001',
        ])->assertCreated();

        $this->assertSame($claim->id, $response->json('data.labor_claim_id'));
        $this->assertSame('5000000.00', $response->json('data.dpp'));

        $bill = ApBill::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame((int) $this->defaultMandor()->id, (int) $bill->vendor_id);
    }
}
