<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\VendorDocumentStoreRequest;
use Modules\Procurement\Http\Requests\VendorDocumentUpdateRequest;
use Modules\Procurement\Http\Resources\VendorDocumentResource;
use Modules\Procurement\Models\VendorDocument;

/**
 * Register dokumen prakualifikasi vendor (temuan #35/#69 — satu register).
 * CRUD polos: masa berlaku hidup di kolom valid_until, gate-nya di
 * VendorQualificationService, pengingatnya di deadline-watch.
 */
class VendorDocumentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = VendorDocument::query()
            ->with('vendor')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('name', 'like', "%{$q}%")
                        ->orWhere('number', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('doc_type'), fn ($query) => $query->where('doc_type', $request->string('doc_type')))
            ->when($request->has('is_mandatory'), fn ($query) => $query->where('is_mandatory', $request->boolean('is_mandatory')))
            // expired=1: lewat hari terakhir masa berlakunya ("berlaku s/d"
            // masih sah PADA hari itu); tanpa tanggal berarti tidak pernah
            // kedaluwarsa, jadi tidak ikut.
            ->when($request->boolean('expired'), fn ($query) => $query
                ->whereNotNull('valid_until')
                ->where('valid_until', '<', now()->toDateString()))
            // Yang paling dekat kedaluwarsa di atas; tanpa tanggal di bawah.
            ->orderByRaw('valid_until is null')
            ->orderBy('valid_until')
            ->orderBy('id');

        return $this->listing($request, $query, VendorDocumentResource::class,
            sortable: ['name', 'doc_type', 'valid_until']);
    }

    public function store(VendorDocumentStoreRequest $request): JsonResponse
    {
        $document = VendorDocument::query()->create($request->validated());

        return $this->created(VendorDocumentResource::make($document->load('vendor')));
    }

    public function show(VendorDocument $vendorDocument): JsonResponse
    {
        return $this->ok(VendorDocumentResource::make($vendorDocument->load('vendor')));
    }

    public function update(VendorDocumentUpdateRequest $request, VendorDocument $vendorDocument): JsonResponse
    {
        // vendor_id absen dari rules: sebuah SBU tidak pindah pemilik lewat
        // koreksi formulir.
        $vendorDocument->update($request->validated());

        return $this->ok(VendorDocumentResource::make($vendorDocument->load('vendor')));
    }

    public function destroy(VendorDocument $vendorDocument): JsonResponse
    {
        $vendorDocument->delete();

        return $this->ok(null, 'Dokumen vendor dihapus.');
    }
}
