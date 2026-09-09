<?php

namespace Tests\Feature\Assets;

use App\Models\User;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\EquipmentLog;
use Modules\Assets\Models\Maintenance;
use Modules\Assets\Services\MaintenanceDueService;
use Modules\Core\Support\WatchedDeadlines;
use Modules\Core\Support\WatchedThresholds;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * F-7 — jatuh tempo servis alat menurut JAM OPERASI.
 *
 * Setiap uji di bawah ini memaku satu keputusan yang bisa dipilih salah, dan
 * angkanya DITULIS di ujinya sendiri — tidak satu pun harapan disusun dari
 * konstanta produksi yang sedang diujinya (pelajaran F-6: uji yang membandingkan
 * sebuah konstanta dengan dirinya sendiri hijau untuk nilai APA PUN).
 */
class MaintenanceHourMeterDueTest extends ErpTestCase
{
    private MaintenanceDueService $service;

    private ?User $clerk = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MaintenanceDueService::class);
        // Ambang ditulis di ujinya, bukan dibaca dari config produksi: seluruh
        // angka batas di bawah ini (4.950 / 4.949) dihitung dari 50 ini.
        config(['erp.thresholds.maintenance_hour_meter' => 50]);
    }

    // -------------------------------------------------------------- fixtures

    private function asset(array $attributes = []): Asset
    {
        $category = AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            ['name' => 'Alat Berat', 'useful_life_months_default' => 96],
        );

        return Asset::query()->create(array_merge([
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
        ], $attributes));
    }

    private function deployment(Asset $asset, array $attributes = []): Deployment
    {
        $project = Project::query()->firstOrCreate(
            ['code' => 'PRJ-2026-001'],
            ['name' => 'Pembangunan Gedung Kantor Graha Sentosa', 'type' => 'construction', 'status' => 'active'],
        );

        return Deployment::query()->create(array_merge([
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'deployed_from' => '2026-03-02',
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Satu pembacaan, DITULIS LANGSUNG ke register.
     *
     * Sengaja tidak lewat EquipmentLogService: penjaga monotonnya menolak
     * angka yang turun DI DALAM satu mobilisasi, sementara justru pembacaan
     * yang turun — meter diganti antar-mobilisasi, atau satu digit salah
     * ketik yang lolos dari mobilisasi yang lain — adalah kejadian yang
     * definisi "pembacaan tertinggi" ada untuk dihadapi. Uji yang hanya bisa
     * membuat data yang sudah dijaga tidak menguji penjaganya.
     */
    private function log(Deployment $deployment, string $date, ?float $hourMeter, ?float $fuel = null): EquipmentLog
    {
        return EquipmentLog::query()->create([
            'deployment_id' => $deployment->id,
            'log_date' => $date,
            'hour_meter' => $hourMeter,
            'fuel_liters' => $fuel,
            'logged_by' => $this->clerk(),
        ]);
    }

    private function clerk(): int
    {
        $this->clerk ??= User::query()->create([
            'name' => 'Agus Prasetyo',
            'email' => 'agus@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);

        return (int) $this->clerk->id;
    }

    private function maintenance(Asset $asset, string $date, ?float $hourTarget, ?string $dateTarget = null): Maintenance
    {
        return Maintenance::query()->create([
            'asset_id' => $asset->id,
            'maintenance_date' => $date,
            'maintenance_type' => 'service_rutin',
            'cost' => 0,
            'next_due_date' => $dateTarget,
            'next_due_hour_meter' => $hourTarget,
        ]);
    }

    private function row(Asset $asset): ?array
    {
        return $this->service->forAsset($asset);
    }

    // ------------------------------------------------- perangkap B: turun

    /**
     * SATU DIGIT YANG SALAH KETIK TIDAK BOLEH MENDIAMKAN SERVIS YANG SUDAH
     * LEWAT.
     *
     * Mesin di 5.120 jam sudah melewati target 5.000. Operator berikutnya
     * mengetik 512 (satu digit hilang). Kalau yang dihakimi adalah pembacaan
     * TERBARU, alatnya berubah menjadi "masih 4.488 jam lagi" dan alarmnya
     * mati justru pada alat yang paling perlu dilihat. Yang dihakimi adalah
     * pembacaan TERTINGGI: meter tidak berjalan mundur.
     */
    public function test_a_reading_that_drops_never_silences_an_overdue_service(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 5120);
        $this->log($deployment, '2026-07-20', 512); // salah ketik
        $this->maintenance($asset, '2026-05-01', 5000);

        $row = $this->row($asset);

        $this->assertSame(5120.0, $row['reading']);
        $this->assertSame(512.0, $row['latest_reading']);
        $this->assertTrue($row['meter_went_backwards']);
        $this->assertSame(WatchedThresholds::LAMPAU, $row['state']);
        $this->assertStringContainsString('pembacaan TERAKHIR', $row['note']);
        $this->assertStringContainsString('5.120', $row['note']);
    }

    /**
     * METER YANG BERHENTI DI ANGKA YANG SAMA (alat menganggur sebulan):
     * tanggal yang dipulangkan adalah TERAKHIR KALI angka itu terbaca, bukan
     * pertama kali. "Tertinggi sejak 1 Jul" pada alat yang meterannya dibaca
     * lagi 31 Jul membuat pembacanya mengira register itu berhenti diisi.
     */
    public function test_a_repeated_high_reading_is_dated_by_its_latest_sighting(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 4800);
        $this->log($deployment, '2026-07-31', 4800);
        $this->maintenance($asset, '2026-05-01', 5000);

        $row = $this->row($asset);

        $this->assertSame(4800.0, $row['reading']);
        $this->assertSame('2026-07-31', $row['reading_date']);
        $this->assertFalse($row['meter_went_backwards']);
    }

    /**
     * Meter yang naik: yang tertinggi DAN yang terakhir adalah baris yang
     * sama, dan tidak ada peringatan meter mundur yang dikarang.
     */
    public function test_a_rising_meter_reports_one_reading_and_no_backwards_warning(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 3240);
        $this->log($deployment, '2026-07-31', 3375.5);
        $this->maintenance($asset, '2026-05-01', 5000);

        $row = $this->row($asset);

        $this->assertSame(3375.5, $row['reading']);
        $this->assertSame('2026-07-31', $row['reading_date']);
        $this->assertFalse($row['meter_went_backwards']);
        $this->assertStringNotContainsString('TERAKHIR', $row['note']);
    }

    // --------------------------------------- perangkap D: baris yang berlaku

    /**
     * KARTU SERVIS TERBARU MENGGANTIKAN RENCANA SEBELUMNYA — baris yang sama
     * yang dibaca pemicu tanggal (latest_per_group).
     */
    public function test_the_newest_maintenance_row_supplies_the_hour_target(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 4800);
        $this->maintenance($asset, '2026-01-10', 4500); // sudah lewat, digantikan
        $newest = $this->maintenance($asset, '2026-06-14', 5000);

        $row = $this->row($asset);

        $this->assertSame(5000.0, $row['next_due_hour_meter']);
        $this->assertSame($newest->code, $row['maintenance_code']);
        $this->assertSame(WatchedThresholds::AMAN, $row['state']);
    }

    /**
     * DAN KALAU YANG TERBARU LUPA MENGISI TARGETNYA, ALATNYA MENJADI "BATAS
     * BELUM DISETEL" — bukan diam-diam mewarisi target kartu yang lalu.
     *
     * Pola alarm_when_date_missing milik pemicu tanggal, diterjemahkan ke jam:
     * satu kolom yang lupa diisi harus terlihat. Mewarisi 4.500 dari kartu
     * Januari akan membuat alat ini berbunyi "sudah lewat 300 jam" atas
     * sebuah target yang mekaniknya sudah mengganti.
     */
    public function test_a_newest_maintenance_without_an_hour_target_is_tanpa_batas_not_an_inherited_one(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 4800);
        $this->maintenance($asset, '2026-01-10', 4500);
        $newest = $this->maintenance($asset, '2026-06-14', null);

        $row = $this->row($asset);

        $this->assertNull($row['next_due_hour_meter']);
        $this->assertSame(WatchedThresholds::TANPA_BATAS, $row['state']);
        $this->assertStringContainsString($newest->code.' belum menyebut target jam', $row['note']);
    }

    /** Baris perawatan yang DIHAPUS tidak menggantikan apa pun. */
    public function test_a_soft_deleted_newest_maintenance_does_not_supersede_the_live_one(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 4800);
        $live = $this->maintenance($asset, '2026-06-14', 5000);
        $this->maintenance($asset, '2026-07-20', null)->delete();

        $row = $this->row($asset);

        $this->assertSame($live->code, $row['maintenance_code']);
        $this->assertSame(5000.0, $row['next_due_hour_meter']);
    }

    // ------------------------------------ perangkap A: tiga cara tidak terukur

    /** Sebab 1 — alat belum pernah dimobilisasi, jadi tidak ada yang menampung log. */
    public function test_an_asset_never_deployed_is_unmeasured_with_its_own_sentence(): void
    {
        $asset = $this->asset();
        $this->maintenance($asset, '2026-06-14', 5000);

        $row = $this->row($asset);

        $this->assertNull($row['reading']);
        $this->assertSame(WatchedThresholds::TIDAK_TERUKUR, $row['state']);
        $this->assertSame(MaintenanceDueService::BELUM_DIMOBILISASI, $row['unmeasured_reason']);
        $this->assertStringContainsString('belum pernah dimobilisasi', $row['note']);
    }

    /** Sebab 2 — mobilisasinya ada, satu log pun belum ditulis. */
    public function test_a_deployed_asset_without_logs_is_unmeasured_with_its_own_sentence(): void
    {
        $asset = $this->asset();
        $this->deployment($asset);
        $this->maintenance($asset, '2026-06-14', 5000);

        $row = $this->row($asset);

        $this->assertNull($row['reading']);
        $this->assertSame(MaintenanceDueService::TANPA_LOG, $row['unmeasured_reason']);
        $this->assertStringContainsString('1 mobilisasi tercatat', $row['note']);
        $this->assertStringContainsString('belum ada satu log', $row['note']);
    }

    /**
     * Sebab 3 — LOGNYA ADA, hour_meter-nya NULL pada semuanya. Kolomnya
     * nullable justru karena mengisi solar tanpa mencatat jam kerja adalah
     * kejadian biasa di lapangan.
     */
    public function test_logs_without_a_single_hour_meter_figure_are_unmeasured_with_their_own_sentence(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', null, 200);
        $this->log($deployment, '2026-07-05', null, 180);
        $this->maintenance($asset, '2026-06-14', 5000);

        $row = $this->row($asset);

        $this->assertNull($row['reading']);
        $this->assertSame(MaintenanceDueService::LOG_TANPA_JAM, $row['unmeasured_reason']);
        $this->assertStringContainsString('2 log tercatat', $row['note']);
        $this->assertStringContainsString('tidak satu pun mengisi hour meter', $row['note']);
    }

    /**
     * DAN NOL JAM ADALAH SEBUAH PEMBACAAN. Mesin baru yang meterannya masih
     * nol TERUKUR — dan jaraknya ke target 250 jam adalah 250, bukan "tidak
     * ada yang tahu".
     */
    public function test_a_zero_hour_reading_is_a_reading_not_an_absence(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 0);
        $this->maintenance($asset, '2026-06-14', 250);

        $row = $this->row($asset);

        $this->assertSame(0.0, $row['reading']);
        $this->assertNull($row['unmeasured_reason']);
        $this->assertSame(WatchedThresholds::AMAN, $row['state']);
        $this->assertSame(250.0, $row['remaining_hours']);
    }

    // ------------------------------------------------------ ambang peringatan

    /**
     * AMBANGNYA JARAK, BUKAN PERSENTASE — dan angkanya ditulis di sini.
     *
     * Margin 50 jam (disetel di setUp): 4.950 tepat pada ambang MENDEKATI,
     * 4.949 masih AMAN, 5.000 LAMPAU. Kalau ambangnya dihitung persen, 4.949
     * dari 5.000 adalah 98,98 % dan ketiganya akan "Mendekati batas".
     */
    public function test_the_warning_starts_a_fixed_number_of_hours_before_the_target(): void
    {
        $states = [];

        foreach ([4949.0, 4950.0, 5000.0] as $index => $reading) {
            $asset = $this->asset(['code' => 'AST-900'.$index]);
            $deployment = $this->deployment($asset);
            $this->log($deployment, '2026-07-01', $reading);
            $this->maintenance($asset, '2026-06-14', 5000);
            $states[(string) $reading] = $this->row($asset)['state'];
        }

        $this->assertSame(WatchedThresholds::AMAN, $states['4949']);
        $this->assertSame(WatchedThresholds::MENDEKATI, $states['4950']);
        $this->assertSame(WatchedThresholds::LAMPAU, $states['5000']);
    }

    /**
     * Angka yang dikirim paket ini kepada pemilik, dipaku pada nilainya
     * sendiri: 50 jam. Uji di atas menyetel config-nya, jadi tanpa baris ini
     * bawaan produksi bisa berubah menjadi apa pun tanpa satu uji pun merah.
     */
    public function test_the_shipped_warning_margin_is_fifty_hours(): void
    {
        // Berkas config DIBACA DARI DISK, bukan lewat config() yang setUp
        // sudah menimpanya: yang dipaku adalah angka yang benar-benar dikirim.
        $shipped = require config_path('erp.php');
        $this->assertSame(50, $shipped['thresholds']['maintenance_hour_meter']);

        // …dan bawaan kelasnya sendiri, dipakai saat config tidak menyebutnya.
        config(['erp.thresholds.maintenance_hour_meter' => null]);
        $this->assertSame(50.0, $this->service->warnMarginHours());
    }

    // ------------------------------------------------- perangkap E: dilepas

    /**
     * ASET YANG DILEPAS KELUAR DARI PENGAWASAN — DARI KEDUA PEMICU.
     *
     * Cacat yang berulang di kampanye ini adalah aturan yang benar di satu
     * pemicu dan bocor di pemicu kedua, jadi ujinya menanyai KEDUANYA atas
     * satu aset yang sama: pengawas tanggal (WatchedDeadlines) dan baris
     * registri jam (MaintenanceDueService).
     */
    public function test_a_disposed_asset_drops_out_of_both_triggers(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 5200);
        $this->maintenance($asset, '2026-06-14', 5000, '2026-01-01');

        $entry = collect(WatchedDeadlines::entries())->firstWhere('key', 'maintenance_next_due');

        $this->assertNotNull($this->row($asset), 'prasyarat: sebelum dilepas, alat ini diawasi kedua pemicu');
        $this->assertSame(1, WatchedDeadlines::scoped($entry)->count());

        $asset->update(['status' => 'disposed']);

        $this->assertNull($this->row($asset));
        $this->assertSame(0, WatchedDeadlines::scoped($entry)->count());
    }

    /** Aset yang dihapus lunak keluar juga. */
    public function test_a_soft_deleted_asset_is_not_watched(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 5200);
        $this->maintenance($asset, '2026-06-14', 5000);

        $this->assertNotNull($this->row($asset));

        $asset->delete();

        $this->assertSame([], $this->service->rows());
    }

    /**
     * Pembacaan milik mobilisasi yang DIHAPUS tidak boleh kembali lewat pintu
     * belakang — sikap yang sama dengan Asset::equipmentLogs dan kartu aset.
     */
    public function test_readings_on_a_deleted_deployment_do_not_judge_a_service(): void
    {
        $asset = $this->asset();
        $deleted = $this->deployment($asset);
        $this->log($deleted, '2026-07-01', 5200);
        $deleted->delete();
        $this->maintenance($asset, '2026-06-14', 5000);

        $row = $this->row($asset);

        $this->assertNull($row['reading']);
        $this->assertSame(WatchedThresholds::TIDAK_TERUKUR, $row['state']);
    }

    // ------------------------------------------------------ siapa masuk daftar

    /**
     * Alat yang tidak punya target DAN tidak punya pembacaan bukan alat
     * berjam: scaffolding dan rak server tidak boleh membanjiri registri
     * sebagai "belum terukur" selamanya (aturan "masih urusan seseorang").
     */
    public function test_an_asset_with_neither_target_nor_reading_is_not_listed(): void
    {
        $scaffolding = $this->asset(['code' => 'AST-0004', 'name' => 'Scaffolding Set Ringlock 400 m2']);
        $this->maintenance($scaffolding, '2026-06-14', null, '2026-12-14');

        $this->assertNull($this->row($scaffolding));
        $this->assertSame([], $this->service->rows());
    }

    /** Sebaliknya: pembacaan tanpa target MASUK, sebagai "batas belum disetel". */
    public function test_readings_without_a_target_are_listed_as_tanpa_batas(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-31', 3375.5);

        $row = $this->row($asset);

        $this->assertSame(WatchedThresholds::TANPA_BATAS, $row['state']);
        $this->assertStringContainsString('belum ada catatan perawatan', $row['note']);
    }

    // -------------------------------------------------------- pintu tulisnya

    /**
     * NOL BUKAN "BELUM DISETEL", DAN SERVIS PADA JAM KE-0 TIDAK BERARTI APA
     * PUN. Menerima 0 akan melahirkan keadaan "Tidak dianggarkan" di sisi jam
     * — kalimat yang di sisi rupiah berarti "sebuah dokumen yang disetujui
     * menyebut Rp 0" dan di sini tidak berarti apa-apa. Yang berarti belum
     * disetel adalah NULL, dan NULL diterima.
     */
    public function test_the_write_door_refuses_a_zero_hour_target_but_accepts_none(): void
    {
        $asset = $this->asset();
        $this->actingAs($this->adminUser());

        $this->postJson('/api/assets/maintenances', [
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-06-14',
            'maintenance_type' => 'service_rutin',
            'cost' => 0,
            'next_due_hour_meter' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('next_due_hour_meter');

        $this->postJson('/api/assets/maintenances', [
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-06-14',
            'maintenance_type' => 'service_rutin',
            'cost' => 0,
            'next_due_hour_meter' => null,
        ])->assertStatus(201)->assertJsonPath('data.next_due_hour_meter', null);
    }

    /**
     * DAN SEBUAH KARTU SERVIS BOLEH MENJADWALKAN DENGAN JAM SAJA — tanpa
     * tanggal. Alat berat memang dirawat begitu; kalau pintu tulisnya
     * mewajibkan tanggal, seluruh paket ini hanya bisa dipakai oleh orang yang
     * mengarang satu.
     */
    public function test_a_service_can_be_scheduled_by_hours_alone_and_reads_back(): void
    {
        $asset = $this->asset();
        $this->actingAs($this->adminUser());

        $created = $this->postJson('/api/assets/maintenances', [
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-06-14',
            'maintenance_type' => 'service_rutin',
            'cost' => 1_500_000,
            'next_due_hour_meter' => 5000,
        ])->assertStatus(201)->json('data');

        // (float): JSON menuliskan 5000.0 sebagai 5000; yang diuji adalah
        // angka yang sampai ke layar, bukan tipe PHP-nya.
        $this->assertSame(5000.0, (float) $created['next_due_hour_meter']);
        $this->assertNull($created['next_due_date']);

        $this->assertSame(5000.0, (float) $this->getJson('/api/assets/maintenances/'.$created['id'])
            ->assertOk()
            ->json('data.next_due_hour_meter'));
    }

    /** Kartu aset membawa keadaan jamnya — endpoint yang dibaca layar detail. */
    public function test_the_asset_history_endpoint_carries_the_hour_meter_due_block(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 4960);
        $this->maintenance($asset, '2026-06-14', 5000, '2026-12-14');
        $this->actingAs($this->adminUser());

        $due = $this->getJson('/api/assets/assets/'.$asset->id.'/history')
            ->assertOk()
            ->json('data.hour_meter_due');

        $this->assertSame(4960.0, (float) $due['reading']);
        $this->assertSame(5000.0, (float) $due['next_due_hour_meter']);
        $this->assertSame(40.0, (float) $due['remaining_hours']);
        $this->assertSame('2026-12-14', $due['next_due_date']);
        $this->assertSame(WatchedThresholds::MENDEKATI, $due['state']);
    }

    /** Alat yang bukan alat berjam tidak membawa blok itu sama sekali. */
    public function test_a_non_hour_metered_asset_carries_no_due_block(): void
    {
        $asset = $this->asset(['name' => 'Server Rack 42U + UPS 10kVA']);
        $this->actingAs($this->adminUser());

        $this->getJson('/api/assets/assets/'.$asset->id.'/history')
            ->assertOk()
            ->assertJsonPath('data.hour_meter_due', null);
    }

    // ----------------------------------------------- perangkap C: dua pemicu

    /**
     * SEBUAH ALAT BISA LEWAT SERVIS MENURUT JAM SEMENTARA TANGGALNYA MASIH
     * JAUH — dan barisnya membawa keduanya, jadi tidak ada layar yang bisa
     * menampilkan satu tanpa yang lain.
     */
    public function test_a_row_carries_the_date_trigger_beside_the_hour_trigger(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->log($deployment, '2026-07-01', 5200);
        $this->maintenance($asset, '2026-06-14', 5000, '2026-12-14');

        $row = $this->row($asset);

        $this->assertSame(WatchedThresholds::LAMPAU, $row['state']);
        $this->assertSame('2026-12-14', $row['next_due_date']);
        $this->assertStringContainsString('pemicu tanggal: 14 Des 2026', $row['note']);
    }
}
