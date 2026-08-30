<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\Attachment;
use Modules\Core\Support\AttachableDocuments;
use Modules\Core\Support\Geotag;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\PettyCashVoucher;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\GatePass;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\SafetyIncident;
use Modules\Projects\Models\WorkPermit;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;

/**
 * Galeri foto progres per proyek (Temuan 16).
 *
 * One query over core_attachments: image-mime files hanging off every
 * attachable document type that REALLY belongs to a project. "Really" means a
 * project_id column, or one unambiguous hop (GRN → its PO, opname subkon →
 * its SPK) — never an inference. Deliberately absent, with the reason each
 * time, in sources() below.
 *
 * Photos are ordered by when they were TAKEN when the camera said so
 * (taken_at, from EXIF), falling back to upload time: a week of site photos
 * uploaded in one batch on Friday must not collapse onto Friday.
 *
 * Two permission layers, both borrowed from elsewhere in Core:
 *  - prj.view opens the gallery at all, checked BEFORE the project id is
 *    resolved (AttachmentController's rule — the other order is an existence
 *    oracle over project ids);
 *  - each SOURCE is included only while the caller holds that module's .view
 *    (the calendar rule) — a scanned nota on a vendor bill is finance's to
 *    show, and must not become readable through the gallery side door.
 */
class ProjectPhotoController extends ApiController
{
    public function __invoke(Request $request, int $project): JsonResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->can('prj.view')) {
            return $this->error('Anda tidak memiliki izin prj.view.', 403);
        }

        $found = Project::query()->find($project);

        if ($found === null) {
            return $this->error('Proyek tidak ditemukan.', 404);
        }

        $visible = array_filter(
            $this->sources($found),
            static fn (array $source): bool => $user->can($source['prefix'].'.view'),
        );

        $query = Attachment::query()
            ->with('uploader:id,name')
            ->where('mime', 'like', 'image/%')
            ->where(function (Builder $where) use ($visible): void {
                foreach ($visible as $source) {
                    $where->orWhere(fn (Builder $one) => $one
                        ->where('attachable_type', $source['class'])
                        ->whereIn('attachable_id', $source['ids']));
                }
            });

        // The window filters the same date the gallery sorts and displays —
        // capture date first, upload date as fallback. A malformed bound is
        // dropped, not 500'd (the listing() convention).
        foreach (['date_from' => '>=', 'date_to' => '<='] as $param => $operator) {
            $value = $request->query($param);

            if (is_string($value) && Carbon::hasFormat($value, 'Y-m-d')) {
                $query->whereRaw("date(coalesce(taken_at, created_at)) {$operator} ?", [$value]);
            }
        }

        // Per-source counts BEFORE pagination, so the chips stay true on
        // every page.
        $countsByClass = (clone $query)
            ->reorder()
            ->selectRaw('attachable_type, count(*) as n')
            ->groupBy('attachable_type')
            ->pluck('n', 'attachable_type');

        $paginator = $query
            ->orderByRaw('coalesce(taken_at, created_at) desc')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 24));

        $codes = $this->documentCodes($paginator->items());
        $site = $this->siteCoordinates($found);

        $data = array_map(
            fn (Attachment $photo): array => $this->row($photo, $codes, $site),
            $paginator->items(),
        );

        $sources = [];

        foreach ($visible as $source) {
            $count = (int) ($countsByClass[$source['class']] ?? 0);

            if ($count > 0) {
                $sources[] = [
                    'slug' => $source['slug'],
                    'label' => AttachableDocuments::labelFor($source['slug']),
                    'count' => $count,
                ];
            }
        }

        return $this->ok($data, null, [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'sources' => $sources,
        ]);
    }

    /**
     * Every attachable type with a REAL linkage to this project, scoped to it.
     *
     * Deliberately absent:
     *  - servicedesk/tickets + field-reports: svc_tickets belongs to a service
     *    contract; it has no project column, and pretending a ticket photo is
     *    project evidence would put the wrong site's CCTV repair into a
     *    termin's lampiran.
     *  - finance/payments: fin_payments has no project_id.
     *  - inventory/stock-adjustments: warehouse-scoped, not project-scoped.
     *  - crm/quotations, contracts, guarantees: the pointer runs project →
     *    contract, not the reverse — there is no project_id to scope on.
     *    (est BOQ/RAP are also pre-award paper, but they DO carry project_id,
     *    which is why they sit in the list above and these do not.)
     *  - vendors, employees, certificates, assets: master data.
     *
     * @return list<array{slug: string, class: class-string, prefix: string, ids: Builder}>
     */
    private function sources(Project $project): array
    {
        $id = $project->id;

        // Eloquent builders, so soft-deleted parents drop out exactly as they
        // do on their own list screens — a photo on a deleted laporan harian
        // is not progress evidence.
        $scoped = [
            'projects/projects' => Project::query()->whereKey($id),
            'projects/daily-reports' => DailyReport::query()->where('project_id', $id),
            'projects/bast' => Bast::query()->where('project_id', $id),
            'projects/defects' => Defect::query()->where('project_id', $id),
            // P0-C: foto APD pada izin kerja dan foto muatan pada izin gerbang
            // adalah bukti lapangan proyek itu sendiri — project_id langsung.
            'projects/work-permits' => WorkPermit::query()->where('project_id', $id),
            'projects/gate-passes' => GatePass::query()->where('project_id', $id),
            // P6 (temuan panduan §7.7): foto kejadian K3 kini menempel pada
            // insidennya — project_id langsung, bukti lapangan proyek itu.
            'projects/safety-incidents' => SafetyIncident::query()->where('project_id', $id),
            // Deviasi P6 #1: foto opname owner (bekisting, hasil cor, angka
            // meteran) adalah bukti progres persis — project_id langsung.
            // Attachable sejak P3, tetapi terlewat dari daftar ini.
            'projects/progress-measurements' => ProgressMeasurement::query()->where('project_id', $id),
            // est_boqs / est_cost_budgets membawa project_id sendiri — foto
            // survei lokasi di BOQ dan lampiran RAP adalah bukti proyek,
            // bukan hanya dokumen pra-kontrak; sebelum baris ini keduanya
            // tidak muncul di galeri DAN tidak disebut absen di docblock.
            'estimation/boqs' => Boq::query()->where('project_id', $id),
            'estimation/cost-budgets' => CostBudget::query()->where('project_id', $id),
            'subcontract/subcontracts' => Subcontract::query()->where('project_id', $id),
            'subcontract/progress-claims' => ProgressClaim::query()->whereIn(
                'subcontract_id',
                Subcontract::query()->where('project_id', $id)->select('id'),
            ),
            'procurement/purchase-requisitions' => PurchaseRequisition::query()->where('project_id', $id),
            'procurement/purchase-orders' => PurchaseOrder::query()->where('project_id', $id),
            'inventory/goods-receipts' => GoodsReceipt::query()->whereIn(
                'purchase_order_id',
                PurchaseOrder::query()->where('project_id', $id)->select('id'),
            ),
            'finance/ar-invoices' => ArInvoice::query()->where('project_id', $id),
            'finance/ap-bills' => ApBill::query()->where('project_id', $id),
            'finance/journals' => Journal::query()->where('project_id', $id),
            'finance/petty-cash-vouchers' => PettyCashVoucher::query()->where('project_id', $id),
            'finance/kasbon' => Kasbon::query()->where('project_id', $id),
        ];

        $sources = [];

        foreach ($scoped as $slug => $ids) {
            $sources[] = [
                'slug' => $slug,
                'class' => AttachableDocuments::classFor($slug),
                'prefix' => AttachableDocuments::prefixFor($slug),
                'ids' => $ids->select($ids->getModel()->getTable().'.id'),
            ];
        }

        return $sources;
    }

    /**
     * code per parent document for one page — one query per type present,
     * never one per row. Every document header in this repository carries a
     * unique `code` (CONVENTIONS §4), which is the name an operator can check
     * against paper; "Laporan harian #17" is not.
     *
     * @param  list<Attachment>  $photos
     * @return array<class-string, array<int, string>>
     */
    private function documentCodes(array $photos): array
    {
        $byClass = [];

        foreach ($photos as $photo) {
            $byClass[$photo->attachable_type][] = (int) $photo->attachable_id;
        }

        $codes = [];

        foreach ($byClass as $class => $ids) {
            $codes[$class] = $class::query()->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->pluck('code', 'id')
                ->all();
        }

        return $codes;
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function siteCoordinates(Project $project): ?array
    {
        if (! Geotag::isValidLatitude($project->latitude) || ! Geotag::isValidLongitude($project->longitude)) {
            return null;
        }

        return ['latitude' => (float) $project->latitude, 'longitude' => (float) $project->longitude];
    }

    /**
     * @param  array<class-string, array<int, string>>  $codes
     * @param  array{latitude: float, longitude: float}|null  $site
     */
    private function row(Attachment $photo, array $codes, ?array $site): array
    {
        $slug = $photo->documentSlug();

        $distance = null;

        if ($site !== null && $photo->latitude !== null && $photo->longitude !== null) {
            $distance = (int) round(Geotag::distanceMetres(
                (float) $photo->latitude,
                (float) $photo->longitude,
                $site['latitude'],
                $site['longitude'],
            ));
        }

        return [
            'id' => $photo->id,
            'original_name' => $photo->original_name,
            'caption' => $photo->caption,
            'mime' => $photo->mime,
            'size_bytes' => $photo->size_bytes,
            // The date the gallery sorts and groups by: capture date when the
            // camera recorded one, upload date otherwise.
            'date' => ($photo->taken_at ?? $photo->created_at)?->toDateString(),
            'taken_at' => $photo->taken_at,
            'created_at' => $photo->created_at,
            'uploader' => $photo->uploader?->only(['id', 'name']),
            'latitude' => $photo->latitude,
            'longitude' => $photo->longitude,
            'accuracy_m' => $photo->accuracy_m,
            'geo_source' => $photo->geo_source,
            'distance_from_site_m' => $distance,
            'document' => [
                'slug' => $slug,
                'id' => (int) $photo->attachable_id,
                'label' => $slug !== null ? AttachableDocuments::labelFor($slug) : 'Dokumen',
                'code' => $codes[$photo->attachable_type][(int) $photo->attachable_id] ?? null,
            ],
        ];
    }
}
