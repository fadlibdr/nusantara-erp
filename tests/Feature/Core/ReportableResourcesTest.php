<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\ReportableResources;
use Modules\Core\Support\SpaEnums;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Tests\ErpTestCase;

/**
 * Registri Laporan Bebas dipaku pada dunia di sekelilingnya (Fase 1 / P1-F).
 *
 * ROADMAP menuliskan syaratnya "kolom = kolom layar daftar — DIPAKU UJI", dan
 * berkas ini adalah paku itu. Ia memaku empat hal, dan keempatnya bisa salah
 * tanpa satu galat pun di mana pun:
 *
 *  1. **Kolom.** Kunci `columns` registri sama DAN seurutan dengan `columns[]`
 *     layar daftarnya di schema.js. Kolom yang ditambahkan ke layar tanpa
 *     diputuskan di registri menjatuhkan uji ini — alih-alih diam-diam menjadi
 *     kolom yang tidak pernah bisa dilaporkan.
 *  2. **Skema.** Setiap `select` benar-benar kolom tabelnya, dan `soft_deletes`
 *     benar-benar mencerminkan ada-tidaknya `deleted_at`. Menebak salah berarti
 *     laporan menghitung dokumen yang sudah dibuang, atau 500.
 *  3. **Izin.** Setiap izin benar-benar dicetak PermissionSeeder. Salah ketik
 *     `finance.view` tidak melempar apa pun: entri hanya menjadi tak terlihat
 *     bagi SEMUA orang termasuk admin, tanpa satu baris log.
 *  4. **Jendela tanggal.** `date_column` sama dengan `dateColumn:` yang
 *     dideklarasikan controller layar itu — dibaca dari `meta.date_column`
 *     endpoint-nya sendiri, bukan dari salinan tangan.
 *
 * Ditambah bagian yang MENOLAK dan penjaga anti-no-op, seperti saudara-
 * saudaranya: sebuah regex yang berhenti cocok harus menjatuhkan uji, bukan
 * melaporkan PASS atas daftar kosong.
 */
class ReportableResourcesTest extends ErpTestCase
{
    public function test_the_catalogue_is_the_eight_the_owner_decided(): void
    {
        // Keputusan pemilik ledger #4: "8 resource". Angkanya literal di sini,
        // bukan dibaca dari registri yang sedang diuji.
        $this->assertCount(8, ReportableResources::keys());

        foreach (ReportableResources::keys() as $key) {
            $this->assertMatchesRegularExpression('#^[a-z][a-z0-9/-]*$#', $key,
                "Kunci resource [{$key}] bukan kunci RESOURCES schema.js.");
        }
    }

    /**
     * Kunci kolom registri = kunci kolom layar daftar, SAMA dan SEURUTAN.
     *
     * Kesetaraan berurutan, bukan sekadar himpunan: urutan registri adalah
     * urutan yang dilihat orang di pemilih kolom, dan "kolomnya sama tapi
     * acak" bukan cermin layar daftarnya.
     */
    public function test_every_entry_mirrors_its_list_screen_columns_in_order(): void
    {
        $checked = 0;

        foreach (ReportableResources::entries() as $key => $entry) {
            $screen = $this->screenColumns($key);

            $this->assertNotSame([], $screen, "Kolom layar untuk [{$key}] tidak terbaca dari schema.js — pemindaian ini kehilangan sasarannya.");
            $this->assertSame(
                $screen,
                array_keys($entry['columns']),
                sprintf(
                    'Kolom registri untuk [%s] tidak sama (atau tidak seurutan) dengan kolom layar daftarnya. '
                    .'Setiap kolom layar harus punya nasibnya di registri: dipetakan, digantikan, atau DITOLAK '
                    .'dengan why_not — tidak ada nasib keempat, dan tidak ada kolom yang hilang diam-diam.',
                    $key,
                ),
            );

            $checked++;
        }

        $this->assertSame(8, $checked);
    }

