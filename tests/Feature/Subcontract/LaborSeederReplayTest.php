<?php

namespace Tests\Feature\Subcontract;

use Modules\Crm\Database\Seeders\CrmDatabaseSeeder;
use Modules\Estimation\Database\Seeders\EstimationDatabaseSeeder;
use Modules\Iam\Database\Seeders\IamDatabaseSeeder;
use Modules\Procurement\Database\Seeders\ProcurementDatabaseSeeder;
use Modules\Projects\Database\Seeders\ProjectsDatabaseSeeder;
use Modules\Subcontract\Database\Seeders\SubcontractDatabaseSeeder;
use Modules\Subcontract\Models\LaborClaim;
use Modules\Subcontract\Models\LaborContract;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * `db:seed` KEDUA DI ATAS BASIS TERISI HARUS KONVERGEN, BUKAN CRASH.
 *
 * Jalur seed-ulang SP3/OPM (P4) pernah hapus-bangun baris kontraknya —
 * padahal scm_labor_claim_items.labor_contract_item_id ber-FK ke baris itu,
 * jadi begitu OPM/2026/III/0001 ada, `$contract->items()->delete()` memicu
 * SQLSTATE[23000]. Dan seandainya pun lolos, id baru berarti riwayat
 * roll-forward opname menunjuk baris mati (migrasi scm_labor_contracts
 * menjelaskan mengapa kunci id baris justru andalannya).
 *
 * Kanon di-seed dengan seeder ASLI dalam urutan ASLI
 * (database/seeders/DatabaseSeeder::$moduleOrder), seperti
 * RkkSeederLinkageTest — yang diuji adalah replay `db:seed` yang
 * sebenarnya, bukan tiruan rakitan tangan. Aturannya sama dengan
 * ProjectsDatabaseSeeder::rekeyMeasurementLines: baris dokumen approved
 * tidak pernah dihapus — konvergen di tempat.
 */
class LaborSeederReplayTest extends ErpTestCase
{
    private function seedCanon(): void
    {
        $this->seed(IamDatabaseSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CrmDatabaseSeeder::class);
        $this->seed(EstimationDatabaseSeeder::class);
        $this->seed(ProjectsDatabaseSeeder::class);
        $this->seed(ProcurementDatabaseSeeder::class);
        $this->seed(SubcontractDatabaseSeeder::class);
    }

    private function canonContract(): LaborContract
    {
        return LaborContract::query()->where('code', 'SP3/2026/III/0001')->firstOrFail();
    }

    private function canonClaim(): LaborClaim
    {
        return LaborClaim::query()->where('code', 'OPM/2026/III/0001')->firstOrFail();
    }

    public function test_a_fresh_seed_produces_an_approved_opname_pointing_at_live_contract_lines(): void
    {
        $this->seedCanon();

        $contract = $this->canonContract();
        $this->assertSame('261600000.00', (string) $contract->value);
        $this->assertCount(2, $contract->items);

        $claim = $this->canonClaim();
        $liveItemIds = $contract->items->modelKeys();

        $this->assertCount(2, $claim->items);

        foreach ($claim->items as $line) {
            $this->assertContains((int) $line->labor_contract_item_id, $liveItemIds,
                'Baris opname demo menunjuk baris SP3 yang tidak hidup.');
        }
    }

    /** Seed ulang di atas basis terisi menghasilkan keadaan yang SAMA, tanpa penggandaan. */
    public function test_a_second_seed_run_converges_without_replacing_the_claimed_lines(): void
    {
        $this->seedCanon();

        $contract = $this->canonContract();
        $itemIdsByLineNo = $contract->items->pluck('id', 'line_no')->all();
        $claimLineIds = $this->canonClaim()->items()->orderBy('id')->pluck('id')->all();

        // Penggeseran di antara dua seed (mis. tangan iseng di demo): replay
        // harus MENGEMBALIKAN kanon lewat update di tempat, bukan sekadar
        // selamat dari FK — id baris tetap, angkanya menyusul.
        $contract->items()->where('line_no', 1)->update(['unit_rate' => 99999]);

        $this->seed(CrmDatabaseSeeder::class);
        $this->seed(EstimationDatabaseSeeder::class);
        $this->seed(ProjectsDatabaseSeeder::class);
        $this->seed(ProcurementDatabaseSeeder::class);
        $this->seed(SubcontractDatabaseSeeder::class);

        $after = $this->canonContract();

        // Baris kontrak yang diklaim tidak diganti: id per line_no identik,
        // jadi FK opname tetap menunjuk baris hidup yang sama.
        $this->assertSame($itemIdsByLineNo, $after->items->pluck('id', 'line_no')->all());
        $this->assertSame('45000.00', (string) $after->items->firstWhere('line_no', 1)->unit_rate);
        $this->assertSame('261600000.00', (string) $after->value);

        // Baris opname approved tidak pernah dihapus — id-nya pun sama.
        $claim = $this->canonClaim();
        $this->assertSame($claimLineIds, $claim->items()->orderBy('id')->pluck('id')->all());
        $this->assertSame('27220000.00', (string) $claim->gross_amount);

        // Satu SP3 kanon, satu OPM kanon, tanpa baris ganda.
        $this->assertSame(1, LaborContract::withTrashed()->where('code', 'SP3/2026/III/0001')->count());
        $this->assertSame(1, LaborClaim::withTrashed()->where('code', 'OPM/2026/III/0001')->count());
        $this->assertSame(2, $after->items()->count());
        $this->assertSame(2, $claim->items()->count());
    }
}
