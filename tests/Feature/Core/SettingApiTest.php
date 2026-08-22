<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Core\Services\SettingService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET/PUT /api/core/settings — the screen an operator uses to update statutory
 * parameters without a deploy. The endpoint is registry-driven: it must report
 * every editable key with its effective value, its shipped default and whether
 * it is currently overridden, and a PUT must persist (or clear) exactly that.
 */
class SettingApiTest extends ErpTestCase
{
    /**
     * Lower bound only. The exact figure used to be pinned here and went stale
     * the moment the accounting group grew (the GR/IR accounts), which turned a
     * registry addition into an unrelated red test. The payload is checked
     * against SettingService::editableKeys() key for key below, which is the
     * assertion that actually matters; this floor only catches a registry that
     * has collapsed.
     */
    private const MIN_EDITABLE_KEY_COUNT = 60;

    private const GROUP_COUNT = 9;

    private function actAsAdmin(): User
    {
        $user = $this->adminUser();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /**
     * Flatten the grouped payload into key => row for direct assertions.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(TestResponse $response): array
    {
        return collect($response->json('data.groups'))
            ->flatMap(fn (array $group): array => $group['settings'])
            ->keyBy('key')
            ->all();
    }

    public function test_index_returns_every_editable_key_with_value_default_and_override_state(): void
    {
        $this->actAsAdmin();

        $response = $this->getJson('/api/core/settings');

        $response->assertOk();
        $this->assertCount(self::GROUP_COUNT, $response->json('data.groups'));

        $rows = $this->rows($response);
        $this->assertGreaterThanOrEqual(self::MIN_EDITABLE_KEY_COUNT, count($rows));
        $this->assertSame(
            array_keys(app(SettingService::class)->editableKeys()),
            array_keys($rows),
        );

        foreach ($rows as $key => $row) {
            $this->assertArrayHasKey('value', $row, "Setting [{$key}] has no value.");
            $this->assertArrayHasKey('default', $row, "Setting [{$key}] has no default.");
            $this->assertArrayHasKey('is_overridden', $row, "Setting [{$key}] has no override flag.");
            $this->assertArrayHasKey('label', $row, "Setting [{$key}] has no label.");
            $this->assertArrayHasKey('type', $row, "Setting [{$key}] has no type.");
            $this->assertFalse($row['is_overridden'], "Setting [{$key}] should be untouched on a fresh install.");
        }

        // Shipped defaults, straight from config/erp.php.
        $this->assertSame(11.0, (float) $rows['tax.ppn_rate']['value']);
        $this->assertSame(11.0, (float) $rows['tax.ppn_rate']['default']);
        $this->assertSame(173, (int) $rows['payroll.overtime.divisor']['value']);
        $this->assertSame('PO/{Y}/{RM}/{N4}', $rows['documents.PO']['value']);
    }

    public function test_index_marks_an_overridden_key_and_still_reports_the_shipped_default(): void
    {
        $this->actAsAdmin();
        $this->setSetting('tax.ppn_rate', 12);

        $rows = $this->rows($this->getJson('/api/core/settings')->assertOk());

        $this->assertSame(12.0, (float) $rows['tax.ppn_rate']['value']);
        $this->assertSame(11.0, (float) $rows['tax.ppn_rate']['default']);
        $this->assertTrue($rows['tax.ppn_rate']['is_overridden']);
        // Untouched neighbour stays on its default.
        $this->assertFalse($rows['projects.default_retention_pct']['is_overridden']);
    }

    public function test_update_persists_the_new_value_and_echoes_it_back(): void
    {
        $this->actAsAdmin();

        $response = $this->putJson('/api/core/settings', [
            'settings' => ['tax.ppn_rate' => 12],
        ]);

        $response->assertOk();
        $this->assertSame('Pengaturan disimpan.', $response->json('message'));

        $rows = $this->rows($response);
        $this->assertSame(12.0, (float) $rows['tax.ppn_rate']['value']);
        $this->assertSame(11.0, (float) $rows['tax.ppn_rate']['default']);
        $this->assertTrue($rows['tax.ppn_rate']['is_overridden']);

        $this->assertDatabaseHas('core_settings', ['key' => 'tax.ppn_rate', 'group' => 'tax']);
        $this->assertSame(12.0, (float) app(SettingService::class)->get('tax.ppn_rate'));
    }

    public function test_update_writes_several_parameters_in_one_call(): void
    {
        $this->actAsAdmin();

        $this->putJson('/api/core/settings', [
            'settings' => [
                'tax.ppn_rate' => 12,
                'payroll.overtime.divisor' => 180,
                'documents.PO' => 'PO-{Y}-{N5}',
            ],
        ])->assertOk();

        $settings = app(SettingService::class);
        $this->assertSame(12.0, (float) $settings->get('tax.ppn_rate'));
        $this->assertSame(180, (int) $settings->get('payroll.overtime.divisor'));
        $this->assertSame('PO-{Y}-{N5}', $settings->get('documents.PO'));
        $this->assertDatabaseCount('core_settings', 3);
    }

    public function test_a_null_value_resets_the_parameter_and_deletes_its_row(): void
    {
        $this->actAsAdmin();
        $this->setSetting('tax.ppn_rate', 12);
        $this->assertDatabaseHas('core_settings', ['key' => 'tax.ppn_rate']);

        $response = $this->putJson('/api/core/settings', [
            'settings' => ['tax.ppn_rate' => null],
        ]);

        $response->assertOk();
        $rows = $this->rows($response);
        $this->assertSame(11.0, (float) $rows['tax.ppn_rate']['value']);
        $this->assertFalse($rows['tax.ppn_rate']['is_overridden']);

        $this->assertDatabaseMissing('core_settings', ['key' => 'tax.ppn_rate']);
        $this->assertSame(11.0, (float) app(SettingService::class)->get('tax.ppn_rate'));
    }

    public function test_resetting_one_parameter_leaves_the_others_overridden(): void
    {
        $this->actAsAdmin();
        $this->setSetting('tax.ppn_rate', 12);
        $this->setSetting('projects.default_retention_pct', 10);

        $this->putJson('/api/core/settings', [
            'settings' => ['tax.ppn_rate' => null],
        ])->assertOk();

        $this->assertDatabaseMissing('core_settings', ['key' => 'tax.ppn_rate']);
        $this->assertDatabaseHas('core_settings', ['key' => 'projects.default_retention_pct']);
        $this->assertSame(10.0, (float) app(SettingService::class)->get('projects.default_retention_pct'));
    }

    public function test_an_updated_value_is_visible_to_the_next_request(): void
    {
        $this->actAsAdmin();

        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 12]])->assertOk();

        // Re-read through a second request: the cached override map must have
        // been flushed after the commit, not before it.
        $rows = $this->rows($this->getJson('/api/core/settings')->assertOk());
        $this->assertSame(12.0, (float) $rows['tax.ppn_rate']['value']);
    }

    public function test_a_user_without_core_update_may_read_but_not_write(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('staf', 'web');
        $role->syncPermissions(['crm.view']); // anything but core.update

        $user = User::query()->create([
            'name' => 'Staf Teknik',
            'email' => 'staf@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/core/settings')->assertOk();

        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 12]])
            ->assertStatus(403);

        $this->assertDatabaseCount('core_settings', 0);
        $this->assertSame(11.0, (float) app(SettingService::class)->get('tax.ppn_rate'));
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson('/api/core/settings')->assertStatus(401);
        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 12]])->assertStatus(401);

        $this->assertDatabaseCount('core_settings', 0);
    }
}
