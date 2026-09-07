<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Services\BaselineService;
use Modules\Projects\Services\EvmService;
use Modules\Projects\Services\ProjectService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Tab "Jadwal" (P1-H) — apa yang HARUS dijamin server supaya gantt baca-saja di
 * `public/app/js/views/jadwal.js` menggambar jadwal yang benar.
 *
 * Gambarnya digambar di peramban, jadi berkas ini tidak menguji piksel; yang
 * diuji adalah muatan yang dibaca layar itu, dan setiap anggapan yang
 * disandarinya. Sisi peramban diukur harness S26_gantt.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * SATU HAL YANG MENOPANG SELURUH LAYAR: BASELINE DICOCOKKAN LEWAT `wbs_code`.
 *
 * `prj_baseline_tasks.wbs_task_id` ada, tetapi ia menggantung. Diukur di sini
 * (test pertama, satu panggilan `generateWbsFromBoq` yang sungguhan): sesudah
 * "Buat WBS dari BOQ" ditekan SEKALI, 0 dari 11 id beku menunjuk baris WBS yang
 * masih ada sementara 11 dari 11 `wbs_code` cocok — angka yang sama bentuknya
 * dengan berkas demo yang dikirim repositori ini (id beku 12–22, id hidup
 * 34–44, yang berarti tombol itu ditekan dua kali lagi sesudahnya).
 *
 * Maka mencocokkan lewat id bukan "kurang aman": ia menghasilkan gantt TANPA
 * SATU PUN bar pembanding pada data yang ada, dan kaki kartunya akan
 * mengumumkan "0 dari 11 tugas cocok" untuk sebuah baseline yang disetujui dan
 * lengkap. Angka yang salah, diumumkan dengan yakin.
 * ────────────────────────────────────────────────────────────────────────────
 */