    /** Kolom yang tidak punya `select` WAJIB menjelaskan dirinya. */
    public function test_a_refused_column_always_carries_its_reason(): void
    {
        $refused = 0;

        foreach (ReportableResources::entries() as $key => $entry) {
            foreach ($entry['columns'] as $columnKey => $column) {
                if (isset($column['select'])) {
                    continue;
                }

                $refused++;
                $this->assertArrayHasKey('why_not', $column,
                    "Kolom [{$key}.{$columnKey}] ditolak tanpa alasan; orangnya akan mencarinya di pemilih kolom dan tidak menemukan apa pun.");
                $this->assertGreaterThan(40, strlen($column['why_not']),
                    "Alasan penolakan [{$key}.{$columnKey}] terlalu pendek untuk menjelaskan apa pun.");
            }
        }

        $this->assertGreaterThan(0, $refused,
            'Tidak ada satu pun kolom yang ditolak — mustahil, karena 59 dari 90 layar memuat kolom yang tidak bisa dilayani satu kueri.');
    }

    /** Setiap `select` adalah kolom tabelnya yang sungguhan. */
    public function test_every_selected_column_exists_on_its_table(): void
    {
        foreach (ReportableResources::entries() as $key => $entry) {
            $this->assertTrue(Schema::hasTable($entry['table']), "Tabel [{$entry['table']}] untuk [{$key}] tidak ada.");

            foreach ($entry['columns'] as $columnKey => $column) {
                if (! isset($column['select'])) {
                    continue;
                }

                $this->assertTrue(
                    Schema::hasColumn($entry['table'], $column['select']),
                    "Kolom [{$key}.{$columnKey}] menunjuk [{$entry['table']}.{$column['select']}] yang tidak ada.",
                );
            }

            foreach ($entry['filters'] as $filterKey => $filter) {
                $this->assertTrue(
                    Schema::hasColumn($entry['table'], $filter['column']),
                    "Saringan [{$key}.{$filterKey}] menunjuk kolom yang tidak ada.",
                );
            }
        }
    }

    /**
     * `soft_deletes` mencerminkan skema hidup.
     *
     * true yang salah adalah 500; false yang salah adalah laporan yang
     * menghitung dokumen yang sudah dibuang lalu berselisih dengan layar
     * daftar yang diklaimnya cermin — diam-diam, dan selamanya.
     */
    public function test_soft_deletes_matches_the_live_schema(): void
    {
        $withColumn = 0;

        foreach (ReportableResources::entries() as $key => $entry) {
            $hasColumn = Schema::hasColumn($entry['table'], 'deleted_at');
            $withColumn += $hasColumn ? 1 : 0;

            $this->assertSame($hasColumn, $entry['soft_deletes'],
                "Entri [{$key}] mengaku soft_deletes=".var_export($entry['soft_deletes'], true)
                ." sementara {$entry['table']} ".($hasColumn ? 'punya' : 'tidak punya').' kolom deleted_at.');
        }

        // Bagian yang menolak: bila SEMUA delapan sama, uji ini tidak
        // membedakan apa pun. Tepat satu dari delapan memang tanpa deleted_at.
        $this->assertSame(7, $withColumn,
            'Tepat tujuh dari delapan tabel katalog punya deleted_at; kalau angkanya berubah, salah satu entri perlu diputuskan ulang.');
    }

    /** Setiap izin yang disebut registri benar-benar dicetak PermissionSeeder. */
    public function test_every_permission_named_by_the_catalogue_exists(): void
    {
        $known = PermissionSeeder::expected();

        foreach (ReportableResources::entries() as $key => $entry) {
            $this->assertNotSame([], $entry['permission'], "Entri [{$key}] tanpa izin sama sekali.");

            foreach ($entry['permission'] as $permission) {
                $this->assertContains($permission, $known,
                    "Entri [{$key}] menuntut izin [{$permission}] yang tidak pernah dicetak PermissionSeeder — "
                    .'entri itu tidak akan pernah terlihat oleh siapa pun, termasuk admin.');
            }
        }
    }

