<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\GlobalSearchService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Models\Item;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pencarian global.
 *
 * There was no search box in the shell and no command palette. Navigation was
 * the sidebar and nothing else, so finding PO/2026/VII/0042 meant knowing it was
 * a purchase order, opening that list, and typing into its filter — and thirteen
 * index endpoints did not accept a `q` at all.
 */
class GlobalSearchTest extends ErpTestCase
{
    use FinanceFixtures;

    private GlobalSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = app(GlobalSearchService::class);
    }

    private function seedThings(): void
    {
        Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
        ]);
        $this->makeVendor(['code' => 'VND-0001', 'name' => 'PT Semen Distribusi Utama']);
        Item::query()->create([
            'code' => 'ITM-0001', 'name' => 'Semen Portland 50kg', 'unit' => 'zak',
            'category_id' => DB::table('inv_item_categories')->insertGetId([
                'code' => 'SIPIL', 'name' => 'Sipil', 'created_at' => now(), 'updated_at' => now(),
            ]),
            'item_type' => 'material', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------ what it finds

    public function test_a_document_code_finds_its_document(): void
    {
        $this->seedThings();

        $result = $this->search->search($this->adminUser(), 'PRJ-2026');

        $this->assertSame(1, $result['total']);
        $this->assertSame('Proyek', $result['groups'][0]['label']);
        $this->assertSame('PRJ-2026-001', $result['groups'][0]['results'][0]['code']);
    }

    /** One word can legitimately hit several kinds of record at once. */
    public function test_one_term_spans_the_groups_it_matches(): void
    {
        $this->seedThings();

        $result = $this->search->search($this->adminUser(), 'Semen');

        $this->assertSame(['Vendor', 'Item'], array_column($result['groups'], 'label'));
        $this->assertSame(2, $result['total']);
    }

    /** Every hit carries the route that opens it; that is the whole point. */
    public function test_every_hit_carries_a_link_the_spa_can_open(): void
    {
        $this->seedThings();

        $hit = $this->search->search($this->adminUser(), 'Graha')['groups'][0]['results'][0];

        $this->assertSame('#/d/projects/'.$hit['id'], $hit['link']);
    }

    public function test_a_soft_deleted_record_is_not_found(): void
    {
        $this->seedThings();
        Vendor::query()->where('code', 'VND-0001')->delete();

        $this->assertSame(0, $this->search->search($this->adminUser(), 'VND-0001')['total']);
    }

    // ------------------------------------------------------------- the guards

    /**
     * A search box is the easiest accidental enumeration oracle in an ERP. A
     * group the caller may not read is never queried, so "no results" and
     * "results you may not open" are the same answer.
     */
    public function test_it_returns_nothing_from_a_module_the_caller_cannot_read(): void
    {
        $this->seedThings();

        $result = $this->search->search($this->userWithOnly(['inv.view']), 'Semen');

        $this->assertSame(['Item'], array_column($result['groups'], 'label'));
        $this->assertSame(1, $result['total'], 'the vendor of the same name must not surface');
    }

    public function test_an_anonymous_caller_finds_nothing(): void
    {
        $this->seedThings();

        $this->assertSame(0, $this->search->search(null, 'Semen')['total']);
    }

    /**
     * A lone "%" is a valid thing to type and must not return the whole
     * database, which is what an unescaped LIKE wildcard would do.
     */
    public function test_a_wildcard_character_is_matched_literally(): void
    {
        $this->seedThings();

        $admin = $this->adminUser();

        $this->assertSame(0, $this->search->search($admin, '%%')['total']);
        $this->assertSame(0, $this->search->search($admin, '__')['total']);
    }

    /** One letter matches most of the database and helps nobody. */
    public function test_a_single_character_is_not_searched(): void
    {
        $this->seedThings();

        $this->assertSame(0, $this->search->search($this->adminUser(), 'S')['total']);
    }

    public function test_each_group_is_capped_so_one_kind_cannot_bury_the_rest(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->makeVendor(['code' => 'VND-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'name' => "PT Banyak {$i}"]);
        }

        $result = $this->search->search($this->adminUser(), 'Banyak', 5);

        $this->assertCount(5, $result['groups'][0]['results']);
    }

    // ------------------------------------------------------------- the endpoint

    public function test_the_endpoint_answers_a_search(): void
    {
        $this->seedThings();

        $this->actingAs($this->adminUser())
            ->getJson('/api/core/search?q=PRJ-2026')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.groups.0.results.0.code', 'PRJ-2026-001');
    }

    public function test_the_endpoint_requires_a_term(): void
    {
        $this->actingAs($this->adminUser())
            ->getJson('/api/core/search')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    private function userWithOnly(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('terbatas', 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas', 'email' => 'petugas@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
