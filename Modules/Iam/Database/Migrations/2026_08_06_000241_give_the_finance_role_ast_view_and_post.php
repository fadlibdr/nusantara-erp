<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PENYUSUTAN MILIK FINANCE — the retrofit for installations whose roles were
 * seeded long ago, same surgery pattern as the fin.approve split (000220).
 *
 * Posting a depreciation run requires ast.post, and no seeded role except
 * admin held it — finance did not even hold ast.view, so the Assets menu was
 * invisible to the login responsible for the monthly close. RoleSeeder now
 * grants both on a fresh install; nothing re-runs it against a live database
 * and `db:seed` is forbidden on the demo dataset, so this migration puts the
 * two permissions on erp1's existing `finance` role.
 *
 * Deliberately NOT ast.create/update/delete: the register stays with the
 * project side, keeping the recorder of an asset and the poster of its
 * depreciation two different people.
 *
 * Guarded on the `finance` role already existing, which makes it a genuine
 * no-op on a fresh RefreshDatabase test database (RoleSeeder has not run
 * there) and on any installation that never seeded roles.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const GRANTS = ['ast.view', 'ast.post'];

    public function up(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $finance = Role::where('name', 'finance')->where('guard_name', 'web')->first();

        foreach (self::GRANTS as $name) {
            $finance?->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Revoke from the role only — the permissions themselves are canon
        // (PermissionSeeder mints every prefix.action pair) and other roles
        // still hold them.
        $finance = Role::where('name', 'finance')->where('guard_name', 'web')->first();

        foreach (self::GRANTS as $name) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();

            if ($permission !== null) {
                $finance?->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Nothing to retrofit on an installation that has no roles yet —
     * RoleSeeder will already be creating `finance` with these grants.
     */
    private function rolesAreSeeded(): bool
    {
        $roles = config('permission.table_names.roles', 'roles');

        return Schema::hasTable($roles)
            && Role::where('name', 'finance')->where('guard_name', 'web')->exists();
    }
};
