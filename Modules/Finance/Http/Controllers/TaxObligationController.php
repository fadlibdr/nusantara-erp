<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\TaxObligationUpdateRequest;
use Modules\Finance\Http\Resources\TaxObligationResource;
use Modules\Finance\Models\TaxObligation;
use Modules\Finance\Services\TaxObligationService;

class TaxObligationController extends ApiController
{
    public function __construct(private readonly TaxObligationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = TaxObligation::query()
            ->when($request->filled('year'), fn ($query) => $query->where('masa_year', $request->integer('year')))
            ->when($request->filled('tax_type'), fn ($query) => $query->where('tax_type', $request->string('tax_type')))
            // Status is derived from the dates, so the filter asks the dates.
            ->when($request->string('status')->toString() === 'belum', fn ($query) => $query
                ->whereNull('disetor_date')->whereNull('dilapor_date'))
            ->when($request->string('status')->toString() === 'disetor', fn ($query) => $query
                ->whereNotNull('disetor_date')->whereNull('dilapor_date'))
            ->when($request->string('status')->toString() === 'dilapor', fn ($query) => $query
                ->whereNotNull('dilapor_date'))
            ->with('journal')
            ->orderBy('due_date')
            ->orderBy('tax_type');

        return $this->listing($request, $query, TaxObligationResource::class,
            sortable: ['due_date', 'masa_year', 'masa_month', 'tax_type', 'amount'], dateColumn: 'due_date');
    }

    /**
     * Mint the year's masa rows — idempotent, never touches manual entries.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100']]);

        $created = $this->service->ensureYear((int) $data['year']);

        return $this->ok(
            ['created' => $created, 'year' => (int) $data['year']],
            $created > 0
                ? "{$created} kewajiban masa {$data['year']} dibuat."
                : "Kalender {$data['year']} sudah lengkap.",
        );
    }

    public function update(TaxObligationUpdateRequest $request, TaxObligation $taxObligation): JsonResponse
    {
        try {
            $row = $this->service->update($taxObligation, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(TaxObligationResource::make($row->load('journal')));
    }
}
