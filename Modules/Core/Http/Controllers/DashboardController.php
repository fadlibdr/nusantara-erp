<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Projects\Models\Project;

/**
 * The dashboard's money tiles, summed in SQL.
 *
 * dashboard.js used to fetch page 1 (per_page 100) of projects, AR invoices
 * and AP bills and reduce the tile numbers in the browser. That is not an
 * approximation that degrades visibly — it is an undercount that starts the
 * day document 101 exists and never announces itself: a director reading
 * "Piutang belum tertagih Rp 3,2 M" has no way to know invoice 101..140 are
 * missing from the number. Here each figure is one aggregate query over the
 * whole table.
 *
 * Permission scoping follows the calendar rule: no gate on the route, and each
 * block is included only when the caller holds that module's .view permission
 * — an absent block and a module the caller may not read look the same.
 *
 * The tile semantics are pinned to what dashboard.js always displayed:
 * approved documents only, outstanding = total − amount_paid (cancellation
 * reverses the receivable but also leaves status 'cancelled', so the status
 * filter already excludes it), active = status active|finishing.
 */
class DashboardController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = [];

        if ($user?->can('prj.view')) {
            $projects = Project::query()
                ->whereIn('status', ['active', 'finishing'])
                // 'Proyek saya' (Temuan 80): users.employee_id →
                // prj_projects.project_manager_id. An account without an
                // employee link manages no projects, so mine=1 must be empty
                // for it — falling back to "all projects" would make the
                // toggle lie precisely on admin accounts.
                ->when($request->boolean('mine'), fn ($query) => $user->employee_id === null
                    ? $query->whereRaw('1 = 0')
                    : $query->where('project_manager_id', $user->employee_id))
                ->selectRaw('count(*) as active_count')
                ->selectRaw('coalesce(sum(contract_value), 0) as contract_value')
                ->first();

            $data['projects'] = [
                'active_count' => (int) $projects->active_count,
                'contract_value' => round((float) $projects->contract_value, 2),
            ];
        }

        if ($user?->can('fin.view')) {
            $ar = ArInvoice::query()
                ->where('status', 'approved')
                ->selectRaw('coalesce(sum(total - amount_paid), 0) as outstanding')
                ->selectRaw('count(case when total - amount_paid > 0 then 1 end) as open_count')
                ->first();

            $ap = ApBill::query()
                ->where('status', 'approved')
                ->selectRaw('coalesce(sum(total_payable - amount_paid), 0) as outstanding')
                ->selectRaw('count(case when total_payable - amount_paid > 0 then 1 end) as open_count')
                ->first();

            $data['ar_invoices'] = [
                'outstanding' => round((float) $ar->outstanding, 2),
                'open_count' => (int) $ar->open_count,
            ];
            $data['ap_bills'] = [
                'outstanding' => round((float) $ap->outstanding, 2),
                'open_count' => (int) $ap->open_count,
            ];
        }

        // (object) so a caller with no view permission at all reads {} — the
        // SPA iterates keys either way, and [] would flip the JSON shape.
        return $this->ok((object) $data);
    }
}
