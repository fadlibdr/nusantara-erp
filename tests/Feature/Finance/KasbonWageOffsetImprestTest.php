<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Database\Seeders\TaxSeeder;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\PettyCashFund;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Subcontract\LaborFixtures;

/**
 * Defect C (repair wave P4) — the imprest identity under a mandor wage offset.
 *
 * A kasbon recovered lewat potongan upah (KasbonService::offsetAgainstWageBill,
 * dipanggil ApBillService::approve) mengkredit 1-1370 dari jurnal tagihannya:
 * piutang laci DIKONSUMSI hutang upah, TANPA uang kembali ke laci. Ekspektasi
 * imprest harus turun sebesar offset saat itu juga — layar kasir menyebut
 * selisih sebagai temuan, bukan noise, jadi selisih palsu sebesar offset
 * adalah alarm palsu permanen sampai isi ulang berikutnya.
 */
class KasbonWageOffsetImprestTest extends ErpTestCase
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

    /** Laci Rp 5.000.000 yang didanai PENUH sampai float-nya. */
    private function fundedFund(): PettyCashFund
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-02-01');

        return $fund;
    }

    /** Kasbon cair lewat jalur produksi (issue oleh pemegang laci). */
    private function issuedKasbon(PettyCashFund $fund, float $amount): Kasbon
    {
        $employeeId = DB::table('hr_employees')->insertGetId([
            'code' => 'EMP-DC'.str_pad((string) (DB::table('hr_employees')->count() + 1), 3, '0', STR_PAD_LEFT),
            'name' => 'Agus Prasetyo',
            'nik_ktp' => str_pad((string) (3175020000000000 + DB::table('hr_employees')->count() + 1), 16, '0'),
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

    /**
     * Tagihan upah mandor approved atas SP3 100 m2 x Rp 50.000 dengan satu
     * opname penuh yang memotong kasbonnya — jalur produksi P4 selengkapnya.
     */
    private function approvedWageBill(Kasbon $kasbon, float $deduction): ApBill
    {
        $contract = $this->makeApprovedLaborContract(
            [],
            [['qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();

        $claim = $this->approvedLaborClaim($contract, [$item->id => 100], [
            'kasbon_id' => $kasbon->id,
            'kasbon_deduction_amount' => $deduction,
        ]);

        return $this->approveBill($this->apBills()->create(['labor_claim_id' => $claim->id]));
    }

    /**
     * Repro verifier: float 5.000.000, kasbon 2.000.000 cair, offset penuh
     * 2.000.000 lewat tagihan upah. Piutang laci dikonsumsi hutang upah —
     * uang TIDAK kembali ke laci, jadi ekspektasi harus ikut turun ke
     * 3.000.000 = saldo GL. Selisihnya nol, bukan 2.000.000.
     */
    public function test_offset_penuh_tidak_meninggalkan_selisih_imprest(): void
    {
        $fund = $this->fundedFund();
        $kasbon = $this->issuedKasbon($fund, 2000000);

        // Sebelum offset: 5.000.000 − 2.000.000 kasbon berjalan = 3.000.000.
        $this->assertSame(3000000.0, $this->funds()->balance($fund));
        $this->assertSame(3000000.0, $this->funds()->imprestExpectation($fund));

        $this->approvedWageBill($kasbon, 2000000);
        $this->assertSame(KasbonStatus::Settled, $kasbon->fresh()->status);

        // Jurnal tagihan tidak menyentuh akun laci — saldo GL tetap.
        $this->assertSame(3000000.0, $this->funds()->balance($fund));

        // Suku offset berdiri, identitas menutup: 5.000.000 − 0 bon −
        // 0 kasbon berjalan − 0 belanja kasbon − 2.000.000 offset = 3.000.000.
        $this->assertSame(2000000.0, $this->funds()->unreplenishedWageOffsetTotal($fund));
        $this->assertSame(3000000.0, $this->funds()->imprestExpectation($fund));

        // Modal kerja yang termakan upah dipulihkan lewat isi ulang biasa.
        $this->assertSame(2000000.0, $this->funds()->replenishmentDue($fund));
    }

    /**
     * Offset parsial: kasbon masih ISSUED dan suku kasbon-berjalan sudah
     * memuat nilai MUKA penuh (termasuk irisan yang terpulihkan) — suku
     * offset tidak boleh menghitungnya lagi, atau rupiah yang sama dikurangi
     * dua kali.
     */
    public function test_offset_parsial_tidak_dihitung_ganda_selama_kasbon_masih_cair(): void
    {
        $fund = $this->fundedFund();
        $kasbon = $this->issuedKasbon($fund, 3000000);

        $this->approvedWageBill($kasbon, 2000000);

        $fresh = $kasbon->fresh();
        $this->assertSame(KasbonStatus::Issued, $fresh->status);
        $this->assertSame(1000000.0, $fresh->outstandingAmount());

        // Suku kasbon-berjalan tetap muka penuh; suku offset nol (belum
        // Settled): 5.000.000 − 3.000.000 = 2.000.000 = saldo GL.
        $this->assertSame(3000000.0, $this->funds()->outstandingKasbonTotal($fund));
        $this->assertSame(0.0, $this->funds()->unreplenishedWageOffsetTotal($fund));
        $this->assertSame(2000000.0, $this->funds()->balance($fund));
        $this->assertSame(2000000.0, $this->funds()->imprestExpectation($fund));
    }

    /**
     * Pembatalan tagihan upah: jurnal pembaliknya mendebit 1-1370 kembali dan
     * releaseWageOffset membuka kasbonnya lagi — nilai muka penuh kembali ke
     * suku kasbon-berjalan, suku offset kosong, ekspektasi pulih PERSIS.
     */
    public function test_pembatalan_tagihan_memulihkan_ekspektasi_persis(): void
    {
        $fund = $this->fundedFund();
        $kasbon = $this->issuedKasbon($fund, 2000000);

        $bill = $this->approvedWageBill($kasbon, 2000000);
        $this->assertSame(3000000.0, $this->funds()->imprestExpectation($fund));

        $this->apBills()->cancel($bill, $this->financeApprover(), 'Volume opname salah input');

        $fresh = $kasbon->fresh();
        $this->assertSame(KasbonStatus::Issued, $fresh->status);
        $this->assertSame('0.00', (string) $fresh->wage_offset_total);

        // Identitas kembali ke bentuk pra-offset dan tetap menutup:
        // 5.000.000 − 2.000.000 kasbon berjalan = 3.000.000 = saldo GL.
        $this->assertSame(0.0, $this->funds()->unreplenishedWageOffsetTotal($fund));
        $this->assertSame(3000000.0, $this->funds()->balance($fund));
        $this->assertSame(3000000.0, $this->funds()->imprestExpectation($fund));
        $this->assertSame(0.0, $this->funds()->replenishmentDue($fund));
    }

    /**
     * Campuran offset + pertanggungjawaban kuitansi, lalu isi ulang: tiap
     * rupiah dihitung SEKALI — belanja kuitansi di suku baris, irisan offset
     * di suku offset — dan keduanya bertahan sampai isi ulang TERPOSTING
     * (aturan temuan #8), lalu identitas menutup di float.
     */
    public function test_campuran_offset_dan_kuitansi_lalu_isi_ulang_menutup_identitas(): void
    {
        $bank = $this->makeBankAccount('1-1210');
        $fund = $this->fundedFund();
        $kasbon = $this->issuedKasbon($fund, 3000000);

        $this->approvedWageBill($kasbon, 2000000);

        // Kuitansi Rp 600.000 atas sisa muka 1.000.000 → kembalian Rp 400.000.
        $this->kasbons()->settle($kasbon->fresh(), [[
            'category' => 'material',
            'description' => 'Semen tambahan tukang',
            'amount' => 600000,
            'project_id' => $this->defaultLaborProject()->id,
        ]], '2026-04-10', $this->custodianUser());

        // Saldo GL: 5.000.000 − 3.000.000 cair + 400.000 kembalian = 2.400.000.
        $this->assertSame(2400000.0, $this->funds()->balance($fund));

        // Tanpa hitung ganda: belanja kuitansi 600.000 di suku baris, irisan
        // offset 2.000.000 di suku offset — ekspektasi 5.000.000 − 600.000 −
        // 2.000.000 = 2.400.000 = saldo GL.
        $this->assertSame(600000.0, $this->funds()->settledKasbonSpendTotal($fund));
        $this->assertSame(2000000.0, $this->funds()->unreplenishedWageOffsetTotal($fund));
        $this->assertSame(2400000.0, $this->funds()->imprestExpectation($fund));

        // Isi ulang 600.000 kuitansi + 2.000.000 offset = 2.600.000.
        $this->assertSame(2600000.0, $this->funds()->replenishmentDue($fund));

        $payment = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-04-20',
            'bank_account_id' => $bank->id,
            'amount' => 2600000,
            'petty_cash_fund_id' => $fund->id,
        ]);
        $allocation = [['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => 2600000]];

        $this->payments()->submit($payment, $allocation, $this->financeUser());

        // Distempel saat diajukan, tetapi belum diganti: kedua suku bertahan.
        $this->assertSame($payment->id, (int) $kasbon->refresh()->replenishment_payment_id);
        $this->assertSame(2000000.0, $this->funds()->unreplenishedWageOffsetTotal($fund));
        $this->assertSame(2400000.0, $this->funds()->imprestExpectation($fund));

        $this->payments()->approve($payment->refresh(), $this->financeApprover());
        $this->payments()->post($payment->refresh(), $allocation);

        // Terposting: laci utuh dan setiap suku identitas bersih.
        $this->assertSame(5000000.0, $this->funds()->balance($fund));
        $this->assertSame(0.0, $this->funds()->settledKasbonSpendTotal($fund));
        $this->assertSame(0.0, $this->funds()->unreplenishedWageOffsetTotal($fund));
        $this->assertSame(5000000.0, $this->funds()->imprestExpectation($fund));
    }
}
