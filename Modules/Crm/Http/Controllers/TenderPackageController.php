<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Crm\Http\Requests\TenderChecklistRequest;
use Modules\Crm\Http\Requests\TenderDocumentsRequest;
use Modules\Crm\Http\Requests\TenderPackageStoreRequest;
use Modules\Crm\Http\Requests\TenderPackageUpdateRequest;
use Modules\Crm\Http\Resources\TenderPackageResource;
use Modules\Crm\Models\TenderPackage;
use Modules\Crm\Services\TenderPackageService;

/**
 * Berkas satu lelang: register dokumennya, BA aanwijzing, checklist
 * kelengkapan. Bukan dokumen ber-persetujuan — lihat migrasi 000386.
 */
class TenderPackageController extends ApiController
{
    public function __construct(private readonly TenderPackageService $packages) {}

    public function index(Request $request): JsonResponse
    {
        $query = TenderPackage::query()
            ->with(['lead:id,code,name,company_name'])
            ->when($request->filled('lead_id'), fn ($q) => $q->where('lead_id', $request->integer('lead_id')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('code', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('tender_number', 'like', $term)
                    ->orWhere('owner_name', 'like', $term));
            })
            // Batas pemasukan terdekat lebih dulu: "yang mana yang harus
            // dikejar" adalah pertanyaan yang dijawab daftar ini.
            ->orderByRaw('submission_deadline is null, submission_deadline')
            ->orderByDesc('id');

        return $this->listing($request, $query, TenderPackageResource::class,
            sortable: ['code', 'title', 'submission_deadline', 'aanwijzing_date'],
            dateColumn: 'submission_deadline');
    }

    public function store(TenderPackageStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $package = $this->packages->create($data, $request->user());

        // Layar generik mengirim kepala + baris dalam satu simpanan; aturan
        // urutan addendum tetap dijalankan service, jadi register yang melompat
        // ditolak dengan 422 yang sama.
        if (array_key_exists('documents', $data) && is_array($data['documents'])) {
            $this->packages->replaceDocuments($package, $data['documents']);
        }

        return $this->created(
            TenderPackageResource::make($package->load(['lead', 'documents'])),
            'Paket tender dibuat.',
        );
    }

    public function show(TenderPackage $tenderPackage): JsonResponse
    {
        return $this->ok(TenderPackageResource::make($tenderPackage->load(['lead', 'documents'])));
    }

    public function update(TenderPackageUpdateRequest $request, TenderPackage $tenderPackage): JsonResponse
    {
        $data = $request->validated();
        $this->packages->update($tenderPackage, $data);

        if (array_key_exists('documents', $data) && is_array($data['documents'])) {
            $this->packages->replaceDocuments($tenderPackage, $data['documents']);
        }

        return $this->ok(
            TenderPackageResource::make($tenderPackage->load(['lead', 'documents'])),
            'Paket tender diperbarui.',
        );
    }

    public function destroy(TenderPackage $tenderPackage): JsonResponse
    {
        $tenderPackage->delete();

        return $this->ok(null, 'Paket tender dihapus.');
    }

    /** Register dokumen lelang — diganti utuh, urutan addendum dijaga service. */
    public function replaceDocuments(TenderDocumentsRequest $request, TenderPackage $tenderPackage): JsonResponse
    {
        $this->packages->replaceDocuments($tenderPackage, $request->validated()['documents']);

        return $this->ok(
            TenderPackageResource::make($tenderPackage->load(['lead', 'documents'])),
            'Register dokumen lelang diperbarui.',
        );
    }

    /** Template kelengkapan apa adanya — supaya layar tidak menyalinnya. */
    public function checklistTemplate(): JsonResponse
    {
        return $this->ok($this->packages->template());
    }

    public function checklist(TenderPackage $tenderPackage): JsonResponse
    {
        return $this->ok($this->packages->checklist($tenderPackage));
    }

    public function setChecklist(TenderChecklistRequest $request, TenderPackage $tenderPackage): JsonResponse
    {
        $this->packages->setChecklist($tenderPackage, $request->validated()['checklist']);

        return $this->ok(
            $this->packages->checklist($tenderPackage->fresh()),
            'Checklist kelengkapan disimpan.',
        );
    }
}
