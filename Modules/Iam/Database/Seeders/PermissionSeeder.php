<?php

namespace Modules\Iam\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Module prefixes = table prefix without underscore (see CONVENTIONS.md §6).
     */
    public const PREFIXES = [
        'core', 'iam', 'crm', 'inv', 'ast', 'est',
        'prj', 'prc', 'scm', 'hr', 'fin', 'svc',
    ];

    public const ACTIONS = ['view', 'create', 'update', 'delete', 'approve', 'post'];

    /**
     * Persetujuan level direktur, di atas izin approve biasa. Only the two
     * documents that stamp needs_director_approval carry one — a PO at/from
     * Rp 100 juta and an SPK at/from Rp 200 juta (approvals.*.threshold_two_level)
     * — and before these permissions existed the flag was stamped, displayed
     * and never enforced: SPK/2026/II/0001 (Rp 6,5 miliar, 32,5× the threshold)
     * was approved by a single non-director login. Deliberately NOT an entry in
     * ACTIONS: expanding it across all twelve prefixes would mint ten
     * permissions nothing checks, and an unchecked permission on the roles
     * screen reads as a control that exists.
     */
    public const DIRECTOR_APPROVALS = ['prc.approve-director', 'scm.approve-director'];

    /*
     * NAMED SEAM (kas kecil): a dedicated fin.cashier permission for drawer
     * custodians is a real future need — today a custodian is provisioned
     * with a custom role holding fin.view/create/update, and fin.create also
     * lets them DRAFT journals/bills/payments (inert under maker-checker:
     * posting/approving needs fin.approve|fin.post they do not hold), a
     * widening that belongs in the release note. It is deliberately NOT
     * minted here yet, for the DIRECTOR_APPROVALS reason above: nothing
     * checks it, and an unchecked permission on the roles screen reads as a
     * control that exists. Mint it together with the route-gate change that
     * enforces it; the in-service custodian-identity guard
     * (PettyCashVoucherService::assertCustodian) stays either way — the
     * permission only narrows the route gate.
     */

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PREFIXES as $prefix) {
            foreach (self::ACTIONS as $action) {
                Permission::findOrCreate("{$prefix}.{$action}", 'web');
            }
        }

        foreach (self::DIRECTOR_APPROVALS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }
}
