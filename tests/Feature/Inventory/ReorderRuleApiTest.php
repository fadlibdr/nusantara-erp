<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Models\ReorderRule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * CRUD aturan reorder (F-6) — dan yang membedakannya dari master data biasa.
 *
 * Barisnya mengubah ARTI sebuah angka yang dibaca empat permukaan lain, jadi
 * dua hal diuji di sini yang tidak diuji pada gudang atau kategori item:
 *
 *  - pasangan kedua ditolak sebagai KALIMAT (422), bukan sebagai 500 dari
 *    QueryException. UNIQUE-nya ada di basis data dan itu yang menahan angka
 *    ubin launcher; yang diuji di sini adalah bahwa orangnya diberi tahu
 *    aturan mana yang sudah berdiri dan apa jalan keluarnya;
 *  - baris membawa min_stock item yang DIGANTIKANNYA, karena layar harus bisa
 *    mencetak keduanya berdampingan.
 */
class ReorderRuleApiTest extends ErpTestCase
{
    use InventoryFixtures;

    /**
     * Peran DIBERI NAMA oleh pemanggilnya, karena satu uji memakai dua peran
     * sekaligus: sebuah `Role::findOrCreate('penjaga-gudang')` yang dipakai
     * dua kali akan men-syncPermissions peran yang SAMA, sehingga pemakai
     * pertama diam-diam mewarisi izin pemakai kedua dan ujinya membuktikan
     * yang lain daripada yang tertulis di namanya.
     */
    private function userWith(array $permissions, string $role = 'penjaga-gudang'): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roleModel = Role::findOrCreate($role, 'web');
        $roleModel->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pemakai '.$role, 'email' => $role.'@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($roleModel);

