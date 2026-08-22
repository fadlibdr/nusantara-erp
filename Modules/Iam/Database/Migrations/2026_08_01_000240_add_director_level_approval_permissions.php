<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PERSETUJUAN LEVEL DIREKTUR — the permissions behind enforcing
 * needs_director_approval, for installations whose roles were seeded long ago.
 *
 * The flag was stamped on submit, shown on the detail screen and announced in
 * the submit response, and no code path ever read it at approval time: on erp1
 * SPK/2026/II/0001 (Rp 6.500.000.000, 32,5× the Rp 200 juta threshold) was
 * submitted AND approved by user id 1 while the screen said a director was
 * required. The guard now lives in DirectorApproval and checks
 * prc.approve-director / scm.approve-director; this migration makes those
 * permissions exist on a live database and puts them where the flag always
 * claimed they were — with the director. PermissionSeeder + RoleSeeder do the
 * same on a fresh install.
 *
 * admin is granted too: its seeding is "every permission in the system", and
 * an admin suddenly unable to approve a large PO would read as a regression,
 * not a control. The three documents already approved past the flag stay
 * approved — the guard only examines documents still awaiting approval, and
 * their core_approvals rows remain as evidence of what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionSeeder::DIRECTOR_APPROVALS as $name) {
            $permission = Permission::findOrCreate($name, 'web');

            Role::where('name', 'direktur')->where('guard_name', 'web')->first()
                ?->givePermissionTo($permission);
            Role::where('name', 'admin')->where('guard_name', 'web')->first()
                ?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Deleting the permission row detaches it from every role and user.
        Permission::query()
            ->whereIn('name', PermissionSeeder::DIRECTOR_APPROVALS)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * On an installation with no roles yet the seeders create these
     * permissions in their final shape; there is nothing to retrofit.
     */
    private function rolesAreSeeded(): bool
    {
        $roles = config('permission.table_names.roles', 'roles');

        return Schema::hasTable($roles)
            && Role::where('name', 'direktur')->where('guard_name', 'web')->exists();
    }
};
