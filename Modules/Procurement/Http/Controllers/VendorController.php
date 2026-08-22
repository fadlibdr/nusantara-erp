<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\VendorStoreRequest;
use Modules\Procurement\Http\Requests\VendorUpdateRequest;
use Modules\Procurement\Http\Resources\VendorResource;
use Modules\Procurement\Models\Vendor;

class VendorController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Vendor::query()
            // Satu subquery untuk bendera "dok. wajib kedaluwarsa" pada
            // picker_label — bukan satu query per vendor. Setengah-terbuka
            // '<' hari-ini: "berlaku s/d" masih sah PADA hari terakhirnya,
            // bacaan yang sama dengan VendorQualificationService.
            ->withCount(['documents as expired_mandatory_documents_count' => fn ($documents) => $documents
                ->where('is_mandatory', true)
                ->whereNotNull('valid_until')
                ->where('valid_until', '<', now()->toDateString())])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('legal_name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('classification'), fn ($query) => $query->where('classification', $request->string('classification')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->has('is_subcontractor'), fn ($query) => $query->where('is_subcontractor', $request->boolean('is_subcontractor')))
            ->orderBy('code');

        return $this->listing($request, $query, VendorResource::class,
            sortable: ['code', 'name', 'classification', 'rating', 'status']);
    }

    public function store(VendorStoreRequest $request): JsonResponse
    {
        $vendor = Vendor::query()->create($request->validated());

        return $this->created(VendorResource::make($vendor));
    }

    public function show(Vendor $vendor): JsonResponse
    {
        return $this->ok(VendorResource::make($vendor));
    }

    public function update(VendorUpdateRequest $request, Vendor $vendor): JsonResponse
    {
        $vendor->update($request->validated());

        return $this->ok(VendorResource::make($vendor));
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        if ($vendor->purchaseOrders()->exists()) {
            return $this->error("Vendor {$vendor->code} has purchase orders and cannot be deleted; set it inactive instead.");
        }

        $vendor->delete();

        return $this->ok(null, 'Vendor deleted.');
    }
}
