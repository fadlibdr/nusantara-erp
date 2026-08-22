<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PEMISAHAN TUGAS PAYROLL — the hr twin of
 * 2026_07_31_000220_split_fin_approve_out_of_the_finance_role.
 *
 * That migration split fin.approve out of `finance`; `hr` kept the identical
 * create + approve + post bundle, so on erp1 hr@nusantara.test could still
 * raise her own basic_salary, calculate a run, submit it — and the moment
 * approvals.segregation_of_duties is unticked (one request for any core.update
 * holder) — approve it, which books the full run to the ledger in the same
 * transaction: Rp 196.270.346,83 gross on PYR/2026/06/002. RoleSeeder now
 * seeds `hr` without hr.approve, but nothing re-runs it against a live
 * database, so this does the same surgery there.
 *
 * No new role: direktur already holds hr.approve from the original seeding.
 * The grant below is a safety net for an installation whose direktur role was
 * hand-edited — revoking hr's only approval right while nobody else holds it
 * would leave payroll unapprovable app-wide.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'hr')->where('guard_name', 'web')->first()
            ?->revokePermissionTo(Permission::findOrCreate('hr.approve', 'web'));

        Role::where('name', 'direktur')->where('guard_name', 'web')->first()
            ?->givePermissionTo(Permission::findOrCreate('hr.approve', 'web'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // direktur keeps hr.approve on the way down too: it held the
        // permission before this migration ever ran.
        Role::where('name', 'hr')->where('guard_name', 'web')->first()
            ?->givePermissionTo(Permission::findOrCreate('hr.approve', 'web'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Nothing to split on an installation that has no roles yet — RoleSeeder
     * will already be creating `hr` in its post-split shape.
     */
    private function rolesAreSeeded(): bool
    {
        $roles = config('permission.table_names.roles', 'roles');

        return Schema::hasTable($roles)
            && Role::where('name', 'hr')->where('guard_name', 'web')->exists();
    }
};
