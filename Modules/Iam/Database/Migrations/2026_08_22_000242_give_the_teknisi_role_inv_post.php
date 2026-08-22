<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TEKNISI BOLEH MEM-POSTING BON GUDANG — T13, decided by the owner on 22 Aug
 * 2026, same retrofit pattern as the finance ast.view/ast.post grant (000241).
 *
 * What this unblocks: acknowledging a parts-bearing field report relieves
 * warehouse stock and posts a journal, so the acknowledge endpoint demands
 * inv.post (FieldReportAcknowledgeRequest) — and only admin held it. A teknisi
 * could submit their own field report but only an admin could acknowledge one
 * that consumed spare parts, so every parts visit queued on an admin and a
 * report stuck in Submitted blocked its month's close. With this grant the
 * teknisi acknowledges the visit they made and the stock is relieved under
 * their own name.
 *
 * What it widens, stated because the grant is broader than the defect:
 * inv.post gates 8 inventory routes (goods-receipt post/cancel, issue
 * post/cancel, issue-return post, purchase-return post, transfer
 * send/receive), so a teknisi can now post or cancel any draft stock document
 * they can reach with inv.view — not just the bon behind their own visit. The
 * owner accepted that on 22 Aug 2026; one revokePermissionTo on the `teknisi`
 * role reverses it.
 *
 * RoleSeeder now grants inv.post on a fresh install; nothing re-runs it
 * against a live database and `db:seed` is forbidden on the demo dataset, so
 * this migration puts the permission on erp1's existing `teknisi` role.
 * Guarded on that role already existing, which makes it a genuine no-op on a
 * fresh RefreshDatabase test database (RoleSeeder has not run there) and on
 * any installation that never seeded roles.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const GRANTS = ['inv.post'];

    public function up(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $teknisi = Role::where('name', 'teknisi')->where('guard_name', 'web')->first();

        foreach (self::GRANTS as $name) {
            $teknisi?->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Revoke from the role only — the permission itself is canon
        // (PermissionSeeder mints every prefix.action pair) and admin still
        // holds it, so the old path (an admin acknowledges) comes back intact.
        $teknisi = Role::where('name', 'teknisi')->where('guard_name', 'web')->first();

        foreach (self::GRANTS as $name) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();

            if ($permission !== null) {
                $teknisi?->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Nothing to retrofit on an installation that has no roles yet —
     * RoleSeeder will already be creating `teknisi` with this grant.
     */
    private function rolesAreSeeded(): bool
    {
        $roles = config('permission.table_names.roles', 'roles');

        return Schema::hasTable($roles)
            && Role::where('name', 'teknisi')->where('guard_name', 'web')->exists();
    }
};
