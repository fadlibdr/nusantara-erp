<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Http\Requests\MethodLibraryStoreRequest;
use Modules\Core\Http\Requests\MethodLibraryUpdateRequest;
use Modules\Core\Http\Resources\MethodLibraryEntryResource;
use Modules\Core\Models\MethodLibraryEntry;
use Modules\Core\Services\MethodLibraryService;

/**
 * Pustaka metode kerja. Izin est.* dan bukan core.*, dengan alasan yang tertulis
 * pada migrasi 000191 (pola core_locations yang memakai prj.*).
 */
class MethodLibraryController extends ApiController
{
    public function __construct(private readonly MethodLibraryService $library) {}

    public function index(Request $request): JsonResponse
    {
        $query = MethodLibraryEntry::query()
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            // Bawaan: hanya versi yang berlaku. Sebuah daftar yang secara
            // bawaan memuat setiap versi lama membuat layar penawaran memilih
            // dari dokumen yang sudah ditarik.
            ->when(! $request->boolean('with_superseded'), fn ($q) => $q->whereNull('superseded_by_id'))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('code', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('work_package', 'like', $term));
            })
            ->orderBy('category')
            ->orderBy('work_package')
            ->orderByDesc('version');

        return $this->listing($request, $query, MethodLibraryEntryResource::class,
            sortable: ['code', 'category', 'work_package', 'version', 'effective_date'],
            dateColumn: 'effective_date');
    }

    public function store(MethodLibraryStoreRequest $request): JsonResponse
    {
        $entry = $this->library->create($request->validated(), $request->user());

        return $this->created(MethodLibraryEntryResource::make($entry), 'Metode kerja ditambahkan ke pustaka.');
    }

    public function show(MethodLibraryEntry $methodLibrary): JsonResponse
    {
        return $this->ok(MethodLibraryEntryResource::make($methodLibrary->load('supersededBy')));
    }

    public function update(MethodLibraryUpdateRequest $request, MethodLibraryEntry $methodLibrary): JsonResponse
    {
        $this->library->update($methodLibrary, $request->validated());

        return $this->ok(MethodLibraryEntryResource::make($methodLibrary), 'Metode kerja diperbarui.');
    }

    /**
     * Terbitkan versi berikutnya. Bukan PUT: yang terjadi adalah pembuatan
     * baris baru dan penstempelan yang lama, bukan penyuntingan.
     */
    public function publishRevision(MethodLibraryUpdateRequest $request, MethodLibraryEntry $methodLibrary): JsonResponse
    {
        $next = $this->library->publishRevision($methodLibrary, $request->validated(), $request->user());

        return $this->created(
            MethodLibraryEntryResource::make($next),
            "Revisi diterbitkan sebagai versi {$next->version} ({$next->code}).",
        );
    }

    public function destroy(MethodLibraryEntry $methodLibrary): JsonResponse
    {
        $methodLibrary->delete();

        return $this->ok(null, 'Metode kerja dihapus dari pustaka.');
    }
}