        return $user;
    }

    public function test_a_rule_is_created_and_carries_the_item_minimum_it_replaces(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Kabel UTP Cat6', ['min_stock' => 100]);

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder-rules', [
                'warehouse_id' => $warehouse->id,
                'item_id' => $item->id,
                'reorder_point' => 20,
                'reorder_qty' => 50,
                'is_active' => true,
                'notes' => 'Gudang site hanya menyimpan cadangan satu minggu.',
            ])
            ->assertCreated()
            ->json('data');

        // (float) di sisi uji: json_encode PHP menulis 20.0 sebagai `20`
        // tanpa JSON_PRESERVE_ZERO_FRACTION, jadi jenis PHP-nya di sini adalah
        // artefak encoder, bukan pernyataan tentang kolomnya.
        $this->assertSame(20.0, (float) $payload['reorder_point']);
        $this->assertSame(50.0, (float) $payload['reorder_qty']);
        $this->assertSame(100.0, (float) $payload['item']['min_stock'], 'Angka item yang digantikan harus ikut menyeberang.');
        $this->assertSame($warehouse->code, $payload['warehouse']['code']);
    }

    public function test_a_second_rule_for_the_same_pair_is_refused_with_a_sentence_not_a_500(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Besi Beton D16', ['min_stock' => 100]);

        ReorderRule::create([
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
            'reorder_point' => 20, 'reorder_qty' => 0, 'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder-rules', [
                'warehouse_id' => $warehouse->id,
                'item_id' => $item->id,
                'reorder_point' => 40,
            ])
            ->assertStatus(422);

        $this->assertStringContainsString(
            'sudah punya aturan reorder',
            (string) $response->json('errors.warehouse_id.0'),
        );

        $this->assertSame(1, ReorderRule::query()->count());
    }

    /** Pasangan yang berbeda tetap boleh — penolakan di atas bukan penolakan atas segalanya. */
    public function test_the_same_item_may_take_a_rule_in_a_second_warehouse(): void
    {
        $central = $this->makeWarehouse('GD-PUSAT');
        $site = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);

        ReorderRule::create(['warehouse_id' => $central->id, 'item_id' => $item->id, 'reorder_point' => 200, 'reorder_qty' => 0, 'is_active' => true]);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder-rules', [
                'warehouse_id' => $site->id, 'item_id' => $item->id, 'reorder_point' => 20,
            ])
            ->assertCreated();

        $this->assertSame(2, ReorderRule::query()->count());
    }

    public function test_moving_a_rule_onto_a_pair_that_already_has_one_is_refused_too(): void
    {
        $central = $this->makeWarehouse('GD-PUSAT');
        $site = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Pasir Beton', ['min_stock' => 50]);

        $moving = ReorderRule::create(['warehouse_id' => $central->id, 'item_id' => $item->id, 'reorder_point' => 200, 'reorder_qty' => 0, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $site->id, 'item_id' => $item->id, 'reorder_point' => 20, 'reorder_qty' => 0, 'is_active' => true]);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson("api/inventory/reorder-rules/{$moving->id}", ['warehouse_id' => $site->id])
            ->assertStatus(422);

        $this->assertSame($central->id, $moving->fresh()->warehouse_id);
    }

    /**
     * ARAH KEDUA dari penjaga yang sama — dan yang dulu tidak dijaga.
     *
     * Penjaga uniknya dulu hanya menumpang pada `warehouse_id`, yang bertanda
     * `sometimes`: muatan yang hanya menyebut `item_id` melewatkan seluruh
     * pemeriksaan dan mendarat sebagai 500 dari QueryException — persis
     * kegagalan yang request ini ada untuk mencegah, dari arah yang lain.
     */
    public function test_moving_a_rule_onto_another_item_of_the_same_warehouse_is_refused_too(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $semen = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        $besi = $this->makeItem('Besi Beton D16', ['min_stock' => 100]);

        $moving = ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $semen->id, 'reorder_point' => 200, 'reorder_qty' => 0, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $besi->id, 'reorder_point' => 20, 'reorder_qty' => 0, 'is_active' => true]);

        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson("api/inventory/reorder-rules/{$moving->id}", ['item_id' => $besi->id])
            ->assertStatus(422);

        $this->assertStringContainsString(
            'sudah punya aturan reorder',
            (string) $response->json('errors.item_id.0'),
        );

        $this->assertSame($semen->id, $moving->fresh()->item_id, 'Barisnya tidak boleh berpindah.');
    }

    /**
     * "JUMLAH PESAN" YANG DIKOSONGKAN — persis yang teks bantuan di bawah
     * kotaknya sarankan sebagai cara menyatakan "pakai kekurangannya".
     *
     * Kolomnya NOT NULL di kedua dialek, dan `nullable` dalam aturan validasi
     * hanya berarti "tidak wajib": sebuah null EKSPLISIT lolos dan mendarat di
     * UPDATE sebagai 500 yang mencetak SQL mentah beserta jalur berkas basis
     * data ke layar penjaga gudang. Sumbernya yang dipaku, bukan kolomnya yang
     * dilonggarkan.
     */
    public function test_clearing_the_order_quantity_is_the_documented_way_to_say_use_the_shortage(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        $rule = ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 50, 'reorder_qty' => 25, 'is_active' => true]);

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson("api/inventory/reorder-rules/{$rule->id}", [
                'warehouse_id' => $warehouse->id,
                'item_id' => $item->id,
                'reorder_point' => 50,
                'reorder_qty' => null,
                'is_active' => true,
                'notes' => null,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(0.0, (float) $payload['reorder_qty']);
        $this->assertSame('0.000', $rule->fresh()->reorder_qty);
    }

    /** Sama pada PEMBUATAN: kotak yang dibiarkan kosong bukan 500. */
    public function test_creating_a_rule_with_an_empty_order_quantity_is_accepted_as_zero(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Kabel UTP Cat6', ['min_stock' => 100]);

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder-rules', [
                'warehouse_id' => $warehouse->id,
                'item_id' => $item->id,
                'reorder_point' => 20,
                'reorder_qty' => null,
                'is_active' => null,
                'notes' => null,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(0.0, (float) $payload['reorder_qty']);
        // is_active null pada PEMBUATAN adalah "tidak dinyatakan", dan sebuah
        // aturan yang baru dibuat tetapi mati sejak lahir tidak menjelaskan
        // apa pun kepada yang membuatnya.
        $this->assertTrue($payload['is_active']);
    }

    /** …dan is_active null pada SUNTINGAN tidak mematikan saklar yang menyala. */
    public function test_a_null_switch_on_edit_leaves_the_rule_where_it_was(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        $rule = ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 50, 'reorder_qty' => 0, 'is_active' => false]);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson("api/inventory/reorder-rules/{$rule->id}", ['reorder_point' => 60, 'is_active' => null])
            ->assertOk();

        $this->assertFalse($rule->fresh()->is_active);
    }

    /** …dan menyunting aturan itu sendiri tanpa memindahkannya tetap boleh. */
    public function test_editing_a_rule_in_place_is_not_blocked_by_its_own_row(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        $rule = ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 200, 'reorder_qty' => 0, 'is_active' => true]);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson("api/inventory/reorder-rules/{$rule->id}", [
                'warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 150, 'is_active' => false,
            ])
            ->assertOk();

        $rule->refresh();
        $this->assertSame('150.000', $rule->reorder_point);
        $this->assertFalse($rule->is_active);
    }

    /**
     * "AKTIF ✓" TIDAK BERARTI "BERLAKU".
     *
     * Item dan gudang menghapus-lembut; relasi aturan memakai withTrashed()
     * dengan sengaja supaya namanya selamat dan barisnya tetap bisa dibuang
     * orangnya. Tetapi kueri kekurangan membuang item dan gudang terhapus
     * lebih dulu — ambang baris itu sudah tidak menentukan apa pun, sementara
     * kolom "Aktif" tetap ✓. Barisnya harus MENGATAKANNYA.
     */
    public function test_a_rule_whose_item_was_thrown_away_says_so_instead_of_looking_alive(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 80, 'reorder_qty' => 0, 'is_active' => true]);

        $admin = $this->adminUser();

        $before = $this->actingAs($admin, 'sanctum')->getJson('api/inventory/reorder-rules')->json('data.0');
        $this->assertTrue($before['applies']);
        $this->assertSame([], $before['deleted_labels']);

        $item->delete();

        $after = $this->actingAs($admin, 'sanctum')->getJson('api/inventory/reorder-rules')->json('data.0');

        $this->assertTrue($after['is_active'], 'Saklarnya memang masih menyala — itulah yang menyesatkan.');
        $this->assertFalse($after['applies']);
        $this->assertSame(['Item dibuang'], $after['deleted_labels']);
        $this->assertTrue($after['item']['deleted']);
    }

    /**
     * `applies` MENJAWAB PERTANYAAN YANG NAMANYA JANJIKAN: "apakah ambang
     * baris ini menentukan sesuatu hari ini?"
     *
     * Ia dulu hanya memeriksa item/gudang terbuang, jadi aturan NONAKTIF —
     * yang menurut bantuan formulirnya sendiri "tetap tersimpan dan tidak
     * menentukan ambang apa pun" — dikirim sebagai `applies: true`. Ketiga
     * syaratnya sekarang datang dari satu tempat (`ReorderRule::governs()`),
     * yaitu tiga syarat yang sama dengan yang dipakai kueri kekurangan.
     */
    public function test_a_switched_off_rule_does_not_claim_to_apply(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 80, 'reorder_qty' => 0, 'is_active' => false]);

        $row = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder-rules')->json('data.0');

        $this->assertFalse($row['is_active']);
        $this->assertFalse($row['applies'],
            'Aturan nonaktif tidak menentukan ambang apa pun, dan barisnya tidak boleh mengaku sebaliknya.');
        // …dan tandanya di layar adalah kolom "Aktif" itu sendiri: tidak ada
        // yang DIBUANG di sini, jadi tidak ada keping "…dibuang".
        $this->assertSame([], $row['deleted_labels']);
    }

    /**
     * …DAN ITEM YANG BERHENTI DIBELI ADALAH SYARAT KEEMPAT.
     *
     * `inv_items.is_active = false` adalah jalur normal untuk barang yang
     * tidak dibeli lagi — dinonaktifkan, bukan dibuang, jadi kartunya tetap
     * bisa dibaca dan riwayatnya utuh. Kueri kekurangan sudah lama
     * membuangnya (`->where('i.is_active', true)`), tetapi syarat itu tidak
     * ikut ke `governing()`: barisnya digambar tanpa satu keping pun,
     * `applies: true`, untuk aturan yang tidak menentukan apa pun.
     */
    public function test_a_rule_for_an_item_that_is_no_longer_bought_does_not_claim_to_apply(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Keramik Diskontinu', ['min_stock' => 200, 'is_active' => false]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 80, 'reorder_qty' => 0, 'is_active' => true]);

        $row = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder-rules')->json('data.0');

        $this->assertTrue($row['is_active'], 'Saklar aturannya memang menyala — itulah yang menyesatkan.');
        $this->assertFalse($row['applies'],
            'Item nonaktif dibuang kueri kekurangan lebih dulu, jadi ambang baris ini tidak menentukan apa pun.');
        // Tidak ada yang DIBUANG di sini: kepingnya menyebut penghapusan, dan
        // nonaktif bukan penghapusan.
        $this->assertSame([], $row['deleted_labels']);
    }

    /** …dan gudang yang dibuang ditandai dengan kalimatnya sendiri. */
    public function test_a_rule_whose_warehouse_was_thrown_away_says_that_instead(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Besi Beton D16', ['min_stock' => 100]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 30, 'reorder_qty' => 0, 'is_active' => true]);

        $warehouse->delete();

        $row = $this->actingAs($this->adminUser(), 'sanctum')->getJson('api/inventory/reorder-rules')->json('data.0');

        $this->assertFalse($row['applies']);
        $this->assertSame(['Gudang dibuang'], $row['deleted_labels']);
        $this->assertTrue($row['warehouse']['deleted']);
    }

    public function test_a_rule_for_a_warehouse_that_does_not_exist_is_refused(): void
    {
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder-rules', ['warehouse_id' => 99999, 'item_id' => $item->id, 'reorder_point' => 10])
            ->assertStatus(422);
    }

    /**
     * NAMANYA MENYEBUT APA YANG BENAR-BENAR DIBUKTIKANNYA.
     *
     * Uji ini dulu bernama "…reading needs only inv.view…" dan tidak pernah
     * menanyakan pemakai TANPA inv.view sama sekali; rutenya memang tidak
     * bergerbang — `Route::get('reorder-rules', …)` hanya di bawah
     * `auth:sanctum`, sama seperti SETIAP GET Inventory yang sudah ada. Nama
     * itu berjanji gerbang yang tidak ada, dan pembaca berikutnya yang
     * menyandarkan keputusan padanya tidak akan menjatuhkan satu uji pun.
     *
     * Jadi yang dipaku di sini adalah keduanya, apa adanya: MENULIS menuntut
     * inv.create, dan MEMBACA mengikuti bawaan modul — terbuka bagi sesi mana
     * pun. Baris terakhir mendokumentasikan 200 itu SEBAGAI keputusan yang
     * disengaja (ReorderController docblock menyebutnya), bukan sebagai
     * kelalaian yang kebetulan lolos; kalau pemilik memutuskan sebaliknya,
     * baris inilah yang jatuh lebih dulu dan menunjuk tempat gerbangnya.
     */
    public function test_writing_a_rule_needs_inv_create_while_reading_follows_the_module_default(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        $reader = $this->userWith(['inv.view']);

        $this->actingAs($reader, 'sanctum')
            ->getJson('api/inventory/reorder-rules')
            ->assertOk();

        $this->actingAs($reader, 'sanctum')
            ->postJson('api/inventory/reorder-rules', ['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 10])
            ->assertForbidden();

        // Sesi TANPA satu pun izin inv.*: 200, sama seperti GET Inventory lain
        // di sekitarnya. Ubin launcher Persediaan MEMANG bergerbang inv.view
        // (registri ModuleCounts), jadi ubinnya tertutup sementara endpoint-nya
        // terbuka — selisih yang ada sebelum paket ini dan tercatat sebagai
        // keputusan pemilik di LAPORAN-PAKET-HM-F-6 §5.
        $outsider = $this->userWith(['hr.view'], 'staf-hr');

        $this->actingAs($outsider, 'sanctum')
            ->getJson('api/inventory/reorder-rules')
            ->assertOk();

        $this->actingAs($outsider, 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk();
    }

    public function test_the_list_can_be_narrowed_to_one_warehouse_and_searched_by_item(): void
    {
        $central = $this->makeWarehouse('GD-PUSAT');
        $site = $this->makeWarehouse('GD-SITE');
        $semen = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        $besi = $this->makeItem('Besi Beton D16', ['min_stock' => 100]);

        ReorderRule::create(['warehouse_id' => $central->id, 'item_id' => $semen->id, 'reorder_point' => 200, 'reorder_qty' => 0, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $site->id, 'item_id' => $semen->id, 'reorder_point' => 20, 'reorder_qty' => 0, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $site->id, 'item_id' => $besi->id, 'reorder_point' => 10, 'reorder_qty' => 0, 'is_active' => true]);

        $admin = $this->adminUser();

        $this->assertCount(2, $this->actingAs($admin, 'sanctum')
            ->getJson("api/inventory/reorder-rules?warehouse_id={$site->id}")->json('data'));

        $this->assertCount(2, $this->actingAs($admin, 'sanctum')
            ->getJson('api/inventory/reorder-rules?q=Semen')->json('data'));
    }

    /**
     * KARTU ITEM ADALAH SATU-SATUNYA LAYAR YANG MEMAJANG ANGKA YANG KALAH.
     *
     * CONVENTIONS §31 menuntut prioritas ambang DITULIS di layar, dan empat
     * permukaan menulisnya dari arah aturan → item ("400 · Aturan reorder
     * gudang ini · stok min. item 200"). Arah sebaliknya tidak dikerjakan:
     * seseorang membuka ITM-0001, membaca "Stok minimum 200,000", dan
     * menyimpulkan itulah ambang di seluruh gudang — sementara pasangan
     * ITM-0001 × Gudang Site berambang 400 dan sedang kurang 50. Yang
     * menaikkan angka 200 di sana tidak mengubah apa pun.
     */
    public function test_the_item_card_says_its_minimum_is_replaced_where_a_rule_exists(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        $admin = $this->adminUser();

        $before = $this->actingAs($admin, 'sanctum')
            ->getJson("api/inventory/items/{$item->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('reorder_rule_note', $before,
            'Item tanpa aturan tidak boleh membawa baris kosong di kartunya.');

        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 400, 'reorder_qty' => 0, 'is_active' => true]);

        $after = $this->actingAs($admin, 'sanctum')
            ->getJson("api/inventory/items/{$item->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('reorder_rule_note', $after);
        $this->assertStringContainsString('1 gudang', $after['reorder_rule_note']);
        $this->assertStringContainsString('TIDAK berlaku', $after['reorder_rule_note']);
        $this->assertStringContainsString('Aturan Reorder', $after['reorder_rule_note']);
    }

    /**
     * …DAN ATURAN YANG GUDANGNYA SUDAH DIBUANG JUGA TIDAK MENGGANTIKAN APA PUN.
     *
     * Kalimat kartu item dulu menghitung `is_active` saja dan tidak pernah
     * menyentuh `inv_warehouses.deleted_at`, sementara kueri kekurangan
     * membuang gudang terhapus lebih dulu. Kartunya karena itu berkata "stok
     * minimum di atas TIDAK berlaku" untuk aturan yang tidak menentukan apa
     * pun — dan layar Aturan Reorder di sebelahnya menandai baris yang sama
     * "Gudang dibuang". Yang membacanya berhenti menaikkan `min_stock` karena
     * mengira ada aturan yang menang; tidak ada.
     */
    public function test_a_rule_whose_warehouse_was_thrown_away_stops_counting_on_the_item_card(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 400, 'reorder_qty' => 0, 'is_active' => true]);

        $admin = $this->adminUser();

        $before = $this->actingAs($admin, 'sanctum')
            ->getJson("api/inventory/items/{$item->id}")->assertOk()->json('data');
        $this->assertArrayHasKey('reorder_rule_note', $before);

        $warehouse->delete();

        $after = $this->actingAs($admin, 'sanctum')
            ->getJson("api/inventory/items/{$item->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('reorder_rule_note', $after,
            'Kartu item menghitung aturan yang gudangnya sudah dibuang — aturan yang tidak menentukan apa pun.');
    }

    /** …dan aturan NONAKTIF tidak menggantikan apa pun, jadi ia tidak disebut. */
    public function test_an_inactive_rule_leaves_the_item_card_alone(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 400, 'reorder_qty' => 0, 'is_active' => false]);

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson("api/inventory/items/{$item->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('reorder_rule_note', $payload);
    }

    /**
     * BARCODE GANDA BISA DICARI DARI DAFTAR ITEM.
     *
     * Laporan paket meminta pemilik menjalankan satu audit `GROUP BY barcode
     * HAVING COUNT(*) > 1` di produksi sebelum memutuskan apakah kolom itu
     * harus UNIQUE — dan tidak memberinya satu pun cara menjalankannya kecuali
     * SSH + tinker. Keputusan yang butuh angka tetapi angkanya tidak bisa
     * diambil siapa pun adalah keputusan yang tidak akan pernah diambil.
     */
    public function test_the_item_list_can_be_narrowed_to_barcodes_more_than_one_item_uses(): void
    {
        $this->makeItem('Semen A', ['code' => 'ITM-D001', 'barcode' => 'F6DUP001']);
        $this->makeItem('Semen B', ['code' => 'ITM-D002', 'barcode' => 'F6DUP001']);
        $this->makeItem('Semen C', ['code' => 'ITM-D003', 'barcode' => 'F6UNIQ01']);
        $this->makeItem('Semen D', ['code' => 'ITM-D004']);

        $admin = $this->adminUser();

        $duplicates = $this->actingAs($admin, 'sanctum')
            ->getJson('api/inventory/items?barcode_duplicate=1')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(
            ['ITM-D001', 'ITM-D002'],
            array_column($duplicates, 'code'),
            'Saringan barcode ganda harus memulangkan TEPAT item yang berbagi kodenya.',
        );

        // …dan barcode kosong bukan duplikat: dua item tanpa barcode tidak
        // berbagi apa pun.
        $unique = $this->actingAs($admin, 'sanctum')
            ->getJson('api/inventory/items?barcode_duplicate=0')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['ITM-D003', 'ITM-D004'], array_column($unique, 'code'));

        // Kolomnya ada di layar, atau saringannya menyaring sesuatu yang tidak
        // bisa dilihat siapa pun.
        // Blok resource-nya dipotong pada resource BERIKUTNYA, bukan pada
        // jumlah karakter tetap: satu komentar baru di dalamnya mendorong
        // kolomnya ke luar jendela dan uji ini menjadi merah untuk perubahan
        // yang tidak ada hubungannya.
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $start = (int) strpos($schema, "'inventory/items': {");
        $block = substr($schema, $start, (int) strpos($schema, "'inventory/item-categories': {", $start) - $start);
        $this->assertStringContainsString("key: 'barcode', label: 'Barcode'", $block);
        $this->assertStringContainsString("key: 'barcode_duplicate'", $block);
    }

    public function test_deleting_a_rule_really_deletes_it_so_the_pair_can_be_ruled_again(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);
        $rule = ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 200, 'reorder_qty' => 0, 'is_active' => true]);

        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("api/inventory/reorder-rules/{$rule->id}")
            ->assertOk();

        $this->assertSame(0, ReorderRule::query()->count());

        // …dan pasangannya bebas lagi. Sebuah softDeletes() di tabel ini akan
        // membuat permintaan berikutnya ditolak 422 selamanya.
        $this->actingAs($admin, 'sanctum')
            ->postJson('api/inventory/reorder-rules', ['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 50])
            ->assertCreated();
    }
}
