<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PEMISAHAN TUGAS KEUANGAN — the only part of this package that reaches an
 * installation whose roles were seeded long ago.
 *
 * RoleSeeder is authoritative for a fresh install, but nothing re-runs it
 * against a live database and `db:seed` is forbidden against the demo dataset,
 * so on erp1 the `finance` role would keep fin.approve — the screen would look
 * identical and the finding would still be open. This does the surgery:
 * fin.approve leaves `finance`, and a new `finance-manager` role receives it.
 *
 * Guarded on the `finance` role already existing, which makes it a genuine
 * no-op on a fresh RefreshDatabase test database (RoleSeeder has not run there)
 * and on any installation that never seeded roles — so it adds nothing to the
 * setup cost of the suite.
 *
 * It deliberately creates NO USER. Minting a login with a known password on
 * somebody's production install from a migration is not acceptable, and the
 * demo does not need it: direktur@nusantara.test already holds fin.approve.
 */
return new class extends Migration
{
    private const MANAGER_ROLE = 'finance-manager';

    /** @var list<string> */
    private const MANAGER_PERMISSIONS = [
        'fin.view', 'fin.approve',
        'crm.view', 'prc.view', 'scm.view',
    ];

    public function up(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'finance')->where('guard_name', 'web')->first()
            ?->revokePermissionTo(Permission::findOrCreate('fin.approve', 'web'));

        $manager = Role::findOrCreate(self::MANAGER_ROLE, 'web');
        $manager->syncPermissions(array_map(
            static fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
            self::MANAGER_PERMISSIONS,
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'finance')->where('guard_name', 'web')->first()
            ?->givePermissionTo(Permission::findOrCreate('fin.approve', 'web'));

        Role::where('name', self::MANAGER_ROLE)->where('guard_name', 'web')->first()?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Nothing to split on an installation that has no roles yet — RoleSeeder
     * will already be creating them in their post-split shape.
     */
    private function rolesAreSeeded(): bool
    {
        $roles = config('permission.table_names.roles', 'roles');

        return Schema::hasTable($roles)
            && Role::where('name', 'finance')->where('guard_name', 'web')->exists();
    }
};