    /**
     * `date_column` sama dengan yang dideklarasikan layar daftarnya sendiri,
     * dibaca dari `meta.date_column` endpoint-nya — bukan dari salinan tangan.
     */
    public function test_the_date_window_matches_the_list_screen_it_mirrors(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = $this->adminUser();
        $this->actingAs($admin, 'sanctum');

        foreach (ReportableResources::entries() as $key => $entry) {
            $api = $this->screenApiPath($key);
            $response = $this->getJson("/api/{$api}?per_page=1");

            $this->assertSame(200, $response->status(), "Endpoint daftar [{$api}] tidak dapat dibaca admin.");
            $this->assertSame(
                $entry['date_column'],
                $response->json('meta.date_column'),
                "Jendela tanggal [{$key}] berbeda dari yang dideklarasikan controller layarnya.",
            );
        }
    }

    /** Katalog menyaring dirinya per izin, dan hilang seluruhnya tanpa sesi. */
    public function test_the_catalogue_filters_itself_by_permission(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->assertSame([], ReportableResources::for(null));

        $finance = $this->userWithPermissions(['fin.view']);
        $keys = array_keys(ReportableResources::for($finance));

        $this->assertContains('finance/ar-invoices', $keys);
        $this->assertContains('finance/project-costs', $keys);
        $this->assertNotContains('hr/employees', $keys, 'Pemegang fin.view tidak boleh melihat katalog SDM.');

        $none = $this->userWithPermissions(['core.view']);
        $this->assertSame([], ReportableResources::for($none),
            'Katalog untuk peran tanpa satu pun izin sumber harus KOSONG, bukan berisi entri yang gagal saat dijalankan.');
    }

    /**
     * Setiap kolom berjenis enum ATAU status membawa `enum`, dan enum itu ada
     * di `public/app/js/enums.js`.
     *
     * Temuan verifikasi P1-F, ditemukan lima lensa secara terpisah: ketujuh
     * kolom `status` katalog semula tanpa `enum`, sehingga mengelompokkan
     * menurut Status menuliskan token basis data mentah ('approved',
     * 'available') di layar, di CSV DAN di XLSX — di tempat layar daftarnya
     * menuliskan 'Disetujui' dan 'Tersedia'. Layar daftar mendapatkannya dari
     * `status_label` yang dikirim kelas Resource; laporan tidak lewat Resource
     * sama sekali, jadi satu-satunya sumbernya adalah `enum` di sini.
     */
    public function test_every_enum_and_status_column_names_an_enum_that_exists(): void
    {
        $enums = SpaEnums::all();
        $this->assertGreaterThan(50, count($enums), 'enums.js tidak terbaca — uji ini tidak membuktikan apa pun.');

        $checked = 0;

        foreach (ReportableResources::entries() as $key => $entry) {
            foreach ($entry['columns'] as $columnKey => $column) {
                if (! in_array($column['type'], ['enum', 'status'], true)) {
                    continue;
                }

                $checked++;
                $this->assertArrayHasKey('enum', $column,
                    "Kolom [{$key}.{$columnKey}] berjenis {$column['type']} tanpa `enum`: laporan akan menuliskan "
                    .'token basis data mentah di tempat layar daftarnya menuliskan labelnya.');
                $this->assertNotSame([], SpaEnums::labels($column['enum']),
                    "Enum [{$column['enum']}] pada [{$key}.{$columnKey}] tidak ada di enums.js.");
            }

            foreach ($entry['filters'] as $filterKey => $filter) {
                if (($filter['enum'] ?? null) === null) {
                    continue;
                }

                $this->assertNotSame([], SpaEnums::labels($filter['enum']),
                    "Enum saringan [{$filter['enum']}] pada [{$key}.{$filterKey}] tidak ada di enums.js.");
            }
        }

        $this->assertGreaterThan(10, $checked, 'Terlalu sedikit kolom berjenis enum/status yang diperiksa.');
    }

