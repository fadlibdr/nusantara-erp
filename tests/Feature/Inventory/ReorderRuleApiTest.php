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

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('penjaga-gudang', 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Penjaga Gudang', 'email' => 'gudang@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

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

    public function test_a_rule_for_a_warehouse_that_does_not_exist_is_refused(): void
    {
        $item = $this->makeItem('Semen Portland', ['min_stock' => 200]);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder-rules', ['warehouse_id' => 99999, 'item_id' => $item->id, 'reorder_point' => 10])
            ->assertStatus(422);
    }

    public function test_reading_the_rules_needs_only_inv_view_but_writing_needs_inv_create(): void
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
