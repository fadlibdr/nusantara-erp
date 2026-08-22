<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\VendorEvaluationStoreRequest;
use Modules\Procurement\Http\Requests\VendorEvaluationUpdateRequest;
use Modules\Procurement\Http\Resources\VendorEvaluationResource;
use Modules\Procurement\Models\VendorEvaluation;
use Modules\Procurement\Services\VendorEvaluationService;

class VendorEvaluationController extends ApiController
{
    public function __construct(private readonly VendorEvaluationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = VendorEvaluation::query()
            ->with('vendor')
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('period'), fn ($query) => $query->where('period', $request->string('period')))
            ->orderByDesc('id');

        return $this->listing($request, $query, VendorEvaluationResource::class,
            sortable: ['period', 'quality_score', 'delivery_score', 'price_score', 'service_score', 'total_score']);
    }

    public function store(VendorEvaluationStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['evaluated_by'] = $data['evaluated_by'] ?? $request->user()?->id;

        // delivery_score null yang lolos validasi harus benar-benar absen,
        // atau isset() di service membacanya sebagai "sudah diisi".
        if (! isset($data['delivery_score'])) {
            unset($data['delivery_score']);
        }

        $evaluation = $this->service->create($data);

        return $this->created(VendorEvaluationResource::make($evaluation));
    }

    /**
     * Saran skor kirim dari bukti GRN vs tanggal janji PO, untuk ditampilkan
     * form evaluasi SEBELUM evaluator mengisi. data null = vendor belum punya
     * riwayat yang bisa dinilai — jawaban jujur, bukan skor karangan.
     */
    public function deliverySuggestion(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => ['required', 'integer']]);

        return $this->ok($this->service->deliverySnapshot($request->integer('vendor_id')));
    }

    public function show(VendorEvaluation $vendorEvaluation): JsonResponse
    {
        return $this->ok(VendorEvaluationResource::make($vendorEvaluation->load('vendor', 'evaluator')));
    }

    public function update(VendorEvaluationUpdateRequest $request, VendorEvaluation $vendorEvaluation): JsonResponse
    {
        $evaluation = $this->service->update($vendorEvaluation, $request->validated());

        return $this->ok(VendorEvaluationResource::make($evaluation));
    }

    public function destroy(VendorEvaluation $vendorEvaluation): JsonResponse
    {
        $this->service->delete($vendorEvaluation);

        return $this->ok(null, 'Vendor evaluation deleted.');
    }
}
