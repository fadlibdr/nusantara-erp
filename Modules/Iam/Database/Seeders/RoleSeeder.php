<?php

namespace Modules\Iam\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Role name => the permission names the role is meant to hold.
     *
     * Public and static so erp:permission-check can read the intent without
     * re-seeding: production admin held 74/86 permissions on 4 Sep 2026
     * (HASIL-UJI §6.2 P-1) and the only way to see WHICH roles had fallen
     * behind was to read this file next to the roles screen. run() iterates
     * this same map, so the seeded shape and the checked shape cannot drift
     * apart — whoever changes a role here changes both.
     *
     * admin is spelled out as every canonical permission (PermissionSeeder::
     * expected()) rather than "every row in the permissions table": the two
     * coincide on a healthy database, and on a drifted one a row the seeder
     * never minted is itself the finding, not something admin should
     * silently absorb. Nothing in the application mints a permission outside
     * PermissionSeeder — the roles API only assigns existing names.
     *
     * @return array<string, list<string>>
     */
    public static function intended(): array
    {
        $allButApprove = ['view', 'create', 'update', 'delete', 'post'];

        return [
            // admin — every permission in the system.
            'admin' => PermissionSeeder::expected(),

            // direktur — sees everything, approves everything, including the
            // documents whose value demands a director's own signature.
            'direktur' => array_merge(
                self::expand(PermissionSeeder::PREFIXES, ['view']),
                self::expand(PermissionSeeder::PREFIXES, ['approve']),
                PermissionSeeder::DIRECTOR_APPROVALS,
            ),

            /*
             * P1-ENG — eng.* distribution, decided from RoleSeeder reality:
             * engineering documents are prepared by the drafter/estimator and the
             * site (Made Wirawan is "Drafter/Estimator", the IPP is raised by the
             * pelaksana), and INTERNALLY authorised by the Manajer Proyek — the
             * same person whose prj.approve signs the P0-C field permits, so
             * eng.approve lands on the same role. eng.approve also covers
             * RECORDING the MK's stamp on a submittal (typing the external
             * decision in is approve-adjacent power; the service separately
             * refuses the submittal's own creator). direktur inherits eng.view +
             * eng.approve through the PREFIXES expansion above, admin everything.
             */
            /*
             * P1-QC — qc.* follows the eng.* reasoning: quality inspections and NCRs
             * are prepared by the site and the project's own QC, and INTERNALLY
             * authorised by the Manajer Proyek, so qc.approve lands on the same role
             * that holds prj.approve/eng.approve. qc.approve also covers VERIFYING an
             * NCR (accepting that a correction held is approve-adjacent power) and
             * approving an inspection sheet. direktur inherits qc.view + qc.approve
             * through the PREFIXES expansion above; admin everything.
             */
            'project-manager' => array_merge(
                self::expand(['prj', 'est', 'scm', 'inv', 'ast', 'eng', 'qc'], ['view', 'create', 'update']),
                ['prj.approve', 'eng.approve', 'qc.approve'],
            ),

            'site-manager' => array_merge(
                self::expand(['prj', 'eng', 'qc'], ['view', 'create', 'update']),
                self::expand(['inv'], ['view', 'create']),
            ),

            'estimator' => array_merge(
                self::expand(['est'], $allButApprove),
                // The drafter half of "Drafter/Estimator": registers drawings and
                // prepares submittals, never records the MK's stamp on their own
                // submission (no eng.approve).
                self::expand(['eng'], ['view', 'create', 'update']),
                ['prj.view', 'crm.view'],
            ),

            'procurement' => array_merge(
                self::expand(['prc'], $allButApprove),
                // eng.view only: buying a material that the MK has not approved is
                // exactly what reading the SMS register prevents.
                ['inv.view', 'est.view', 'eng.view'],
            ),

            // Warehouse handles physical stock; approving and posting stock
            // adjustments stays with management.
            'warehouse' => array_merge(
                self::expand(['inv'], ['view', 'create', 'update', 'delete']),
                ['prj.view'],
            ),

            /*
             * PEMISAHAN TUGAS. finance prepares and pays; it does not approve.
             *
             * Until this split the one role held create + approve + post on every
             * finance document, so a single login could raise a vendor bill to a
             * vendor of its own choosing, approve it and disburse it — the
             * one-person fraud path an external auditor writes up as a material
             * weakness. It keeps fin.post: a disbursement can now only be posted on
             * a payment somebody else already approved, which is the ordinary
             * two-signature model, and moving posting to the approver would just
             * make the approver do the clerical work.
             */
            'finance' => array_merge(
                self::expand(['fin'], $allButApprove),
                ['crm.view', 'prc.view', 'scm.view', 'hr.view'],
                /*
                 * ast.view + ast.post: posting the monthly depreciation run is a
                 * close step finance owns, and without these two it was admin-only
                 * — the menu Aset was not even visible to the finance login, so on
                 * a real close the step was either skipped or done by IT. NOT
                 * ast.create/update: the register (adding assets, changing costs)
                 * stays with the project side, so the person who records an asset
                 * and the person who posts its depreciation remain different
                 * people.
                 */
                ['ast.view', 'ast.post'],
            ),

            /*
             * The second pair of eyes on finance. Enough context to judge a bill —
             * the customer, the PO, the SPK behind it — and no ability to raise
             * one. direktur also holds fin.approve, but routing every vendor bill
             * to the managing director is how a small contractor ends up sharing
             * the director's password.
             */
            'finance-manager' => [
                'fin.view', 'fin.approve',
                'crm.view', 'prc.view', 'scm.view',
            ],

            /*
             * PEMISAHAN TUGAS, sisi payroll — the same split `finance` got, because
             * hr was the same bundle. Until this change the one role held create +
             * update + approve + post on payroll, so hr@nusantara.test could raise
             * her own basic_salary, calculate a run and approve it — and approving
             * IS posting: PayrollRunController::approve books the whole run to the
             * ledger in the same transaction (PYR/2026/06/002 = Rp 196.270.346,83
             * gross on the demo). Maker-checker alone still guarded that path, but
             * maker-checker is one setting flip away from off; the finance role was
             * protected by the role split as well, and payroll — the largest
             * recurring outflow — deserves the same two controls. hr.approve now
             * sits with direktur (and admin), who already approve everything else.
             */
            'hr' => array_merge(
                self::expand(['hr'], $allButApprove),
                ['iam.view'],
            ),

            'sales' => array_merge(
                self::expand(['crm'], $allButApprove),
                ['prj.view', 'svc.view'],
            ),

            /*
             * inv.post — T13, decided by the owner on 22 Aug 2026 (migration
             * 000242 does the same surgery on erp1's already-seeded role).
             * Acknowledging a parts-bearing field report relieves stock and posts
             * a journal, so the endpoint demands inv.post — held by admin alone,
             * which meant a teknisi could submit their own visit report but only
             * an admin could acknowledge one that consumed spare parts. Now the
             * teknisi acknowledges the visit they made, stock relieved under
             * their own name. Stated cost: inv.post gates 8 inventory routes,
             * so a teknisi can also post/cancel any draft stock document they
             * can reach with inv.view — accepted, reversible with one
             * revokePermissionTo. TeknisiInventoryPostingPermissionTest pins
             * this role to exactly these five permissions.
             */
            'teknisi' => array_merge(
                self::expand(['svc'], ['view', 'create', 'update']),
                ['inv.view', 'inv.post'],
            ),
        ];
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::intended() as $name => $permissions) {
            Role::findOrCreate($name, 'web')->syncPermissions($permissions);
        }
    }

    /**
     * Cartesian product of prefixes x actions => permission names.
     *
     * @param  list<string>  $prefixes
     * @param  list<string>  $actions
     * @return list<string>
     */
    private static function expand(array $prefixes, array $actions): array
    {
        $names = [];

        foreach ($prefixes as $prefix) {
            foreach ($actions as $action) {
                $names[] = "{$prefix}.{$action}";
            }
        }

        return $names;
    }
}