    /**
     * Katalog membedakan "bisa dicetak" dari "bisa dikelompokkan".
     *
     * Temuan verifikasi P1-F (blocking): pemilih kolom mode rincian dulu
     * menonaktifkan setiap kolom yang punya `why_not`, padahal `why_not`
     * menjelaskan kenapa sebuah kolom tidak bisa menjadi DIMENSI atau UKURAN —
     * bukan kenapa ia tidak bisa dicetak. Akibatnya setiap kolom Kode, Nama dan
     * Keterangan di seluruh katalog mati di pemilihnya.
     */
    public function test_the_catalogue_separates_printable_from_groupable(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->actingAs($this->adminUser(), 'sanctum');

        $payload = $this->getJson('/api/core/reports/resources')->assertOk()->json('data');

        $bothSelectableAndRefusedAsDimension = 0;

        foreach ($payload as $resource) {
            foreach ($resource['columns'] as $column) {
                $this->assertArrayHasKey('selectable', $column,
                    "Katalog tidak menyatakan `selectable` untuk [{$resource['key']}.{$column['key']}].");

                if ($column['selectable'] && $column['why_not'] !== null) {
                    $bothSelectableAndRefusedAsDimension++;
                }

                if (! $column['selectable']) {
                    $this->assertNotNull($column['why_not'],
                        "Kolom [{$resource['key']}.{$column['key']}] tidak bisa dipilih dan tidak menjelaskan kenapa.");
                }
            }
        }

        $this->assertGreaterThan(10, $bothSelectableAndRefusedAsDimension,
            'Tidak ada satu pun kolom yang bisa DICETAK tetapi tidak bisa DIKELOMPOKKAN — mustahil, karena setiap '
            .'kolom kode dan nama di katalog persis begitu. Kedua sifat itu sedang disamakan lagi.');
    }

    /* ------------------------------------------------------------ perkakas */

    /**
     * Pengguna dengan izin persis ini. Tidak ada helper bersama untuk bentuk
     * ini di ErpTestCase (yang ada hanya adminUser), dan menambah satu di sana
     * akan mengubah kelas dasar untuk 3.900 uji demi satu berkas.
     *
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): \App\Models\User
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $role = \Spatie\Permission\Models\Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        /** @var \App\Models\User $user */
        $user = \App\Models\User::query()->create([
            'name' => 'Pengguna uji',
            'email' => substr(md5(implode('|', $permissions).microtime()), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Kunci kolom layar daftar sebuah resource, dari schema.js, dalam urutan
     * layar. `codeColumn`/`statusColumn` adalah const bersama di berkas itu.
     *
     * @return list<string>
     */
    private function screenColumns(string $key): array
    {
        $source = (string) file_get_contents(public_path('app/js/schema.js'));
        $start = strpos($source, "  '{$key}': {");

        if ($start === false) {
            return [];
        }

        $block = substr($source, $start, strpos($source, "\n  },", $start) - $start);
        $columnsAt = strpos($block, 'columns: [');

        if ($columnsAt === false) {
            return [];
        }

        $columns = substr($block, $columnsAt, strpos($block, "\n    ],", $columnsAt) - $columnsAt);
        $out = [];

        foreach (explode("\n", $columns) as $line) {
            $line = trim($line);

            if ($line === 'codeColumn,') {
                $out[] = 'code';
            } elseif ($line === 'statusColumn,') {
                $out[] = 'status';
            } elseif (preg_match("/^\{ key: '([^']+)'/", $line, $found) === 1) {
                $out[] = $found[1];
            }
        }

        return $out;
    }

    /** `api:` sebuah entri RESOURCES — jalur endpoint daftarnya. */
    private function screenApiPath(string $key): string
    {
        $source = (string) file_get_contents(public_path('app/js/schema.js'));
        $start = strpos($source, "  '{$key}': {");
        $block = substr($source, $start, 400);

        preg_match("/api: '([^']+)'/", $block, $found);

        return $found[1];
    }
}
