<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Core\Models\SavedReport;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Laporan Bebas yang disimpan dan dibagikan per peran (Fase 1 / P1-F).
 *
 * Tiga aturan diuji di sini, dan ketiganya adalah aturan yang BELUM PERNAH ADA
 * di sistem ini sebelum paket ini — jadi tidak ada preseden yang menjaganya:
 *
 *  - **Hanya pemilik yang mengubah**, tanpa jalan pintas admin, dan
 *    penolakannya 422 yang menyebut pemiliknya dan jalan keluarnya.
 *  - **Berbagi per peran tidak memberi akses baru.** Sebuah laporan Keuangan
 *    yang dibagikan ke seluruh kantor tetap tak terlihat oleh yang tidak
 *    memegang fin.view — kalau tidak, berbagi menjadi cara memberi izin.
 *  - **Peran yang basi TERLIHAT.** Nama peran adalah satu-satunya rujukan
 *    peran di seluruh basis data ini, dan peran boleh diganti namanya; sebuah
 *    berbagi yang diam-diam berhenti bekerja adalah fitur rusak tanpa ada yang
 *    tahu.
 */
class SavedReportTest extends ErpTestCase
{
    private function userWith(string $roleName, array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pengguna '.$roleName,
            'email' => strtolower($roleName).'-'.substr(md5(microtime()), 0, 6).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
            'resource' => 'finance/project-costs',
            'mode' => 'group',
            'row' => ['column' => 'cost_category'],
            'measure' => ['agg' => 'sum', 'column' => 'amount'],
        ];
    }

    public function test_a_saved_report_round_trips_and_names_its_owner(): void
    {
        $finance = $this->userWith('finance', ['fin.view']);
        $this->actingAs($finance, 'sanctum');

        $created = $this->postJson('/api/core/reports/saved', [
            'name' => 'Biaya per kategori',
            'definition' => $this->definition(),
        ])->assertStatus(201);

        $created->assertJsonPath('data.name', 'Biaya per kategori')
            ->assertJsonPath('data.resource', 'finance/project-costs')
            ->assertJsonPath('data.resource_label', 'Biaya Proyek')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.shared_roles', []);

        $this->getJson('/api/core/reports/saved')->assertOk()->assertJsonCount(1, 'data');
    }

    /** Definisi yang tidak sah ditolak SAAT DISIMPAN, bukan saat dijalankan. */
    public function test_a_saved_report_validates_its_definition_on_write(): void
    {
        $this->actingAs($this->userWith('finance', ['fin.view']), 'sanctum');

        $this->postJson('/api/core/reports/saved', [
            'name' => 'Rusak',
            'definition' => ['resource' => 'finance/ar-invoices', 'mode' => 'group',
                'row' => ['column' => 'status'], 'measure' => ['agg' => 'sum', 'column' => 'outstanding']],
        ])->assertStatus(422)
            ->assertJsonPath('errors.definition.0', fn ($m) => str_contains((string) $m, 'outstanding'));

        $this->assertSame(0, SavedReport::query()->count());
    }

    /**
     * Hanya pemilik yang mengubah — dan penolakannya menyebut pemiliknya, dan
     * jalan keluarnya.
     */
    public function test_only_the_owner_may_edit_and_the_refusal_says_what_to_do_instead(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $other = $this->userWith('finance-manager', ['fin.view']);

        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/core/reports/saved', [
            'name' => 'Biaya per kategori',
            'definition' => $this->definition(),
            'shared_roles' => ['finance-manager'],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($other, 'sanctum');

        // Ia BOLEH membacanya…
        $this->getJson("/api/core/reports/saved/{$id}")->assertOk()->assertJsonPath('data.is_owner', false);

        // …dan tidak boleh mengubahnya. 422, bukan 403: ia ada di halaman yang benar.
        $message = (string) $this->putJson("/api/core/reports/saved/{$id}", ['name' => 'Punya saya sekarang'])
            ->assertStatus(422)->json('errors.owner.0');

        $this->assertStringContainsString('Biaya per kategori', $message);
        $this->assertStringContainsString($owner->name, $message);
        $this->assertStringContainsString('Simpan sebagai salinan', $message,
            'Penolakan harus menyebut jalan keluarnya, seperti assertCustodian menyebut apa yang harus dilakukan.');

        $this->deleteJson("/api/core/reports/saved/{$id}")->assertStatus(422);
        $this->assertSame('Biaya per kategori', SavedReport::query()->find($id)->name);
    }

    /** Tidak ada jalan pintas admin. */
    public function test_an_admin_may_not_edit_someone_elses_report_either(): void
    {
        // Admin dibuat LEBIH DULU: berbagi divalidasi terhadap peran yang
        // hidup saat ditulis, jadi peran 'admin' harus sudah ada sebelum
        // laporan yang dibagikan kepadanya disimpan.
        $admin = $this->adminUser();
        $owner = $this->userWith('finance', ['fin.view']);

        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/core/reports/saved', [
            'name' => 'Milik keuangan', 'definition' => $this->definition(), 'shared_roles' => ['admin'],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($admin, 'sanctum');

        $this->putJson("/api/core/reports/saved/{$id}", ['name' => 'Diubah admin'])->assertStatus(422);

        // …tetapi menyalinnya boleh, dan salinannya miliknya sendiri.
        $copy = $this->postJson("/api/core/reports/saved/{$id}/copy")->assertStatus(201);
        $copy->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.name', 'Milik keuangan (salinan)')
            // Salinan TIDAK mewarisi berbagi: menyalin bukan membagikan.
            ->assertJsonPath('data.shared_roles', []);
    }

    /**
     * Berbagi per peran TIDAK memberi akses yang tidak dipunyai orangnya.
     *
     * Ini aturan yang membuat berbagi aman: kalau ia salah, siapa pun bisa
     * memberi seluruh kantor akses ke angka keuangan dengan satu kotak centang.
     */
    public function test_sharing_never_grants_access_the_person_does_not_have(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $warehouse = $this->userWith('warehouse', ['inv.view']);

        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/core/reports/saved', [
            'name' => 'Biaya proyek', 'definition' => $this->definition(),
            'shared_roles' => ['warehouse'],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($warehouse, 'sanctum');

        $this->getJson('/api/core/reports/saved')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/core/reports/saved/{$id}")->assertStatus(404);
    }

    /** Laporan yang dibagikan sampai kepada peran yang MEMANG boleh membacanya. */
    public function test_a_shared_report_reaches_the_role_it_was_shared_with(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $manager = $this->userWith('finance-manager', ['fin.view']);

        $this->actingAs($owner, 'sanctum');
        $this->postJson('/api/core/reports/saved', [
            'name' => 'Biaya proyek', 'definition' => $this->definition(),
            'shared_roles' => ['finance-manager'],
        ])->assertStatus(201);

        $this->actingAs($manager, 'sanctum');
        $this->getJson('/api/core/reports/saved')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_owner', false)
            ->assertJsonPath('data.0.owner_name', $owner->name);
    }

    /** Berbagi ke peran yang tidak ada ditolak SAAT DITULIS. */
    public function test_sharing_with_a_role_that_does_not_exist_is_refused_by_name(): void
    {
        $this->actingAs($this->userWith('finance', ['fin.view']), 'sanctum');

        $this->postJson('/api/core/reports/saved', [
            'name' => 'Salah peran', 'definition' => $this->definition(),
            'shared_roles' => ['peran-karangan'],
        ])->assertStatus(422)
            ->assertJsonPath('errors.definition.0', fn ($m) => str_contains((string) $m, 'peran-karangan'));
    }

    /**
     * Peran yang diganti namanya SESUDAH berbagi ditandai, bukan disembunyikan.
     *
     * Nama peran adalah satu-satunya rujukan peran di seluruh basis data ini,
     * dan peran boleh diganti namanya lewat API. Sebuah berbagi yang diam-diam
     * berhenti bekerja adalah fitur rusak tanpa ada yang tahu.
     */
    public function test_a_renamed_role_leaves_a_visible_stale_share(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $this->userWith('finance-manager', ['fin.view']);

        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/core/reports/saved', [
            'name' => 'Biaya proyek', 'definition' => $this->definition(),
            'shared_roles' => ['finance-manager'],
        ])->assertStatus(201)->json('data.id');

        Role::query()->where('name', 'finance-manager')->update(['name' => 'manajer-keuangan']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson("/api/core/reports/saved/{$id}")->assertOk()
            ->assertJsonPath('data.shared_roles', ['finance-manager'])
            ->assertJsonPath('data.stale_roles', ['finance-manager']);
    }

    /** Dua laporan bernama sama di daftar satu orang tidak bisa dibedakan olehnya. */
    public function test_two_reports_of_one_person_may_not_share_a_name(): void
    {
        $this->actingAs($this->userWith('finance', ['fin.view']), 'sanctum');

        $this->postJson('/api/core/reports/saved', ['name' => 'Sama', 'definition' => $this->definition()])->assertStatus(201);
        $this->postJson('/api/core/reports/saved', ['name' => 'Sama', 'definition' => $this->definition()])->assertStatus(422);

        // …sementara orang LAIN boleh memakai nama yang sama.
        $this->actingAs($this->userWith('finance-manager', ['fin.view']), 'sanctum');
        $this->postJson('/api/core/reports/saved', ['name' => 'Sama', 'definition' => $this->definition()])->assertStatus(201);
    }

    /** Menyalin dua kali tidak menabrak namanya sendiri. */
    public function test_copying_twice_produces_two_distinct_names(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/core/reports/saved', ['name' => 'Asli', 'definition' => $this->definition()])
            ->assertStatus(201)->json('data.id');

        $this->postJson("/api/core/reports/saved/{$id}/copy")->assertStatus(201)->assertJsonPath('data.name', 'Asli (salinan)');
        $this->postJson("/api/core/reports/saved/{$id}/copy")->assertStatus(201)->assertJsonPath('data.name', 'Asli (salinan) 2');
    }

    /**
     * Sesudah izin sumbernya dicabut, pemiliknya masih MELIHAT barisnya —
     * dan itulah satu-satunya cara `canManage()` bisa dicapai dari layar.
     *
     * Putaran verifikasi pertama menambahkan `canManage()` supaya barisnya
     * tidak "tinggal selamanya tanpa satu pun cara membuangnya", tetapi
     * `visibleTo()` tetap menyaring baris SENDIRI lewat izin sumber: daftar
     * mengembalikan [], GET id menjawab 404, sementara PUT dan DELETE atas id
     * yang sama menjawab 200 — perbaikan yang tidak bisa dicapai dan tidak
     * punya satu uji pun (verifikasi kedua P1-F).
     */
    public function test_the_owner_still_sees_and_can_delete_his_row_after_the_source_permission_is_revoked(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $this->actingAs($owner, 'sanctum');

        $id = $this->postJson('/api/core/reports/saved', ['name' => 'Biaya saya', 'definition' => $this->definition()])
            ->assertStatus(201)->assertJsonPath('data.readable', true)->json('data.id');

        // Izin sumbernya dicabut — perannya tetap sama, isinya yang berubah.
        Role::findByName('finance', 'web')->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/core/reports/saved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            // …tetapi ANGKAnya tertutup, dan barisnya mengatakannya.
            ->assertJsonPath('data.0.readable', false);

        $this->getJson("/api/core/reports/saved/{$id}")->assertOk()->assertJsonPath('data.readable', false);

        // Menjalankan dan mengekspornya tetap tertutup: yang boleh dibuang
        // bukan yang boleh dibaca.
        $this->postJson('/api/core/reports/run', $this->definition())->assertStatus(403);
        $this->get("/api/core/reports/saved/{$id}/xlsx")->assertStatus(404);
        $this->postJson("/api/core/reports/saved/{$id}/copy")->assertStatus(404);

        // Dan jalan keluarnya benar-benar ada, dari daftar yang menampilkannya.
        $this->deleteJson("/api/core/reports/saved/{$id}")->assertOk();
        $this->getJson('/api/core/reports/saved')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * Baris orang lain TIDAK ikut terlihat: kelonggaran di atas hanya untuk
     * pemiliknya, dan berbagi tetap tidak memberi akses baru.
     */
    public function test_a_shared_row_still_disappears_when_the_reader_loses_the_source_permission(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $reader = $this->userWith('finance-manager', ['fin.view']);

        $this->actingAs($owner, 'sanctum');
        $this->postJson('/api/core/reports/saved', [
            'name' => 'Dibagikan', 'definition' => $this->definition(), 'shared_roles' => ['finance-manager'],
        ])->assertStatus(201);

        $this->actingAs($reader, 'sanctum');
        $this->getJson('/api/core/reports/saved')->assertOk()->assertJsonCount(1, 'data');

        Role::findByName('finance-manager', 'web')->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/core/reports/saved')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * Nama yang bukan teks ditolak dengan kalimat, bukan dengan 500.
     *
     * `trim((string) $input['name'])` atas sebuah array memicu 'Array to
     * string conversion' → ErrorException, dan `copy()` yang mengetik
     * parameternya `?string` memicu TypeError; keduanya lolos dari lengan
     * catch controller (`LogicException|InvalidArgumentException`) dan
     * mendarat sebagai 500 — satu-satunya bentuk salah di endpoint ini yang
     * tidak berkalimat (verifikasi kedua P1-F). Angka pun ditolak: sebuah
     * laporan bernama '12345' adalah cast yang berhasil diam-diam.
     */
    public function test_a_name_that_is_not_text_is_refused_with_a_sentence_not_a_500(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $this->actingAs($owner, 'sanctum');

        $id = $this->postJson('/api/core/reports/saved', ['name' => 'Asli', 'definition' => $this->definition()])
            ->assertStatus(201)->json('data.id');

        foreach ([[], ['x'], ['a' => 'b'], 12345, true] as $shape) {
            $this->postJson('/api/core/reports/saved', ['name' => $shape, 'definition' => $this->definition()])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Nama laporan harus berupa teks.');

            $this->putJson("/api/core/reports/saved/{$id}", ['name' => $shape, 'definition' => $this->definition()])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Nama laporan harus berupa teks.');

            $this->postJson("/api/core/reports/saved/{$id}/copy", ['name' => $shape])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Nama laporan harus berupa teks.');
        }

        // Dan nama yang memang teks tetap lewat — termasuk salinan tanpa nama.
        $this->postJson("/api/core/reports/saved/{$id}/copy")->assertStatus(201);
        $this->postJson("/api/core/reports/saved/{$id}/copy", ['name' => 'Salinan bernama'])
            ->assertStatus(201)->assertJsonPath('data.name', 'Salinan bernama');
    }

    /**
     * Definisi yang salah dilaporkan sebagai DEFINISI, bukan sebagai
     * kepemilikan.
     *
     * Temuan verifikasi P1-F: `InvalidArgumentException` MEWARISI
     * `LogicException` di PHP, jadi lengan `catch (LogicException)` yang
     * ditulis lebih dulu menelan setiap galat definisi dan melabelinya
     * `owner` — pemiliknya sendiri diberi tahu "hanya pemiliknya yang dapat
     * mengubah" ketika yang salah adalah nama kolomnya.
     */
    public function test_a_definition_error_on_update_is_labelled_as_a_definition_error(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $this->actingAs($owner, 'sanctum');

        $id = $this->postJson('/api/core/reports/saved', [
            'name' => 'Milik saya', 'definition' => $this->definition(),
        ])->assertStatus(201)->json('data.id');

        $response = $this->putJson("/api/core/reports/saved/{$id}", [
            'definition' => ['resource' => 'finance/project-costs', 'mode' => 'group',
                'row' => ['column' => 'kolom-karangan'], 'measure' => ['agg' => 'count']],
        ])->assertStatus(422);

        $response->assertJsonPath('errors.definition.0', fn ($m) => str_contains((string) $m, 'kolom-karangan'));
        $this->assertNull($response->json('errors.owner'),
            'Galat definisi dilabeli kepemilikan: urutan catch-nya terbalik dan lengan kedua tidak pernah tercapai.');
    }

    /**
     * Izin sumber diperiksa saat MENULIS, bukan hanya saat membaca.
     *
     * Temuan verifikasi P1-F: tanpa ini seseorang bisa menyimpan laporan atas
     * sumber yang tidak boleh ia lihat — barisnya lalu tak terlihat olehnya
     * tetapi TETAP ADA, dan membagikannya ke peran yang memegang izin itu
     * berarti ia menyusun laporan atas data yang tidak pernah boleh ia sentuh.
     */
    public function test_saving_a_report_for_a_resource_you_cannot_read_is_refused(): void
    {
        $warehouse = $this->userWith('warehouse', ['inv.view']);
        $this->actingAs($warehouse, 'sanctum');

        $this->postJson('/api/core/reports/saved', [
            'name' => 'Biaya proyek', 'definition' => $this->definition(),
        ])->assertStatus(422)
            ->assertJsonPath('errors.definition.0', fn ($m) => str_contains((string) $m, 'fin.view'));

        $this->assertSame(0, SavedReport::query()->count());
    }

    /**
     * Laporan yang TERSEMBUNYI dan laporan yang TIDAK ADA menjawab hal yang
     * sama, kata demi kata.
     *
     * Temuan verifikasi P1-F: binding rute implisit menjawab id yang tidak ada
     * dengan pesan Laravel sendiri, sementara laporan yang ada tetapi bukan
     * hak pemanggil dijawab kalimat kami — dan dua 404 yang berbeda bunyinya
     * adalah cara menghitung laporan milik orang lain.
     */
    public function test_a_hidden_report_and_a_missing_one_answer_identically(): void
    {
        $owner = $this->userWith('finance', ['fin.view']);
        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/core/reports/saved', [
            'name' => 'Rahasia', 'definition' => $this->definition(),
        ])->assertStatus(201)->json('data.id');

        $stranger = $this->userWith('warehouse', ['inv.view']);
        $this->actingAs($stranger, 'sanctum');

        $hidden = $this->getJson("/api/core/reports/saved/{$id}")->assertStatus(404);
        $missing = $this->getJson('/api/core/reports/saved/999999')->assertStatus(404);

        $this->assertSame($hidden->json('message'), $missing->json('message'));
        $this->assertSame('Laporan tidak ditemukan.', $hidden->json('message'));
    }

    public function test_the_endpoints_require_a_session(): void
    {
        $this->getJson('/api/core/reports/saved')->assertStatus(401);
        $this->postJson('/api/core/reports/saved', [])->assertStatus(401);
    }
}
