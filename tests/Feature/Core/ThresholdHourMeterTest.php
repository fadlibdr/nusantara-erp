<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\EquipmentLog;
use Modules\Assets\Models\Maintenance;
use Modules\Assets\Services\MaintenanceDueService;
use Modules\Core\Support\WatchedThresholds;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * F-7 di registri ambang — entri 'maintenance_hour_meter'.
 *
 * Tiga hal dipaku di sini, dan masing-masing adalah bentuk dari satu cacat
 * yang berulang di kampanye ini (aturan benar di satu permukaan, bocor di
 * permukaan kedua):
 *
 *   KESETARAAN. Baris registri harus menjawab persis seperti service Assets
 *   yang dibaca kartu alat. Entrinya DIPASOK (supply()) justru supaya tidak
 *   ada kueri kedua yang bisa berselisih — dan uji ini memaku bahwa yang
 *   dipasok memang jawaban service itu, bukan salinan yang sudah menyimpang.
 *
 *   SATUAN. Setiap entri mendeklarasikan 'unit' sejak F-2 dan tidak ada yang
 *   membacanya; entri berjam pertama akan mencetak rupiah untuk jam.
 *
 *   AMBANG DALAM SATUANNYA. Entri ini tidak punya persentase sama sekali, jadi
 *   ia tidak boleh mengirim warn_pct yang tidak menghakimi apa pun — dan entri
 *   rupiah tidak boleh kehilangan persentasenya karena entri ini ada.
 */
class ThresholdHourMeterTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['erp.thresholds.maintenance_hour_meter' => 50]);
    }

    private function entry(): array
    {
        return collect(WatchedThresholds::entries())->firstWhere('key', 'maintenance_hour_meter');
    }

    private function measure(): ?array
    {
        return collect(WatchedThresholds::scan()['measures'])->firstWhere('key', 'maintenance_hour_meter');
    }

    private function excavator(float $reading, ?float $target, ?string $dateTarget = null): Asset
    {
        $category = AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            ['name' => 'Alat Berat', 'useful_life_months_default' => 96],
        );
        $project = Project::query()->firstOrCreate(
            ['code' => 'PRJ-2026-001'],
            ['name' => 'Graha Sentosa', 'type' => 'construction', 'status' => 'active'],
        );
        $clerk = User::query()->firstOrCreate(
            ['email' => 'agus@test.local'],
            ['name' => 'Agus Prasetyo', 'password' => 'password', 'is_active' => true],
        );

        $asset = Asset::query()->create([
            'code' => 'AST-'.str_pad((string) (Asset::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => 'Excavator Komatsu PC200-8',
            'category_id' => $category->id,
            'acquisition_date' => '2025-01-01',
            'acquisition_cost' => 960_000_000,
            'salvage_value' => 0,
            'useful_life_months' => 96,
            'accumulated_depreciation' => 0,
            'book_value' => 960_000_000,
            'status' => 'available',
        ]);

        $deployment = Deployment::query()->create([
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'deployed_from' => '2026-03-02',
            'status' => 'active',
        ]);

        EquipmentLog::query()->create([
            'deployment_id' => $deployment->id,
            'log_date' => '2026-07-01',
            'hour_meter' => $reading,
            'logged_by' => $clerk->id,
        ]);

        Maintenance::query()->create([
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-06-14',
            'maintenance_type' => 'service_rutin',
            'cost' => 0,
            'next_due_date' => $dateTarget,
            'next_due_hour_meter' => $target,
        ]);

        return $asset;
    }

    /**
     * KESETARAAN: baris registri = jawaban service, angka demi angka.
     *
     * Kalau suatu hari seseorang menyalin kuerinya ke Core "supaya lebih
     * cepat", uji ini merah pada baris pertama yang berbeda.
     */
    public function test_the_registry_row_is_the_assets_service_answer(): void
    {
        $asset = $this->excavator(4960, 5000, '2026-12-14');

        $service = app(MaintenanceDueService::class)->forAsset($asset);
        $row = collect($this->measure()['rows'])->firstWhere('subject', $asset->code);

        $this->assertNotNull($row);
        $this->assertSame($service['reading'], $row['actual']);
        $this->assertSame($service['next_due_hour_meter'], $row['limit']);
        $this->assertSame($service['state'], $row['state']);
        $this->assertSame($service['note'], $row['note']);
        $this->assertSame('d/assets/assets/'.$asset->id, $row['link']);
    }

    /**
     * Kunci config yang dibaca Core dan yang dibaca Assets adalah SATU kunci.
     * Core tidak boleh mengimpor Assets untuk membacanya, jadi keduanya
     * literal — dan dua literal yang harus sama adalah dua literal yang harus
     * dipaku, atau layar Ambang dan kartu alat bisa memakai dua ambang.
     */
    public function test_core_and_assets_read_the_same_warning_margin_key(): void
    {
        $this->assertSame(MaintenanceDueService::WARN_MARGIN_KEY, $this->entry()['warn_margin_key']);
        $this->assertSame(50.0, $this->measure()['warn_margin']);
        $this->assertSame(50.0, app(MaintenanceDueService::class)->warnMarginHours());
    }

    /**
     * BAWAAN ENTRI CORE ADALAH ANGKA YANG SAMA dengan bawaan service Assets.
     *
     * Ditemukan lewat mutasi: tanpa uji ini, mengubah warn_margin_default di
     * entri Core dari 50 menjadi 999 LOLOS HIJAU — config produksi selalu
     * menyebutkan kuncinya, jadi bawaannya tidak pernah tersentuh sampai
     * seseorang mengosongkan config, dan pada hari itu layar Ambang akan
     * memperingatkan pada jarak yang berbeda dari kartu alat.
     */
    public function test_the_core_entry_falls_back_to_the_same_default_as_assets(): void
    {
        config(['erp.thresholds.maintenance_hour_meter' => null]);

        $this->assertSame(50.0, $this->measure()['warn_margin']);
        $this->assertSame(MaintenanceDueService::WARN_MARGIN_DEFAULT, $this->measure()['warn_margin']);
    }

    /** Entri ini mengukur JAM, dan mengatakannya. */
    public function test_the_entry_declares_hours_and_no_percentage(): void
    {
        $this->excavator(4960, 5000);

        $measure = $this->measure();

        $this->assertSame('jam', $measure['unit']);
        $this->assertFalse($measure['proportional']);
        // Tidak ada ambang persen yang dikirim: lencana "≥ 90 %" di atas tabel
        // yang tidak punya satu persentase pun adalah lencana yang membantah
        // barisnya sendiri.
        $this->assertNull($measure['warn_pct']);

        $row = $measure['rows'][0];
        $this->assertNull($row['pct']);
        $this->assertSame(40.0, $row['remaining']);
    }

    /**
     * DAN ENTRI RUPIAH TIDAK KEHILANGAN PERSENTASENYA karena entri jam ada.
     * Generalisasi 'proportional' bawaannya true, dan uji ini yang memastikan
     * bawaan itu benar-benar berlaku.
     */
    public function test_the_rupiah_entries_keep_their_percentage_and_percent_warning(): void
    {
        $overhead = collect(WatchedThresholds::scan()['measures'])->firstWhere('key', 'overhead_budget_pct');

        $this->assertNotNull($overhead);
        $this->assertSame('rupiah', $overhead['unit']);
        $this->assertTrue($overhead['proportional']);
        $this->assertSame(90.0, $overhead['warn_pct']);
        $this->assertNull($overhead['warn_margin']);
    }

    /**
     * state() dengan margin: LAMPAU dihakimi pada ANGKANYA, MENDEKATI pada
     * jaraknya. Angka-angka ditulis di sini, bukan dibaca dari config.
     */
    public function test_state_with_a_margin_warns_by_distance_and_passes_by_value(): void
    {
        $this->assertSame(WatchedThresholds::AMAN, WatchedThresholds::state(4949.0, 5000.0, 90.0, 50.0));
        $this->assertSame(WatchedThresholds::MENDEKATI, WatchedThresholds::state(4950.0, 5000.0, 90.0, 50.0));
        $this->assertSame(WatchedThresholds::LAMPAU, WatchedThresholds::state(5000.0, 5000.0, 90.0, 50.0));

        // Tanpa margin, angka yang sama dihakimi persen — 98,98 % sudah lewat
        // ambang 90 %, jadi 4.949 di sana MENDEKATI. Dua lensa, dua jawaban,
        // dan itulah kenapa margin ada.
        $this->assertSame(WatchedThresholds::MENDEKATI, WatchedThresholds::state(4949.0, 5000.0, 90.0));
    }

    /**
     * DUA BARIS YANG SAMA-SAMA "50 JAM LAGI" MENDAPAT SATU LENCANA.
     *
     * Angka bulat tidak pernah bisa menangkap ini: 5.000 − 50,0 persis, jadi
     * uji di atas hijau meski keputusannya diambil pada selisih float MENTAH
     * sementara SISA yang dicetak layar dibulatkan tiga desimal. Pada target
     * satu desimal keduanya berbeda — 512,2 − 50,0 = 462,20000000000005
     * sementara pembacaannya 462,19999999999999 — dan dua alat yang sama-sama
     * 50 jam sebelum target berdiri bersebelahan di layar Ambang dengan dua
     * lencana yang berbeda. Yang dihakimi harus SISA yang dicetak, aturan
     * yang sama yang sudah dipegang sisi persen sejak F-2.
     */
    public function test_two_rows_with_the_same_printed_hours_left_get_the_same_state(): void
    {
        $this->assertSame(WatchedThresholds::MENDEKATI, WatchedThresholds::state(462.2, 512.2, 90.0, 50.0));
        $this->assertSame(WatchedThresholds::MENDEKATI, WatchedThresholds::state(5070.2, 5120.2, 90.0, 50.0));

        // …dan ambangnya tidak ikut bergeser: 50,1 jam sebelum target masih
        // AMAN pada target pecahan yang sama.
        $this->assertSame(WatchedThresholds::AMAN, WatchedThresholds::state(462.1, 512.2, 90.0, 50.0));
    }

    /**
     * Tiga keadaan yang bukan angka tetap mendahului margin: alat tanpa
     * pembacaan TIDAK_TERUKUR, pembacaan tanpa target TANPA_BATAS.
     */
    public function test_the_two_non_numeric_states_survive_the_margin(): void
    {
        $this->assertSame(WatchedThresholds::TIDAK_TERUKUR, WatchedThresholds::state(null, 5000.0, 90.0, 50.0));
        $this->assertSame(WatchedThresholds::TANPA_BATAS, WatchedThresholds::state(4800.0, null, 90.0, 50.0));
    }

    /**
     * Urutan baris: yang paling dekat ke targetnya lebih dulu — SISA, bukan
     * persentase. Alat 12.000 jam yang masih 400 jam lagi TIDAK boleh
     * mendahului alat 900 jam yang tinggal 12 jam hanya karena persentasenya
     * lebih besar.
     */
    public function test_rows_are_ordered_by_hours_remaining_not_by_percentage(): void
    {
        $aman = $this->excavator(11_600, 12_000);      // sisa 400 — AMAN
        $tua = $this->excavator(11_980, 12_000);       // sisa  20 — MENDEKATI, 99,8 %
        $muda = $this->excavator(888, 900);            // sisa  12 — MENDEKATI, 98,7 %

        $rows = collect($this->measure()['rows']);

        // KEADAAN dulu: yang AMAN turun ke dasar meski persentasenya 96,7 %.
        // JARAK sesudahnya, DAN JARAK-lah yang mengurutkan dua baris MENDEKATI
        // — kalau persentase yang dipakai, alat 12.000 jam (99,8 %) akan
        // mendahului alat 900 jam yang justru tinggal 12 jam lagi.
        $this->assertSame([$muda->code, $tua->code, $aman->code], $rows->pluck('subject')->all());
        $this->assertSame([12.0, 20.0, 400.0], $rows->pluck('remaining')->all());
    }

    /**
     * Layar Ambang hanya memberi entri kepada yang boleh menindaknya:
     * ast.update, izin yang sama dengan pemicu tanggal.
     */
    public function test_the_entry_is_hidden_from_a_user_without_ast_update(): void
    {
        $this->excavator(4960, 5000);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('keuangan-saja', 'web');
        $role->syncPermissions(['fin.view']);
        $user = User::query()->create([
            'name' => 'Sri Wahyuni',
            'email' => 'sri@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $keys = collect($this->actingAs($user)->getJson('/api/core/thresholds')->json('data'))->pluck('key');

        $this->assertNotContains('maintenance_hour_meter', $keys);
    }

    /** …dan memberikannya kepada yang boleh. Termasuk satuan dan marginnya. */
    public function test_the_api_carries_the_unit_and_the_margin_to_the_screen(): void
    {
        $this->excavator(4960, 5000);

        $measure = collect($this->actingAs($this->adminUser())->getJson('/api/core/thresholds')->json('data'))
            ->firstWhere('key', 'maintenance_hour_meter');

        $this->assertSame('jam', $measure['unit']);
        // (float): JSON menuliskan 50.0 sebagai 50, dan yang diuji di sini
        // adalah angka yang sampai ke layar, bukan tipe PHP-nya.
        $this->assertSame(50.0, (float) $measure['warn_margin']);
        $this->assertFalse($measure['proportional']);
        $this->assertSame('alat', $measure['subject_word']);
    }
}
