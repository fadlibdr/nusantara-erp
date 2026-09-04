<?php

namespace Tests\Feature\Iam;

use Illuminate\Support\Facades\Artisan;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Iam\Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * erp:permission-check — the finding of 4 Sep 2026, made impossible to miss.
 *
 * Production admin held 74 of 86 permissions (HASIL-UJI §6.2 P-1): eng.* and
 * qc.* had been added to PermissionSeeder::PREFIXES, the code was deployed,
 * and no seeder ever re-ran on the live database — two packages unreachable
 * by anyone, and nothing that could say so. These tests pin the three things
 * the deploy gate relies on: a freshly seeded database is clean and the
 * expected count is DERIVED from the seeder constants; a permission the
 * seeder mints but the table lacks fails the check by name; and a role that
 * lost one of its intended permissions fails it naming both the role and
 * the permission. Plus the coupling the whole thing rests on: what run()
 * seeds is exactly what intended() says.
 */
class PermissionCheckTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array{0: int, 1: string} exit code + captured output */
    private function check(array $options = []): array
    {
        $code = Artisan::call('erp:permission-check', $options);

        return [$code, Artisan::output()];
    }

    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')->firstOrFail();
    }

    public function test_the_expected_count_is_derived_from_the_seeder_constants(): void
    {
        $derived = count(PermissionSeeder::PREFIXES) * count(PermissionSeeder::ACTIONS)
            + count(PermissionSeeder::DIRECTOR_APPROVALS);

        $this->assertCount($derived, PermissionSeeder::expected());
        $this->assertCount($derived, array_unique(PermissionSeeder::expected()), 'no name is minted twice');
        $this->assertSame($derived, Permission::query()->where('guard_name', 'web')->count(), 'run() mints exactly expected()');
    }

    public function test_a_freshly_seeded_database_is_clean_and_prints_the_derived_count(): void
    {
        [$code, $out] = $this->check();
        $expected = count(PermissionSeeder::expected());

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("Izin: {$expected} diharapkan", $out);
        $this->assertStringContainsString("{$expected} di basis data", $out);
        $this->assertStringContainsString('Tidak ada penyimpangan izin', $out);
        $this->assertStringNotContainsString('PENYIMPANGAN', $out);

        foreach (array_keys(RoleSeeder::intended()) as $role) {
            $this->assertMatchesRegularExpression('/\|\s*'.preg_quote($role, '/').'\s*\|.*\|\s*sesuai\s*\|/', $out, "{$role} row reads sesuai");
        }
    }

    /**
     * The coupling erp:permission-check rests on: RoleSeeder::run() and
     * RoleSeeder::intended() are one map, so a fresh seed reproduces the
     * intent exactly — including admin, which is spelled out as every
     * canonical permission rather than "whatever the table holds".
     */
    public function test_run_seeds_exactly_what_intended_declares(): void
    {
        foreach (RoleSeeder::intended() as $name => $intended) {
            $this->assertEqualsCanonicalizing(
                $intended,
                $this->role($name)->permissions()->pluck('name')->all(),
                "role {$name} holds exactly what intended() declares",
            );
        }

        $this->assertEqualsCanonicalizing(
            PermissionSeeder::expected(),
            $this->role('admin')->permissions()->pluck('name')->all(),
        );
    }

    /**
     * The production case in miniature: a permission the seeder mints that
     * the table lacks. Every role meant to hold it is named too, which is
     * what the roles screen could not tell the operator on 4 Sep 2026.
     */
    public function test_a_permission_missing_from_the_table_fails_the_check_by_name(): void
    {
        Permission::query()->where('name', 'eng.view')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$code, $out] = $this->check();
        $expected = count(PermissionSeeder::expected());

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString("Izin: {$expected} diharapkan", $out);
        $this->assertStringContainsString(($expected - 1).' di basis data', $out);
        $this->assertStringContainsString('Kurang di basis data (1): eng.view', $out);
        $this->assertStringContainsString('PENYIMPANGAN IZIN: 1 izin kurang', $out);
        // Deleting the row detaches it from every role that held it.
        foreach (['admin', 'direktur', 'project-manager', 'site-manager', 'estimator', 'procurement'] as $role) {
            $this->assertStringContainsString("{$role} kurang: eng.view", $out);
        }
        // Doubled backslashes on purpose: the hint is the shell-escaped form
        // the RECAP T1.1 entry prints, pasteable into bash as-is.
        $this->assertStringContainsString('db:seed --class=Modules\\\\Iam\\\\Database\\\\Seeders\\\\PermissionSeeder', $out);
    }

    /**
     * A role edited away from the seeder — the thing the RECAP warns the
     * re-seed will overwrite, and the thing the check must name before that.
     */
    public function test_a_permission_stripped_from_a_role_fails_the_check_naming_role_and_permission(): void
    {
        $this->role('finance')->revokePermissionTo('fin.post');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$code, $out] = $this->check();

        $this->assertSame(1, $code, $out);
        // The permission itself still exists — only the role lost it.
        $this->assertStringContainsString('PENYIMPANGAN IZIN: 0 izin kurang, 0 izin tidak dikenal, 1 peran menyimpang (finance)', $out);
        $this->assertStringContainsString('finance kurang: fin.post', $out);
        $this->assertMatchesRegularExpression('/\|\s*finance\s*\|.*\|\s*menyimpang\s*\|/', $out);
        $this->assertStringNotContainsString('finance-manager kurang', $out);
    }

    public function test_a_permission_added_to_a_role_is_drift_too(): void
    {
        $this->role('finance')->givePermissionTo('fin.approve'); // undoes the SoD split
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$code, $out] = $this->check();

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('finance lebih: fin.approve', $out);
    }

    public function test_a_seeded_role_that_no_longer_exists_is_drift(): void
    {
        $this->role('teknisi')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$code, $out] = $this->check();

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('teknisi: peran tidak ada di basis data', $out);
    }

    /**
     * A role created on Sistem › Peran & Hak Akses is not drift: RoleSeeder
     * never touches it, so there is no intent to compare against. It is
     * listed so the operator knows the check did not look at it.
     */
    public function test_a_role_outside_the_seeder_is_reported_but_not_judged(): void
    {
        $custodian = Role::findOrCreate('kasir-kas-kecil', 'web');
        $custodian->syncPermissions(['fin.view', 'fin.create', 'fin.update']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$code, $out] = $this->check();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Peran di luar seeder, tidak diperiksa (1): kasir-kas-kecil', $out);
        $this->assertStringContainsString('Tidak ada penyimpangan izin', $out);
    }

    public function test_json_output_carries_the_same_verdict_for_scripts(): void
    {
        [$code, $out] = $this->check(['--json' => true]);
        $json = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $code);
        $this->assertFalse($json['drift']);
        $this->assertSame(count(PermissionSeeder::expected()), $json['expected']);
        $this->assertSame($json['expected'], $json['in_database']);
        $this->assertSame([], $json['missing']);
        $this->assertSame(array_keys(RoleSeeder::intended()), array_keys($json['roles']));

        $this->role('hr')->revokePermissionTo('iam.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$code, $out] = $this->check(['--json' => true]);
        $json = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $code);
        $this->assertTrue($json['drift']);
        $this->assertSame(['hr'], $json['drifted_roles']);
        $this->assertSame(['iam.view'], $json['roles']['hr']['missing']);
    }
}
