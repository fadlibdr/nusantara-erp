<?php

namespace Tests\Feature\Crm;

use Illuminate\Support\Facades\DB;
use Modules\Crm\Database\Seeders\CrmDatabaseSeeder;
use Modules\Crm\Models\RkkDocument;
use Modules\Crm\Services\RkkService;
use Modules\Estimation\Database\Seeders\EstimationDatabaseSeeder;
use Modules\Iam\Database\Seeders\IamDatabaseSeeder;
use Modules\Projects\Database\Seeders\ProjectsDatabaseSeeder;
use Modules\Projects\Models\Project;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * DEMO KANON HARUS BENAR-BENAR MEMPERAGAKAN TAUTAN RKK → IBPRP (P6) — itu
 * headline deliverable-nya dan syarat eksplisit roadmap §3 ("dataset demo yang
 * melatih modulnya").
 *
 * Urutan seeder yang SEBENARNYA (database/seeders/DatabaseSeeder::$moduleOrder)
 * menjalankan Crm pada posisi 3 dan Projects pada posisi 7, jadi saat
 * CrmDatabaseSeeder::seedRkk() bertanya ke prj_risk_register pada seed segar,
 * register itu MASIH KOSONG — dan tanpa kembaran penyelesai di sisi Projects,
 * RKK demo terkirim dengan project_id NULL dan nol tautan IBPRP. Maka canon
 * di-seed di sini dengan seeder ASLI dalam urutan ASLI, seperti
 * ProjectsSeederP3Test, bukan dengan tiruan rakitan tangan.
 *
 * KEMBARANNYA: CrmDatabaseSeeder::seedRkk (jalur seed ulang) dan
 * ProjectsDatabaseSeeder::completeRkkIbprpLinks (jalur seed segar) menjalankan
 * pemilihan yang sama — pola AST-0007 (Procurement & Assets, P5). Tes ini yang
 * berbunyi bila salah satu sisi berubah sendirian.
 */
class RkkSeederLinkageTest extends ErpTestCase
{
    private function seedCanon(): void
    {
        $this->seed(IamDatabaseSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CrmDatabaseSeeder::class);
        $this->seed(EstimationDatabaseSeeder::class);
        $this->seed(ProjectsDatabaseSeeder::class);
    }

    private function canonRkk(): RkkDocument
    {
        return RkkDocument::query()->where('code', 'RKK/2026/VIII/0001')->firstOrFail();
    }

    public function test_a_fresh_seed_in_the_real_order_links_the_rkk_to_the_ibprp_register(): void
    {
        $this->seedCanon();

        $rkk = $this->canonRkk();

        // Sumber registernya disebut — dan itu register milik PRJ-2026-001,
        // satu-satunya proyek demo yang punya baris IBPRP.
        $graha = Project::query()->where('code', 'PRJ-2026-001')->firstOrFail();
        $this->assertSame($graha->id, (int) $rkk->project_id,
            'RKK demo tidak menyebut register sumber IBPRP-nya pada seed segar.');

        // Tautannya ADA…
        $this->assertGreaterThan(0, $rkk->ibprpLinks()->count(),
            'RKK demo tidak menaut satu pun baris IBPRP pada seed segar — tautan RKK → P6 tidak pernah diperagakan.');

        // …dan setiap barisnya hidup di register: nol tautan menggantung.
        foreach (app(RkkService::class)->ibprpRows($rkk) as $row) {
            $this->assertTrue($row['available'], "Tautan IBPRP #{$row['risk_entry_id']} menggantung.");
        }
    }

    /** Seed ulang di atas basis terisi menghasilkan keadaan yang SAMA, tanpa penggandaan. */
    public function test_a_second_seed_run_converges_on_the_same_linkage(): void
    {
        $this->seedCanon();

        $before = $this->canonRkk();
        $entryIds = $before->ibprpLinks()->orderBy('sort_order')->pluck('risk_entry_id')->all();
        $projectId = (int) $before->project_id;

        $this->assertNotSame([], $entryIds);

        // Jalur seed ulang: kini registernya TERISI saat Crm jalan, lalu
        // Projects menjalankan kembarannya lagi. Keduanya harus mendarat di
        // keadaan yang sama.
        $this->seed(CrmDatabaseSeeder::class);
        $this->seed(ProjectsDatabaseSeeder::class);

        $after = $this->canonRkk();

        $this->assertSame($projectId, (int) $after->project_id);
        $this->assertSame($entryIds, $after->ibprpLinks()->orderBy('sort_order')->pluck('risk_entry_id')->all());

        // Satu RKK kanon, dan tidak ada baris tautan ganda.
        $this->assertSame(1, RkkDocument::query()->where('code', 'RKK/2026/VIII/0001')->count());
        $this->assertSame(
            count($entryIds),
            (int) DB::table('crm_rkk_ibprp_links')->where('rkk_id', $after->id)->count(),
        );
    }
}
