<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One search box over everything the user is allowed to see.
 *
 * There was no search box in the shell and no command palette; navigation was
 * the sidebar and nothing else. Finding PO/2026/VII/0042 meant knowing it was a
 * purchase order, opening that list, and typing into its filter — and thirteen
 * index endpoints did not accept a `q` at all.
 *
 * Two rules hold this together.
 *
 * IT SEARCHES WHAT THE CALLER MAY READ, AND NOTHING ELSE. Every group declares
 * the permission its module demands, and a group the caller fails is not queried
 * at all. A search box is the easiest accidental enumeration oracle in any ERP:
 * "no results" and "results you may not open" have to be the same answer, and
 * they are, because the query never runs.
 *
 * IT LOOKS FOR WHAT PEOPLE ACTUALLY TYPE. A document code, a company name, a
 * person's name — never a description or a note. Matching free text would bury
 * the invoice somebody wanted under every daily report that mentioned it, and
 * `LIKE '%…%'` over a text column is the one query in this system that cannot
 * use an index.
 */
class GlobalSearchService
{
    /** Rows per group. Beyond this the answer is "use the list screen". */
    private const PER_GROUP = 5;

    private const MIN_LENGTH = 2;

    /**
     * table, permission, SPA resource, and the one title column worth matching
     * beside the code.
     *
     * Groups are listed in the order a person is most likely to be looking:
     * projects and customers before payments and tickets. Within a group the
     * newest record wins, because a code that repeats across years should
     * surface this year's document.
     */
    private function groups(): array
    {
        return [
            ['label' => 'Proyek', 'table' => 'prj_projects', 'perm' => 'prj.view', 'resource' => 'projects', 'title' => 'name'],
            ['label' => 'Pelanggan', 'table' => 'crm_customers', 'perm' => 'crm.view', 'resource' => 'crm/customers', 'title' => 'name'],
            ['label' => 'Kontrak', 'table' => 'crm_contracts', 'perm' => 'crm.view', 'resource' => 'crm/contracts', 'title' => 'title'],
            ['label' => 'Penawaran', 'table' => 'crm_quotations', 'perm' => 'crm.view', 'resource' => 'crm/quotations', 'title' => 'title'],
            ['label' => 'Vendor', 'table' => 'prc_vendors', 'perm' => 'prc.view', 'resource' => 'procurement/vendors', 'title' => 'name'],
            ['label' => 'Pesanan Pembelian', 'table' => 'prc_purchase_orders', 'perm' => 'prc.view', 'resource' => 'procurement/purchase-orders', 'title' => null],
            ['label' => 'Permintaan Pembelian', 'table' => 'prc_purchase_requisitions', 'perm' => 'prc.view', 'resource' => 'procurement/purchase-requisitions', 'title' => null],
            ['label' => 'Item', 'table' => 'inv_items', 'perm' => 'inv.view', 'resource' => 'inventory/items', 'title' => 'name'],
            ['label' => 'Karyawan', 'table' => 'hr_employees', 'perm' => 'hr.view', 'resource' => 'hr/employees', 'title' => 'name'],
            ['label' => 'Aset', 'table' => 'ast_assets', 'perm' => 'ast.view', 'resource' => 'assets/assets', 'title' => 'name'],
            ['label' => 'Invoice (AR)', 'table' => 'fin_ar_invoices', 'perm' => 'fin.view', 'resource' => 'finance/ar-invoices', 'title' => null],
            ['label' => 'Tagihan Vendor (AP)', 'table' => 'fin_ap_bills', 'perm' => 'fin.view', 'resource' => 'finance/ap-bills', 'title' => null],
            ['label' => 'Pembayaran', 'table' => 'fin_payments', 'perm' => 'fin.view', 'resource' => 'finance/payments', 'title' => null],
            ['label' => 'SPK Subkon', 'table' => 'scm_subcontracts', 'perm' => 'scm.view', 'resource' => 'subcontract/subcontracts', 'title' => 'title'],
            ['label' => 'Tiket Layanan', 'table' => 'svc_tickets', 'perm' => 'svc.view', 'resource' => 'servicedesk/tickets', 'title' => 'title'],
            ['label' => 'Insiden K3', 'table' => 'prj_safety_incidents', 'perm' => 'prj.view', 'resource' => 'projects/safety-incidents', 'title' => 'location'],
        ];
    }

    /**
     * @return array{term: string, groups: array, total: int}
     */
    public function search(?User $user, string $term, int $perGroup = self::PER_GROUP): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return ['term' => $term, 'groups' => [], 'total' => 0];
        }

        $groups = [];
        $total = 0;

        foreach ($this->groups() as $group) {
            if ($user === null || ! $user->can($group['perm'])) {
                continue;
            }

            $hits = $this->query($group, $term, $perGroup);

            if ($hits === []) {
                continue;
            }

            $total += count($hits);
            $groups[] = [
                'label' => $group['label'],
                'resource' => $group['resource'],
                'results' => $hits,
            ];
        }

        return ['term' => $term, 'groups' => $groups, 'total' => $total];
    }

    private function query(array $group, string $term, int $perGroup): array
    {
        $like = '%'.$this->escapeLike($term).'%';
        $titleColumn = $group['title'];

        $query = DB::table($group['table'])
            ->select(array_values(array_filter(['id', 'code', $titleColumn])))
            // whereRaw with an explicit ESCAPE: without it neither SQLite nor
            // MySQL honours the escapes below, and a search for "50%" would
            // return every row in the table. The escape character is '!', not
            // the backslash: "ESCAPE '\'" is valid SQLite but an unterminated
            // string on MySQL, where the backslash escapes the closing quote —
            // 7 GlobalSearchTest errors on phpunit.mysql.xml, 5 Sep 2026 (T0.3).
            ->where(function ($where) use ($like, $titleColumn): void {
                $where->whereRaw("code LIKE ? ESCAPE '!'", [$like]);

                if ($titleColumn !== null) {
                    $where->orWhereRaw("{$titleColumn} LIKE ? ESCAPE '!'", [$like]);
                }
            })
            // Newest first: a code that repeats across years should surface this
            // year's document, and a name search wants the current record.
            ->orderByDesc('id')
            ->limit($perGroup);

        if ($this->hasSoftDeletes($group['table'])) {
            $query->whereNull('deleted_at');
        }

        return $query->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'title' => $titleColumn === null ? null : ($row->{$titleColumn} ?? null),
                'link' => "#/d/{$group['resource']}/{$row->id}",
            ])
            ->all();
    }

    /**
     * A term containing % or _ must match those characters, not act as a
     * wildcard: searching "50%" should not return every row in the table.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    /** @var array<string, bool> */
    private array $softDeletes = [];

    private function hasSoftDeletes(string $table): bool
    {
        return $this->softDeletes[$table] ??= DB::getSchemaBuilder()->hasColumn($table, 'deleted_at');
    }
}
