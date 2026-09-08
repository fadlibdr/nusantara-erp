<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Support\WatchedThresholds;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Support\FixtureSchema;

/**
 * F-2 / T2.1 — registri ambang: aktual vs batas, dengan keadaan yang jujur.
 *
 * Saudara WatchedDeadlines. Yang diuji di sini bukan aritmetikanya (satu bagi
 * satu kali seratus), melainkan KEADAAN yang dilaporkannya ketika salah satu
 * sisinya tidak ada — karena di situlah satu-satunya cara sebuah layar
 * anggaran bisa berbohong: menampilkan 0 % untuk proyek yang tidak punya
 * batas, atau 100 % untuk proyek yang tidak punya apa pun untuk diukur.
 */
class ThresholdWatchTest extends ErpTestCase
{
    private function project(string $code, float $contractValue, string $status = 'active'): int
    {
        return (int) DB::table('prj_projects')->insertGetId([
            'code' => $code,
            'name' => 'Proyek '.$code,
            'type' => 'construction',
            'status' => $status,
            'contract_value' => $contractValue,
            'retention_pct' => 5,
            'warranty_months' => 0,
            'planned_progress_pct' => 0,
            'actual_progress_pct' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** RAP disetujui dengan satu baris seharga $amount. */
    private function approvedRap(int $projectId, string $code, float $amount): int
    {
        $boqId = (int) DB::table('est_boqs')->insertGetId([
            'code' => 'BOQ/'.$code,
            'project_id' => $projectId,
            'title' => 'RAB '.$code,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rapId = (int) DB::table('est_cost_budgets')->insertGetId([
            'code' => $code,
            'boq_id' => $boqId,
            'project_id' => $projectId,
            'target_margin_pct' => 10,
            'total_budget' => $amount,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sectionId = (int) DB::table('est_boq_sections')->insertGetId([
            'boq_id' => $boqId,
            'section_no' => 'A',
            'name' => 'Struktur',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $boqItemId = (int) DB::table('est_boq_items')->insertGetId([
            'boq_id' => $boqId,
            'section_id' => $sectionId,
            'wbs_code' => 'A.1',
            'description' => 'Baris uji',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $amount,
            'amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('est_cost_budget_items')->insert([
            'cost_budget_id' => $rapId,
            'boq_item_id' => $boqItemId,
            'cost_category' => 'material',
            'description' => 'Baris uji',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $amount,
            'amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $rapId;
    }

    /** @return array<string, array<string, mixed>> baris entri, dikunci kode subjek */
    private function rowsOf(string $key): array
    {
        foreach (WatchedThresholds::scan()['measures'] as $measure) {
            if ($measure['key'] === $key) {
                $rows = [];
                foreach ($measure['rows'] as $row) {
                    $rows[$row['subject']] = $row;
                }

                return $rows;
            }
        }

        $this->fail("entri [{$key}] tidak ada dalam pindaian");
    }

    // ------------------------------------------------------------- keadaan

    public function test_a_project_without_a_recorded_contract_value_is_ruled_never_zero_percent(): void
    {
        $id = $this->project('PRJ-2026-801', 0.0);
        $this->approvedRap($id, 'RAP/2026/0801', 500_000_000);

        $row = $this->rowsOf('rap_vs_kontrak_pct')['PRJ-2026-801'];

        $this->assertSame(WatchedThresholds::TANPA_BATAS, $row['state']);
        $this->assertNull($row['pct'], 'sebuah batas yang tidak ada tidak boleh melahirkan persentase');
        $this->assertNull($row['limit']);
        $this->assertSame(500000000.0, $row['actual']);
        $this->assertStringContainsString('Nilai kontrak', (string) $row['note']);
    }

    public function test_a_project_without_an_approved_rap_is_not_measurable(): void
    {
        $this->project('PRJ-2026-802', 1_000_000_000);

        $row = $this->rowsOf('rap_vs_kontrak_pct')['PRJ-2026-802'];

        $this->assertSame(WatchedThresholds::TIDAK_TERUKUR, $row['state']);
        $this->assertNull($row['actual']);
        $this->assertNull($row['pct']);
        $this->assertSame(1000000000.0, $row['limit'], 'batasnya ADA — yang tidak ada adalah yang diukur');
    }

    /**
     * Keduanya hilang: yang dilaporkan adalah "tidak terukur" (tidak ada yang
     * diukur mendahului tidak ada batas), dan catatannya menyebut KEDUANYA —
     * satu keadaan tidak boleh menyembunyikan kekurangan yang lain.
     */
    public function test_a_project_missing_both_sides_names_both_and_reports_the_unmeasurable_one(): void
    {
        $this->project('PRJ-2026-803', 0.0);

        $row = $this->rowsOf('rap_vs_kontrak_pct')['PRJ-2026-803'];

        $this->assertSame(WatchedThresholds::TIDAK_TERUKUR, $row['state']);
        $this->assertStringContainsString('RAP', (string) $row['note']);
        $this->assertStringContainsString('Nilai kontrak', (string) $row['note']);
    }

    public function test_the_three_measurable_states_are_split_at_the_warning_and_at_the_limit(): void
    {
        // 50 % — aman.
        $aman = $this->project('PRJ-2026-810', 1_000_000_000);
        $this->approvedRap($aman, 'RAP/2026/0810', 500_000_000);

        // 90 % tepat — ambang peringatan tercapai, jadi MENDEKATI.
        $mendekati = $this->project('PRJ-2026-811', 1_000_000_000);
        $this->approvedRap($mendekati, 'RAP/2026/0811', 900_000_000);

        // 100 % tepat — "di batas" ada di sisi lampau, bukan sisi aman.
        $tepat = $this->project('PRJ-2026-812', 1_000_000_000);
        $this->approvedRap($tepat, 'RAP/2026/0812', 1_000_000_000);

        $rows = $this->rowsOf('rap_vs_kontrak_pct');

        $this->assertSame(WatchedThresholds::AMAN, $rows['PRJ-2026-810']['state']);
        $this->assertSame(50.0, $rows['PRJ-2026-810']['pct']);
        $this->assertSame(WatchedThresholds::MENDEKATI, $rows['PRJ-2026-811']['state']);
        $this->assertSame(90.0, $rows['PRJ-2026-811']['pct']);
        $this->assertSame(WatchedThresholds::LAMPAU, $rows['PRJ-2026-812']['state']);
        $this->assertSame(100.0, $rows['PRJ-2026-812']['pct']);
    }

    /**
     * KEADAAN DIBANDINGKAN PADA ANGKA YANG DICETAK (verifikasi F-2).
     *
     * Terukur sebelum perbaikan: Rp 899.999.999 dari Rp 1.000.000.000 dicetak
     * "90,0 % terpakai" berlencana hijau "Aman" — di bawah judul kartunya
     * sendiri "Peringatan ≥ 90 %" — karena keadaan dihitung dari persentase
     * MENTAH (89,9999999) sementara layar mencetak yang dibulatkan.
     *
     * Dan kebalikannya juga dijaga: sebuah baris yang masih menyisakan satu
     * rupiah BUKAN "melampaui", karena gerbang menerima rupiah itu — LAMPAU
     * dibandingkan pada rupiahnya, bukan pada persentase yang dibulatkan.
     * Sejak putaran 2 baris seperti itu tidak lagi MENCETAK "100,0 %" pula:
     * angka dan lencana pada satu baris tidak boleh saling membantah.
     */
    public function test_the_state_matches_the_percentage_that_is_printed_beside_it(): void
    {
        // 89,9999999 % -> dicetak "90,0 %".
        $mendekati = $this->project('PRJ-2026-815', 1_000_000_000);
        $this->approvedRap($mendekati, 'RAP/2026/0815', 899_999_999);

        // 99,9999999 % -> dulu dicetak "100,0 %", padahal masih ada Rp 1 tersisa.
        $hampir = $this->project('PRJ-2026-816', 1_000_000_000);
        $this->approvedRap($hampir, 'RAP/2026/0816', 999_999_999);

        $rows = $this->rowsOf('rap_vs_kontrak_pct');

        $this->assertSame(90.0, $rows['PRJ-2026-815']['pct']);
        $this->assertSame(WatchedThresholds::MENDEKATI, $rows['PRJ-2026-815']['state'],
            'baris yang mencetak 90,0 % tidak boleh menyebut dirinya aman');

        $this->assertSame(99.9, $rows['PRJ-2026-816']['pct'],
            'baris yang masih menyisakan satu rupiah tidak boleh mencetak "100 %"');
        $this->assertSame(WatchedThresholds::MENDEKATI, $rows['PRJ-2026-816']['state'],
            'Rp 1 yang masih diterima gerbang bukan "melampaui batas"');
    }

    /**
     * ANGKA DAN LENCANA PADA SATU BARIS TIDAK BOLEH SALING MEMBANTAH.
     *
     * Terukur di peramban (#/ambang, salinan data demo): baris
     * "PRJ-2026-001 | Rp 24.250.000.000 | Rp 24.250.000.001 | 100% | Mendekati
     * batas", tepat di bawah kartu "Cara membacanya" layar itu sendiri yang
     * berbunyi "…menjadi 'Melampaui batas' tepat pada 100 %".
     */
    public function test_a_row_still_under_its_limit_never_prints_one_hundred_percent(): void
    {
        $id = $this->project('PRJ-2026-817', 24_250_000_001);
        $this->approvedRap($id, 'RAP/2026/0817', 24_250_000_000);

        $row = $this->rowsOf('rap_vs_kontrak_pct')['PRJ-2026-817'];

        $this->assertSame(WatchedThresholds::MENDEKATI, $row['state']);
        $this->assertSame('Mendekati batas', $row['state_label']);
        $this->assertLessThan(100.0, $row['pct'],
            'lencana "Mendekati batas" di sebelah angka "100 %" adalah dua pernyataan yang bertentangan');
        $this->assertSame(99.9, $row['pct']);

        // Dan yang sungguh di batasnya tetap mencetak 100 %, di sisi lampau.
        $tepat = $this->project('PRJ-2026-818', 24_250_000_000);
        $this->approvedRap($tepat, 'RAP/2026/0818', 24_250_000_000);

        $atLimit = $this->rowsOf('rap_vs_kontrak_pct')['PRJ-2026-818'];
        $this->assertSame(100.0, $atLimit['pct']);
        $this->assertSame(WatchedThresholds::LAMPAU, $atLimit['state']);
    }

    /**
     * Aturan kejujuran paket ini, dipaku sebagai invarian atas SELURUH
     * registri: tidak ada satu pun baris yang memancarkan persentase tanpa
     * kedua sisinya, dan tidak ada satu pun batas 0 yang diperlakukan sebagai
     * batas sungguhan.
     */
    public function test_no_entry_ever_emits_a_percentage_it_could_not_compute(): void
    {
        $this->approvedRap($this->project('PRJ-2026-820', 0.0), 'RAP/2026/0820', 10_000_000);
        $this->project('PRJ-2026-821', 5_000_000);

        foreach (WatchedThresholds::scan()['measures'] as $measure) {
            foreach ($measure['rows'] as $row) {
                if ($row['actual'] === null || $row['limit'] === null) {
                    $this->assertNull($row['pct'], "[{$measure['key']}/{$row['subject']}] memancarkan persen tanpa kedua sisinya");
                    $this->assertNotNull($row['note'], "[{$measure['key']}/{$row['subject']}] tidak menyebutkan sisi mana yang hilang");
                }

                // Sebuah batas Rp 0 yang SUNGGUH disetel sebuah dokumen adalah
                // batas (verifikasi F-2 putaran 2) — yang tetap terlarang
                // adalah melahirkan PERSENTASE darinya, karena nol tidak bisa
                // dibagi, dan menenangkannya menjadi "aman".
                if ($row['limit'] !== null && (float) $row['limit'] <= 0.0) {
                    $this->assertNull($row['pct'], "[{$measure['key']}/{$row['subject']}] membagi dengan batas nol");
                    $this->assertContains(
                        $row['state'],
                        [WatchedThresholds::LAMPAU, WatchedThresholds::TANPA_ANGGARAN],
                        "[{$measure['key']}/{$row['subject']}] batas nol tidak boleh terbaca aman",
                    );
                }
            }
        }
    }

    // ----------------------------------------------------------- degradasi

    /**
     * Entri yang ukurannya dipasok modul lain dan modul itu belum memasoknya
     * adalah baris SKIPPED — bukan entri berisi nol proyek, yang terbaca
     * sebagai "semua aman".
     */
    public function test_an_entry_whose_module_never_supplied_its_measure_is_skipped_not_empty(): void
    {
        WatchedThresholds::flushSuppliers();

        $scan = WatchedThresholds::scan();

        $skipped = array_column($scan['skipped'], 'reason', 'key');
        $this->assertArrayHasKey('project_budget_pct', $skipped);
        $this->assertStringContainsString('Finance', $skipped['project_budget_pct']);
        $this->assertSame([], array_filter($scan['measures'], fn (array $m): bool => $m['key'] === 'project_budget_pct'));
    }

    public function test_a_missing_table_skips_the_entry_instead_of_crashing(): void
    {
        FixtureSchema::withMissingTable('est_cost_budget_items', function (): void {
            WatchedThresholds::flushSchemaMemo();

            $scan = WatchedThresholds::scan();

            $this->assertArrayHasKey('rap_vs_kontrak_pct', array_column($scan['skipped'], 'reason', 'key'));
        });

        WatchedThresholds::flushSchemaMemo();
    }

    /**
     * Core tidak boleh mengimpor modul fitur untuk menghitung satu pun
     * entrinya — aturan yang sama yang dijaga WatchedDeadlines, dan alasan
     * kenapa entri yang butuh aritmetika komitmen DIPASOK oleh Finance alih-alih
     * disalin ke sini.
     */
    public function test_core_imports_no_feature_module_to_compute_a_threshold(): void
    {
        $source = (string) file_get_contents(base_path('Modules/Core/Support/WatchedThresholds.php'));

        $this->assertNotSame('', $source, 'berkas registri tidak terbaca');

        preg_match_all('/^use (Modules\\\\[A-Za-z]+)\\\\/m', $source, $matches);

        foreach (array_unique($matches[1]) as $namespace) {
            $this->assertSame('Modules\\Core', $namespace, "WatchedThresholds mengimpor {$namespace}");
        }
    }

    // ------------------------------------------------------------- endpoint

    public function test_the_endpoint_returns_only_entries_the_caller_may_act_on(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->approvedRap($this->project('PRJ-2026-830', 1_000_000_000), 'RAP/2026/0830', 950_000_000);

        Sanctum::actingAs($this->userHolding('estimator@test.local', 'est.view'));
        $keys = array_column($this->getJson('/api/core/thresholds')->assertOk()->json('data'), 'key');
        $this->assertContains('rap_vs_kontrak_pct', $keys);

        Sanctum::actingAs($this->userHolding('helpdesk@test.local', 'svc.view'));
        $keys = array_column($this->getJson('/api/core/thresholds')->assertOk()->json('data'), 'key');
        $this->assertNotContains('rap_vs_kontrak_pct', $keys);
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
}