class JadwalGanttTest extends ErpTestCase
{
    use BaselineFixtures;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->project = $this->grahaProject();
    }

    // ------------------------------------------------ invarian `wbs_code`

    /**
     * Angka yang ditulis docblock jadwal.js, diukur ulang di sini terhadap
     * layanan yang sungguhan — bukan dihafal.
     */
    public function test_a_regenerated_wbs_leaves_every_frozen_id_dangling_while_every_wbs_code_still_matches(): void
    {
        $baseline = $this->freeze();

        $before = $this->project->refresh()->wbsTasks()->pluck('id', 'wbs_code')->all();
        $this->assertSame(
            $baseline->tasks()->pluck('wbs_task_id', 'wbs_code')->all(),
            $before,
            'Baseline dibekukan dari WBS yang sedang hidup, jadi sebelum apa pun terjadi setiap id beku '
            .'MEMANG menunjuk tugasnya sendiri — itulah yang membuat pencocokan lewat id terlihat aman.',
        );

        // "Buat WBS dari BOQ" — sekali. Layanannya MENGHAPUS seluruh WBS lalu
        // membuatnya ulang, jadi setiap baris hidup memakai id baru.
        app(ProjectService::class)->generateWbsFromBoq($this->project->refresh());

        $live = $this->project->refresh()->wbsTasks()->get();
        $frozen = $baseline->tasks()->get();
        $liveById = $live->keyBy('id');
        $liveByCode = $live->keyBy('wbs_code');

        $this->assertCount(11, $frozen);
        $this->assertCount(11, $live);
        $this->assertSame(0, $frozen->filter(fn ($row): bool => $liveById->has($row->wbs_task_id))->count(),
            'Sebuah id beku masih menemukan tugas hidup; kalau ini pernah terjadi, angka 0/11 yang '
            .'ditulis docblock jadwal.js dan laporan paketnya sudah tidak benar lagi.');
        $this->assertSame(11, $frozen->filter(fn ($row): bool => $liveByCode->has($row->wbs_code))->count());

        // …dan id yang lama tidak dipakai ulang, ia hanya ditinggalkan.
        $this->assertEmpty(array_intersect(array_values($before), $live->pluck('id')->all()));
    }

    /**
     * Dua pencocok yang sama dijalankan atas MUATAN yang sungguhan (dua endpoint
     * yang dipanggil layar), supaya yang dibandingkan adalah apa yang benar-benar
     * sampai ke peramban.
     */
    public function test_matching_the_frozen_baseline_by_id_would_draw_a_gantt_with_no_baseline_bar_at_all(): void
    {
        $baseline = $this->freeze();
        app(ProjectService::class)->generateWbsFromBoq($this->project->refresh());

        $admin = $this->adminUser();
        $tree = $this->actingAs($admin)
            ->getJson("/api/projects/{$this->project->id}/wbs-tasks")->assertOk()->json('data');
        $frozen = $this->actingAs($admin)
            ->getJson("/api/projects/baselines/{$baseline->id}")->assertOk()->json('data.tasks');

        $rows = $this->flatten($tree);
        $byId = collect($frozen)->keyBy('wbs_task_id');
        $byCode = collect($frozen)->keyBy('wbs_code');

        $matchedById = collect($rows)->filter(fn (array $row): bool => $byId->has($row['id']))->count();
        $matchedByCode = collect($rows)->filter(fn (array $row): bool => $byCode->has($row['wbs_code']))->count();

        $this->assertCount(11, $rows);
        $this->assertSame(0, $matchedById);
        $this->assertSame(11, $matchedByCode);

        // Dan bar pembandingnya memang punya tanggal untuk digambar.
        $b3 = $byCode->get('B.3');
        $this->assertSame('2026-02-23', $b3['planned_start']);
        $this->assertSame('2026-10-15', $b3['planned_end']);
    }

    /**
     * Sisi yang lain dari invarian yang sama, dan satu-satunya tempat di aplikasi
     * ini yang masih memakai id: `EvmService::physicalProgress` mencoba
     * `wbs_task_id` DULU lalu jatuh ke `wbs_code`.
     *
     * Tidak ada FK pada kolom itu, tidak ada yang memvalidasinya, dan relasinya
     * tidak dibatasi per proyek — jadi tidak ada apa pun di basis data yang
     * mencegah sebuah id beku menunjuk tugas hidup yang BUKAN tugas itu. Kalau
     * itu terjadi, EV dihitung dari progres tugas yang salah dan tidak ada satu
     * kalimat pun yang mengatakannya: uang yang salah, dan diam.
     *
     * Yang dipaku di sini bukan "jangan pernah terjadi" (tidak bisa dijamin
     * tanpa FK) melainkan "jangan pernah diam".
     */
    public function test_a_frozen_id_pointing_at_a_task_with_another_code_is_named_instead_of_silently_compared(): void
    {
        $baseline = $this->freeze();

        // Keadaan yang DIIZINKAN skema: id beku B.3 dipindahkan ke baris hidup
        // C.1. Satu UPDATE, tanpa satu pun batasan yang menolaknya.
        $c1 = $this->project->wbsTasks()->where('wbs_code', 'C.1')->firstOrFail();
        DB::table('prj_baseline_tasks')
            ->where('baseline_id', $baseline->id)->where('wbs_code', 'B.3')
            ->update(['wbs_task_id' => $c1->id]);

        $report = app(EvmService::class)->report($this->project->refresh());
        $warnings = implode(' | ', $report['warnings']);

        $this->assertStringContainsString('B.3', $warnings);
        $this->assertStringContainsString('C.1', $warnings);
        $this->assertMatchesRegularExpression('/kode WBS.*berbeda|berbeda.*kode WBS/i', $warnings);
    }

    // ------------------------------------------------ bentuk muatan pohon

    /**
     * Impor MPP-XML menerima OutlineLevel sedalam apa pun (hanya lompatan lebih
     * dari satu tingkat yang ditolak), tetapi endpoint pohon dulu memuat
     * `children.children` saja — TIGA tingkat. Tugas tingkat empat tidak
     * digambar gantt, tidak muncul di tabel WBS tab Ringkasan, dan induknya di
     * tingkat tiga tampil sebagai DAUN lengkap dengan tombol "Perbarui" yang
     * pasti ditolak server ("progress is entered on leaf tasks").
     *
     * Sebuah jadwal yang diam-diam kehilangan paket pekerjaan adalah jadwal
     * karangan, dan ia terlihat persis seperti jadwal yang benar.
     */
    public function test_the_wbs_tree_carries_every_level_a_schedule_import_can_create(): void
    {
        $b3 = $this->project->wbsTasks()->where('wbs_code', 'B.3')->firstOrFail();
        $level4 = $this->addTask('B.3.1', 'Pembesian lantai 1–4', $b3->id);
        $this->addTask('B.3.1.1', 'Pembesian kolom lantai 1', $level4->id);

        $tree = $this->actingAs($this->adminUser())
            ->getJson("/api/projects/{$this->project->id}/wbs-tasks")->assertOk()->json('data');

        $rows = $this->flatten($tree);

        $this->assertSame(
            $this->project->wbsTasks()->count(),
            count($rows),
            'Pohon yang dikirim lebih pendek daripada WBS yang tersimpan: gantt menggambar tugas yang '
            .'diterimanya, jadi baris yang hilang di sini adalah paket pekerjaan yang hilang di jadwal.',
        );
        $this->assertContains('B.3.1.1', array_column($rows, 'wbs_code'));
        $this->assertSame(3, collect($rows)->firstWhere('wbs_code', 'B.3.1.1')['level']);

        // …dan setiap baris membawa kunci `children`, supaya "tidak punya anak"
        // dan "anaknya tidak dimuat" tidak lagi terlihat sama di klien: baris
        // B.3.1 PUNYA anak, dan tombol progres tidak boleh ditawarkan padanya.
        foreach ($rows as $row) {
            $this->assertArrayHasKey('children', $row['raw'], "Baris {$row['wbs_code']} tanpa kunci children.");
        }
        $this->assertCount(1, collect($rows)->firstWhere('wbs_code', 'B.3.1')['raw']['children']);
        $this->assertSame([], collect($rows)->firstWhere('wbs_code', 'B.3.1.1')['raw']['children']);
    }

    /**
     * Pintu KEDUA ke pohon yang sama — `GET projects/{project}` — dulu membawa
     * kedua cacat yang uji di atas menutup, di tempat yang tidak dilihat siapa
     * pun: `rootWbsTasks.children` mengirim 11 dari 13 baris (B.3.1 dan
     * B.3.1.1 hilang) dan setiap simpul tingkat satu dikirim TANPA kunci
     * `children`, termasuk yang punya anak.
     *
     * Dan rutenya berjalan di bawah `auth:sanctum` saja: sesudah 4df5959 peran
     * tanpa satu pun izin `prj.*` mendapat 403 dari `{project}/wbs-tasks` dan
     * tetap 200 di sini, lengkap dengan kode, uraian, bobot, tanggal rencana
     * dan progres setiap paket pekerjaan — persis daftar medan yang gerbang itu
     * dipasang untuk menutupi. Muatan proyek karena itu tidak lagi membawa
     * pohonnya sama sekali: satu pintu, yang utuh dan yang bergerbang.
     */
    public function test_the_project_payload_carries_no_second_copy_of_the_wbs_tree(): void
    {
        $b3 = $this->project->wbsTasks()->where('wbs_code', 'B.3')->firstOrFail();
        $this->addTask('B.3.1', 'Pembesian lantai 1–4', $b3->id);

        $payload = $this->actingAs($this->adminUser())
            ->getJson("/api/projects/{$this->project->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('wbs_tasks', $payload);
        $this->assertSame([], array_values(array_filter(
            array_keys($payload),
            fn (string $key): bool => str_contains($key, 'wbs'),
        )), 'Muatan proyek membawa medan WBS lagi — pintu kedua ke pohon itu terbuka kembali.');

        // Peran tanpa satu pun izin `prj.*` boleh membaca kepala proyeknya
        // (29 GET Projects lain juga masih terbuka — keputusan modul Projects),
        // tetapi tidak boleh lagi ikut membawa pulang seluruh WBS-nya.
        $stranger = $this->userWithoutProjectAccess();
        $leaked = $this->actingAs($stranger)
            ->getJson("/api/projects/{$this->project->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('wbs_tasks', $leaked);
        $this->actingAs($stranger)
            ->getJson("/api/projects/{$this->project->id}/wbs-tasks")->assertForbidden();
    }

    /**
     * Tugas tanpa tanggal adalah keadaan yang sah (POST wbs-tasks menerima
     * keduanya null), dan gantt menggambarnya sebagai bar terbuka / baris
     * "tanpa tanggal". Yang tidak boleh dilakukan server adalah menambalnya
     * dengan tanggal proyek: sebuah bar yang membentang sepanjang proyek adalah
     * rencana yang tidak pernah dibuat siapa pun.
     */
    public function test_a_task_without_planned_dates_reaches_the_screen_as_null(): void
    {
        $b = $this->project->wbsTasks()->where('wbs_code', 'B')->firstOrFail();
        $this->addTask('B.5', 'Pekerjaan tambah belum dijadwalkan', $b->id, start: null, end: null);
        $this->addTask('B.6', 'Pembongkaran bekisting (selesai belum ditetapkan)', $b->id, start: '2026-05-01', end: null);

        $rows = collect($this->flatten($this->actingAs($this->adminUser())
            ->getJson("/api/projects/{$this->project->id}/wbs-tasks")->assertOk()->json('data')));

        $none = $rows->firstWhere('wbs_code', 'B.5');
        $this->assertNull($none['raw']['planned_start']);
        $this->assertNull($none['raw']['planned_end']);

        $open = $rows->firstWhere('wbs_code', 'B.6');
        $this->assertSame('2026-05-01', $open['raw']['planned_start']);
        $this->assertNull($open['raw']['planned_end']);
    }

    /**
     * SATUAN. `progress_pct` berjalan di kawat sebagai 0..100 dan sebagai STRING
     * ('60.0000'); `charts.js ganttChart` menerima 0..1, jadi jadwal.js
     * membaginya 100. Kalau server suatu hari mengirim 0..1, setiap bar terbaca
     * 1 % dan tidak ada yang gagal — karena itu skalanya dipaku di sini.
     *
     * Dan nilai di luar rentang TIDAK dijepit di jalur baca: gantt menjepit
     * barnya sendiri lalu menulis "(di luar 0–100 %)" di <title>-nya, yang hanya
     * mungkin kalau angka aslinya sampai ke peramban.
     */
    public function test_progress_travels_as_zero_to_one_hundred_and_is_never_clamped_on_the_way_out(): void
    {
        DB::table('prj_wbs_tasks')->where('project_id', $this->project->id)
            ->where('wbs_code', 'B.2')->update(['progress_pct' => 105.5]);

        $rows = collect($this->flatten($this->actingAs($this->adminUser())
            ->getJson("/api/projects/{$this->project->id}/wbs-tasks")->assertOk()->json('data')));

        // B.3 = 60 % pada fixture: 60, bukan 0,6.
        $this->assertSame('60.0000', $rows->firstWhere('wbs_code', 'B.3')['raw']['progress_pct']);
        $this->assertSame('105.5000', $rows->firstWhere('wbs_code', 'B.2')['raw']['progress_pct']);
    }

    // -------------------------------------------- baseline yang tak lengkap

    /**
     * Baris beku yang tugas hidupnya sudah tidak ada = lingkup yang DIHAPUS
     * sesudah rencana disepakati, dan itulah satu hal paling penting yang bisa
     * dilaporkan sebuah laporan deviasi. Baris itu harus tetap ikut, dan harus
     * MENGATAKAN dirinya hilang.
     *
     * `live_exists` dulu `null` untuk baris itu, bukan `false` — Laravel
     * memulangkan null tanpa memanggil closure `whenLoaded` ketika relasinya
     * dimuat TETAPI kosong. evm.js:1145 menguji `task.live_exists === false`,
     * jadi coretan "tugas dihapus" tidak pernah tergambar sekali pun, dan
     * docblock resource-nya menjanjikan keselamatan yang tidak diberikannya.
     */
    public function test_a_frozen_row_whose_live_task_is_gone_says_so_with_false_not_null(): void
    {
        $baseline = $this->freeze();
        $this->project->wbsTasks()->where('wbs_code', 'C.2')->delete();

        $tasks = collect($this->actingAs($this->adminUser())
            ->getJson("/api/projects/baselines/{$baseline->id}")->assertOk()->json('data.tasks'));

        $gone = $tasks->firstWhere('wbs_code', 'C.2');
        $this->assertNotNull($gone, 'Baris beku ikut hilang bersama tugasnya — bukti deviasinya terhapus.');
        $this->assertFalse($gone['live_exists']);
        $this->assertNull($gone['live_progress_pct']);

        $alive = $tasks->firstWhere('wbs_code', 'B.3');
        $this->assertTrue($alive['live_exists']);
        $this->assertSame('60.0000', $alive['live_progress_pct']);
    }

    /**
     * Uji di atas membekukan baseline lalu langsung bertanya — keadaan
     * id-masih-utuh, yang paket ini sendiri buktikan TIDAK PERNAH bertahan.
     * Yang normal adalah keadaan di bawah ini: satu kali "Buat WBS dari BOQ"
     * sesudah pembekuan, dan setiap id beku menggantung.
     *
     * Sampai verifikasi P1-H, muatan `show` memuat `tasks.liveTask` — relasi
     * ID — jadi keadaan normal itu memulangkan `live_exists: false` untuk
     * SELURUH isi baseline dan kartu "Isi beku" di layar EVM mencoret setiap
     * barisnya dengan "sudah tidak ada di WBS — bobotnya dihitung nol",
     * sementara EVM di layar sebelahnya menghitung nilai perolehan justru DARI
     * tugas-tugas itu (`tasks_removed: []`). Satu layar mengatakan lingkupnya
     * dihapus, layar sebelahnya menghitung uang dari lingkup yang sama.
     */
    public function test_every_frozen_row_still_finds_its_live_task_after_the_wbs_was_regenerated(): void
    {
        $baseline = $this->freeze();
        app(ProjectService::class)->generateWbsFromBoq($this->project->refresh());

        // Prasyarat yang membuat uji ini berarti: id-nya memang menggantung.
        $liveIds = $this->project->refresh()->wbsTasks()->pluck('id')->all();
        $frozenIds = $baseline->tasks()->whereNotNull('wbs_task_id')->pluck('wbs_task_id')->all();
        $this->assertNotEmpty($frozenIds);
        $this->assertSame([], array_intersect($frozenIds, $liveIds),
            'Prasyarat uji hilang: masih ada id beku yang menunjuk baris hidup.');

        $tasks = collect($this->actingAs($this->adminUser())
            ->getJson("/api/projects/baselines/{$baseline->id}")->assertOk()->json('data.tasks'));

        $this->assertSame([], $tasks->where('live_exists', false)->pluck('wbs_code')->all(),
            'Baris beku yang tugasnya MASIH ADA (kodenya cocok) dikirim sebagai "sudah tidak ada di WBS".');
        $this->assertSame(['code'], $tasks->pluck('live_matched_by')->unique()->values()->all());

        // Progresnya datang dari tugas hidup yang benar, bukan dari null.
        $progress = $this->project->wbsTasks()->pluck('progress_pct', 'wbs_code');
        foreach ($tasks as $task) {
            $this->assertSame((string) $progress[$task['wbs_code']], (string) $task['live_progress_pct']);
            $this->assertSame($task['wbs_code'], $task['live_wbs_code']);
        }
    }

    /**
     * Sisi id dari aturan yang sama: id beku menang (kode yang DIGANTI NAMA
     * masih tugas yang sama), tetapi baris yang progresnya datang dari tugas
     * berkode lain harus MENGATAKANNYA — di layar EVM kalimat itulah yang
     * membedakan "progres saya" dari "progres tugas sebelah".
     */
    public function test_a_frozen_row_whose_id_points_at_another_code_says_where_its_progress_came_from(): void
    {
        $baseline = $this->freeze();
        $c1 = $this->project->wbsTasks()->where('wbs_code', 'C.1')->firstOrFail();
        DB::table('prj_baseline_tasks')
            ->where('baseline_id', $baseline->id)->where('wbs_code', 'B.3')
            ->update(['wbs_task_id' => $c1->id]);

        $row = collect($this->actingAs($this->adminUser())
            ->getJson("/api/projects/baselines/{$baseline->id}")->assertOk()->json('data.tasks'))
            ->firstWhere('wbs_code', 'B.3');

        $this->assertTrue($row['live_exists']);
        $this->assertSame('id', $row['live_matched_by']);
        $this->assertSame('C.1', $row['live_wbs_code']);
        $this->assertSame((string) $c1->progress_pct, (string) $row['live_progress_pct']);

        $evm = $this->spa('views/evm.js');
        $this->assertStringContainsString('task.live_wbs_code !== task.wbs_code', $evm,
            'Layar EVM tidak lagi membandingkan kode hidup dengan kode beku, jadi baris silang kembali diam.');
    }

    /**
     * Baseline yang belum ada bukan galat: proyek yang belum dibekukan tetap
     * punya jadwal, dan yang hilang hanya bar pembandingnya. Endpoint yang
     * dipanggil layar harus memulangkan daftar KOSONG dengan 200 — jadwal.js
     * membaca `current[0]` dan menggambar tanpa baseline.
     */
    public function test_a_project_with_no_approved_baseline_answers_an_empty_current_list(): void
    {
        $admin = $this->adminUser();
        $path = "/api/projects/baselines?project_id={$this->project->id}&current=1&per_page=1";

        $this->assertSame([], $this->actingAs($admin)->getJson($path)->assertOk()->json('data'));

        // Baseline yang masih draft juga belum jadi pembanding: yang dibekukan
        // adalah yang DISETUJUI, dan `current=1` menyaringnya.
        $this->makeRapMirroringWbs();
        $draft = app(BaselineService::class)->snapshot($this->project->refresh(), ['effective_date' => '2026-02-02']);
        $this->assertSame([], $this->actingAs($admin)->getJson($path)->assertOk()->json('data'));

        // Revisi 1 wajib beralasan — BaselineService menolak revisi tanpa sebab.
        $approved = $this->freeze(['reason' => 'Addendum waktu pelaksanaan.']);
        $current = $this->actingAs($admin)->getJson($path)->assertOk()->json('data');
        $this->assertCount(1, $current);
        $this->assertSame($approved->id, $current[0]['id']);
        $this->assertNotSame($draft->id, $current[0]['id']);
    }

    // ------------------------------------------------------------- gerbang

    /**
     * Gerbang layar Jadwal ada di app.js (`session.can('prj.view')`), dan sampai
     * paket ini gerbang itu HANYA di peramban: GET
     * `projects/{project}/wbs-tasks` berjalan di bawah `auth:sanctum` saja, jadi
     * siapa pun yang punya token bisa membaca seluruh WBS setiap proyek — kode,
     * uraian, bobot, tanggal rencana dan progres — dengan satu permintaan.
     * `projects/baselines` di sebelahnya sudah menuntut `prj.view`, jadi
     * separuh jadwalnya dijaga dan separuhnya tidak.
     *
     * Peran demo `finance` dipakai sebagai penguji: RoleSeeder tidak memberinya
     * satu pun izin `prj.*`.
     */
    public function test_the_two_endpoints_the_schedule_reads_both_demand_prj_view(): void
    {
        $stranger = $this->userWithoutProjectAccess();

        $this->actingAs($stranger)
            ->getJson("/api/projects/{$this->project->id}/wbs-tasks")->assertForbidden();
        $this->actingAs($stranger)
            ->getJson("/api/projects/baselines?project_id={$this->project->id}&current=1&per_page=1")
            ->assertForbidden();

        // Dua tetangga di layar yang sama, dan lubang yang sama.
        $this->actingAs($stranger)->getJson("/api/projects/{$this->project->id}/s-curve")->assertForbidden();
        $this->actingAs($stranger)->getJson("/api/projects/{$this->project->id}/dashboard")->assertForbidden();

        // Gudang MEMEGANG prj.view (RoleSeeder), dan tidak boleh ikut terkunci.
        $storeman = $this->userWith('prj.view', 'Gudang');
        $this->actingAs($storeman)
            ->getJson("/api/projects/{$this->project->id}/wbs-tasks")->assertOk();
    }

    // ------------------------------------------------------ pin SPA ↔ server

    /**
     * Pin SPA↔server, dengan grep — sama seperti CrossModuleSpaWiringTest,
     * karena tidak ada runtime JS di host ini dan grep membaca berkas yang sama
     * dengan yang dibaca peninjau.
     *
     * P1-H tidak menambah satu endpoint pun; seluruh layarnya berdiri di atas
     * dua endpoint yang sudah ada. Sebuah endpoint yang diubah namanya di server
     * tanpa menyentuh jadwal.js akan memberi tab yang selamanya "Memuat…".
     */
    public function test_the_schedule_tab_calls_only_endpoints_that_exist(): void
    {
        $jadwal = $this->spa('views/jadwal.js');

        foreach (['projects/${id}/wbs-tasks', "projects/baselines'", 'projects/baselines/${head.id}'] as $needle) {
            $this->assertStringContainsString($needle, $jadwal, "jadwal.js tidak lagi memanggil {$needle}.");
        }

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())->all();

        $this->assertContains('api/projects/{project}/wbs-tasks', $routes);
        $this->assertContains('api/projects/baselines', $routes);
        $this->assertContains('api/projects/baselines/{baseline}', $routes);

        // Medan yang DIBACA jadwal.js, dipaku pada muatan yang sungguhan.
        $baseline = $this->freeze();
        $admin = $this->adminUser();

        $tree = $this->actingAs($admin)
            ->getJson("/api/projects/{$this->project->id}/wbs-tasks")->assertOk()->json('data');
        foreach (['wbs_code', 'name', 'planned_start', 'planned_end', 'progress_pct', 'children'] as $field) {
            $this->assertArrayHasKey($field, $tree[0], "Muatan pohon WBS kehilangan `{$field}`.");
        }

        $show = $this->actingAs($admin)
            ->getJson("/api/projects/baselines/{$baseline->id}")->assertOk()->json('data');
        $this->assertArrayHasKey('code', $show);
        $this->assertArrayHasKey('tasks', $show);
        foreach (['wbs_code', 'planned_start', 'planned_end'] as $field) {
            $this->assertArrayHasKey($field, $show['tasks'][0], "Muatan baseline kehilangan `{$field}`.");
        }

        $index = $this->actingAs($admin)
            ->getJson("/api/projects/baselines?project_id={$this->project->id}&current=1&per_page=1")
            ->assertOk()->json('data');
        $this->assertArrayHasKey('id', $index[0], 'jadwal.js memakai current[0].id untuk mengambil baseline penuhnya.');
    }

    /**
     * Pelajaran 7 September 2026, dijadikan uji: `views/jadwal.js` sempat
     * dikeluarkan dari sebuah merge dengan alasan "yatim, belum terpasang rute".
     * Ia memang tidak punya rute sendiri — `views/project.js` yang mengimpornya
     * — dan satu modul yang 404 menjatuhkan SELURUH graf modul ES: /app/ berhenti
     * pada pemuatnya. Situsnya padam sampai berkasnya dikembalikan.
     */
    public function test_the_schedule_module_is_reachable_only_through_the_project_screen_and_that_import_is_pinned(): void
    {
        $this->assertFileExists(public_path('app/js/views/jadwal.js'));

        $project = $this->spa('views/project.js');

        $this->assertStringContainsString("import { renderJadwal } from './jadwal.js'", $project,
            'project.js tidak lagi mengimpor jadwal.js — kalau impornya dibuang, berkasnya menjadi '
            .'yatim sungguhan; kalau BERKASNYA yang dibuang, /app/ padam seluruhnya.');
        $this->assertStringContainsString("key: 'jadwal'", $project, 'Tab "Jadwal" tidak lagi terdaftar.');
        $this->assertStringContainsString('renderJadwal(pane', $project, 'Tab "Jadwal" terdaftar tetapi tidak memanggil apa pun.');
    }

    // ------------------------------------------------------------ pembantu

    /** Snapshot → submit → approve, oleh dua orang berbeda. */
    private function freeze(array $data = []): ProjectBaseline
    {
        $this->makeRapMirroringWbs();

        $maker = $this->userWith('prj.create', 'Perencana');
        $checker = $this->userWith('prj.approve', 'Direktur');
        $service = app(BaselineService::class);

        $baseline = $service->snapshot($this->project->refresh(), array_merge([
            'effective_date' => '2026-02-02',
        ], $data), $maker);
        $service->submit($baseline, $maker);

        return $service->approve($baseline, $checker);
    }

    /**
     * BOQ yang bagian dan itemnya persis mencerminkan WBS fixture, supaya
     * `generateWbsFromBoq` membangun ulang pohon dengan KODE yang sama dan id
     * yang baru — jalur yang sesungguhnya, bukan penghapusan buatan tangan.
     */
    private function makeRapMirroringWbs(): int
    {
        if ($this->project->boq_id !== null) {
            return (int) $this->project->boq_id;
        }

        $boqId = DB::table('est_boqs')->insertGetId([
            'code' => 'BOQ/2026/0001', 'title' => 'BOQ '.$this->project->code,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sections = [];
        foreach (['A' => 'Pekerjaan Persiapan', 'B' => 'Pekerjaan Struktur', 'C' => 'Pekerjaan Arsitektur & MEP'] as $no => $name) {
            $sections[$no] = DB::table('est_boq_sections')->insertGetId([
                'boq_id' => $boqId, 'section_no' => $no, 'name' => $name,
                'sort_order' => count($sections) + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $boqItemId = null;
        foreach (self::LEAVES as $index => [$code, $weight]) {
            $amount = round($weight / 100 * self::RAP_TOTAL, 2);
            $id = DB::table('est_boq_items')->insertGetId([
                'boq_id' => $boqId, 'section_id' => $sections[explode('.', $code)[0]],
                'wbs_code' => $code, 'description' => 'Pekerjaan '.$code, 'qty' => 1, 'unit' => 'ls',
                'unit_price' => $amount, 'amount' => $amount, 'sort_order' => $index + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $boqItemId ??= $id;
        }

        $rapId = DB::table('est_cost_budgets')->insertGetId([
            'code' => 'RAP/2026/0001', 'boq_id' => $boqId, 'project_id' => $this->project->id,
            'target_margin_pct' => 13, 'total_budget' => self::RAP_TOTAL, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (self::RAP_CATEGORIES as $category => $amount) {
            DB::table('est_cost_budget_items')->insert([
                'cost_budget_id' => $rapId, 'boq_item_id' => $boqItemId, 'cost_category' => $category,
                'description' => 'Anggaran '.$category, 'qty' => 1, 'unit' => 'ls',
                'unit_price' => $amount, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->project->forceFill(['boq_id' => $boqId])->save();
        $this->project->refresh();

        return $rapId;
    }

    private function addTask(string $code, string $name, ?int $parentId, ?string $start = '2026-03-02', ?string $end = '2026-04-30')
    {
        return $this->project->wbsTasks()->create([
            'parent_id' => $parentId,
            'wbs_code' => $code,
            'name' => $name,
            'weight_pct' => 0,
            'planned_start' => $start,
            'planned_end' => $end,
            'progress_pct' => 0,
            'sort_order' => 9,
        ]);
    }

    /** Pohon bersarang → daftar rata, cara yang sama dengan `flatten()` jadwal.js. */
    private function flatten(array $nodes, int $level = 0, array &$out = []): array
    {
        foreach ($nodes as $node) {
            $out[] = ['id' => $node['id'], 'wbs_code' => $node['wbs_code'], 'level' => $level, 'raw' => $node];
            $this->flatten($node['children'] ?? [], $level + 1, $out);
        }

        return $out;
    }

    private function spa(string $relative): string
    {
        $path = public_path('app/js/'.$relative);
        $this->assertFileExists($path, "public/app/js/{$relative} hilang.");

        return (string) file_get_contents($path);
    }

    /** Peran demo tanpa satu pun izin `prj.*` (RoleSeeder: finance). */
    private function userWithoutProjectAccess(): User
    {
        $role = Role::findOrCreate('finance-tanpa-prj', 'web');
        $role->syncPermissions(['fin.view', 'crm.view']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Keuangan', 'email' => 'keuangan-'.str()->random(6).'@nusantara.test',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
