<?php

namespace Tests\Feature\Subcontract;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\PettyCashFund;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\LaborFixtures;

/**
 * P4 — opname mandor: plafon volume per baris SP3 (dua arah: saat menyusun
 * dan saat menyetujui), matematika upah/PPh final, dan validasi potongan
 * kasbon. Deviasi 3.10/3.11.
 */
class LaborClaimTest extends ErpTestCase
{
    use LaborFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
    }

    // ------------------------------------------------------------ plafon dua arah

    public function test_qty_melebihi_sisa_sp3_ditolak_saat_menyusun(): void
    {
        $contract = $this->makeApprovedLaborContract(
            [],
            [['description' => 'Pasangan bata', 'qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();

        $this->approvedLaborClaim($contract, [$item->id => 60]);

        // Sisa 40 — meminta 50 ditolak dengan pesan yang menyebut angkanya.
        try {
            $this->draftLaborClaim($contract, [$item->id => 50]);
            $this->fail('Volume melebihi sisa SP3 harus ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi sisa', $e->getMessage());
            $this->assertStringContainsString('40', $e->getMessage());
        }

        // Tepat pada sisa: boleh — plafon adalah plafon, bukan larangan lunas.
        $claim = $this->draftLaborClaim($contract, [$item->id => 40]);
        $this->assertSame('2000000.00', (string) $claim->gross_amount); // 40 x 50.000
    }

    public function test_qty_yang_basi_ditolak_saat_menyetujui(): void
    {
        $contract = $this->makeApprovedLaborContract(
            [],
            [['qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();

        // Dua opname disusun dari sisa yang sama (100)...
        $first = $this->draftLaborClaim($contract, [$item->id => 70]);
        $second = $this->draftLaborClaim($contract, [$item->id => 70]);

        // ...yang pertama disetujui, sisa hidup tinggal 30.
        $first->submit($this->laborActor());
        $this->laborClaims()->approve($first->refresh(), $this->laborApprover());

        $second->submit($this->laborActor());

        try {
            $this->laborClaims()->approve($second->refresh(), $this->laborApprover());
            $this->fail('Opname yang disusun dari sisa basi harus ditolak saat approve.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('ajukan ulang', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Submitted, $second->fresh()->status);
    }

    // ------------------------------------------------------------ matematika

    public function test_matematika_upah_ppn_nol_pph_final_umkm(): void
    {
        $contract = $this->makeApprovedLaborContract(
            ['pph_rate' => 0.5, 'ppn_rate' => 0],
            [
                ['description' => 'Bata', 'qty' => 200, 'unit' => 'm2', 'unit_rate' => 45000, 'amount' => 9000000],
                ['description' => 'Plester', 'qty' => 180, 'unit' => 'm2', 'unit_rate' => 35000, 'amount' => 6300000],
            ],
        );
        [$bata, $plester] = $contract->items()->get()->all();

        $claim = $this->draftLaborClaim($contract, [$bata->id => 100, $plester->id => 60]);

        // gross = 100 x 45.000 + 60 x 35.000 = 4.500.000 + 2.100.000 = 6.600.000
        // pph   = 0,5% x 6.600.000 = 33.000 (PPh final UMKM atas gross penuh)
        // net   = 6.600.000 - 33.000 = 6.567.000
        $this->assertSame('6600000.00', (string) $claim->gross_amount);
        $this->assertSame('0.00', (string) $claim->ppn_amount);
        $this->assertSame('33000.00', (string) $claim->pph_amount);
        $this->assertSame('6567000.00', (string) $claim->net_payable);
        $this->assertStringStartsWith('OPM/', $claim->code);

        // qty_prev tersimpan 0 pada opname pertama.
        $this->assertSame('0.000', (string) $claim->items()->first()->qty_prev);
    }

    public function test_opname_hanya_atas_sp3_approved(): void
    {
        $contract = $this->makeLaborContract([], [['qty' => 10, 'unit_rate' => 50000, 'amount' => 500000]]);
        $item = $contract->items()->first();

        try {
            $this->draftLaborClaim($contract, [$item->id => 5]);
            $this->fail('Opname atas SP3 draft harus ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah disetujui', $e->getMessage());
        }
    }

    // ------------------------------------------------------------ kasbon

    private function issuedKasbon(float $amount = 3000000, ?int $projectId = null): Kasbon
    {
        $this->seedLedger();

        $fund = PettyCashFund::create([
            'code' => 'PCF-P4',
            'name' => 'Kas Kecil Site P4',
            'coa_account_id' => Account::query()->where('code', '1-1120')->value('id')
                ?? Account::query()->where('is_postable', true)->value('id'),
            'custodian_id' => $this->laborActor()->id,
            'float_amount' => 10000000,
            'is_active' => true,
        ]);

        $employeeId = DB::table('hr_employees')->insertGetId([
            'code' => 'EMP-P4', 'name' => 'Agus Prasetyo', 'nik_ktp' => '3175010101900001',
            'gender' => 'male', 'birth_date' => '1990-01-01', 'ptkp_status' => 'TK/0',
            'join_date' => '2024-01-05', 'employment_type' => 'tetap', 'position' => 'Site Manager',
            'department' => 'proyek', 'base_salary' => 9000000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Kasbon::create([
            'fund_id' => $fund->id,
            'employee_id' => $employeeId,
            'advance_date' => '2026-03-01',
            'amount' => $amount,
            'purpose' => 'Uang muka upah mandor',
            'project_id' => $projectId ?? $this->defaultLaborProject()->id,
            'status' => KasbonStatus::Issued,
            'created_by' => $this->laborActor()->id,
        ]);
    }

    public function test_potongan_kasbon_mengurangi_netto_dan_tersimpan(): void
    {
        $kasbon = $this->issuedKasbon(3000000);

        $contract = $this->makeApprovedLaborContract(
            [],
            [['qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();

        $claim = $this->draftLaborClaim($contract, [$item->id => 100], [
            'kasbon_id' => $kasbon->id,
            'kasbon_deduction_amount' => 2000000,
        ]);

        // gross 5.000.000; pph 25.000; net = 5.000.000 - 25.000 - 2.000.000
        $this->assertSame('5000000.00', (string) $claim->gross_amount);
        $this->assertSame('25000.00', (string) $claim->pph_amount);
        $this->assertSame('2000000.00', (string) $claim->kasbon_deduction_amount);
        $this->assertSame('2975000.00', (string) $claim->net_payable);
    }

    public function test_potongan_melebihi_sisa_kasbon_ditolak_422(): void
    {
        $kasbon = $this->issuedKasbon(1500000);

        $contract = $this->makeApprovedLaborContract(
            [],
            [['qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();

        try {
            $this->draftLaborClaim($contract, [$item->id => 100], [
                'kasbon_id' => $kasbon->id,
                'kasbon_deduction_amount' => 1600000,
            ]);
            $this->fail('Potongan melebihi sisa kasbon harus ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi sisa kasbon', $e->getMessage());
        }
    }

    public function test_potongan_melebihi_upah_terbayarkan_ditolak(): void
    {
        $kasbon = $this->issuedKasbon(10000000);

        $contract = $this->makeApprovedLaborContract(
            [],
            [['qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();

        // Upah terbayarkan = 5.000.000 - 25.000 = 4.975.000 < potongan 5.000.000.
        try {
            $this->draftLaborClaim($contract, [$item->id => 100], [
                'kasbon_id' => $kasbon->id,
                'kasbon_deduction_amount' => 5000000,
            ]);
            $this->fail('Netto minus harus ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('netto tidak boleh minus', strtolower($e->getMessage()));
        }
    }

    public function test_kasbon_proyek_lain_dan_kasbon_belum_cair_ditolak(): void
    {
        $otherProject = Project::create(['name' => 'Proyek Lain', 'type' => 'construction']);
        $kasbonLain = $this->issuedKasbon(3000000, $otherProject->id);

        $contract = $this->makeApprovedLaborContract(
            [],
            [['qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();

        try {
            $this->draftLaborClaim($contract, [$item->id => 50], [
                'kasbon_id' => $kasbonLain->id,
                'kasbon_deduction_amount' => 500000,
            ]);
            $this->fail('Kasbon proyek lain harus ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('milik proyek lain', $e->getMessage());
        }

        $draftKasbon = Kasbon::create([
            'fund_id' => $kasbonLain->fund_id,
            'employee_id' => $kasbonLain->employee_id,
            'advance_date' => '2026-03-02',
            'amount' => 1000000,
            'purpose' => 'Belum cair',
            'project_id' => $this->defaultLaborProject()->id,
            'status' => KasbonStatus::Draft,
            'created_by' => $this->laborActor()->id,
        ]);

        try {
            $this->draftLaborClaim($contract, [$item->id => 50], [
                'kasbon_id' => $draftKasbon->id,
                'kasbon_deduction_amount' => 500000,
            ]);
            $this->fail('Kasbon draft harus ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah cair', $e->getMessage());
        }

        // Potongan tanpa menunjuk kasbonnya juga bukan angka yang sah.
        try {
            $this->draftLaborClaim($contract, [$item->id => 50], [
                'kasbon_deduction_amount' => 500000,
            ]);
            $this->fail('Potongan tanpa kasbon harus ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('menunjuk kasbon', $e->getMessage());
        }
    }

    // ------------------------------------------------------------ maker-checker

    public function test_maker_checker_pengaju_tidak_boleh_menyetujui_opname_sendiri(): void
    {
        $contract = $this->makeApprovedLaborContract(
            [],
            [['qty' => 100, 'unit' => 'm2', 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $item = $contract->items()->first();

        $claim = $this->draftLaborClaim($contract, [$item->id => 50]);
        $claim->submit($this->laborActor());

        try {
            $this->laborClaims()->approve($claim->refresh(), $this->laborActor());
            $this->fail('Pengaju tidak boleh menyetujui opnamenya sendiri.');
        } catch (LogicException $e) {
            $this->assertSame(DocumentStatus::Submitted, $claim->fresh()->status);
        }

        $approved = $this->laborClaims()->approve($claim->refresh(), $this->laborApprover());
        $this->assertSame(DocumentStatus::Approved, $approved->status);
    }
}
