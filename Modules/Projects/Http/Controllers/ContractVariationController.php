<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Resources\ContractVariationResource;
use Modules\Projects\Models\ContractVariation;

/**
 * Register volume tambah-kurang per item BOQ — the half of the opname ceiling
 * a change order's signed VALUE cannot express. See the migration for why the
 * table exists and why it lives in Projects.
 *
 * Deliberately a small register and not a document: it has no lifecycle of its
 * own, because the lifecycle that matters is the CHANGE ORDER's, and
 * MeasurementService counts only rows whose CCO is approved. Write rights are
 * prj.update — the QS who reads the addendum BOQ, the same right that records
 * measured volume.
 */
class ContractVariationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ContractVariation::query()
            ->with(['changeOrder', 'boqItem'])
            ->when($request->filled('contract_id'), fn ($query) => $query->where('contract_id', $request->integer('contract_id')))
            ->when($request->filled('change_order_id'), fn ($query) => $query->where('change_order_id', $request->integer('change_order_id')))
            ->when($request->filled('boq_item_id'), fn ($query) => $query->where('boq_item_id', $request->integer('boq_item_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, ContractVariationResource::class,
            sortable: ['id', 'qty_change']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contract_id' => ['required', 'integer', Rule::exists('crm_contracts', 'id')],
            'change_order_id' => ['required', 'integer', Rule::exists('crm_contract_change_orders', 'id')],
            'boq_item_id' => ['required', 'integer', Rule::exists('est_boq_items', 'id')],
            // SIGNED: pekerjaan kurang lowers the ceiling.
            'qty_change' => ['required', 'numeric'],
            'unit' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $existing = ContractVariation::query()
            ->where('change_order_id', $data['change_order_id'])
            ->where('boq_item_id', $data['boq_item_id'])
            ->exists();

        if ($existing) {
            return $this->error(
                'Item BOQ ini sudah tercatat pada CCO tersebut; ubah barisnya, jangan menambah baris kedua — '
                .'dua baris untuk satu pasangan akan menggandakan plafon opname.'
            );
        }

        $variation = ContractVariation::query()->create($data);

        return $this->created(ContractVariationResource::make($variation->load(['changeOrder', 'boqItem'])));
    }

    public function update(Request $request, ContractVariation $contractVariation): JsonResponse
    {
        $data = $request->validate([
            'qty_change' => ['sometimes', 'numeric'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $contractVariation->fill($data)->save();

        return $this->ok(ContractVariationResource::make($contractVariation->load(['changeOrder', 'boqItem'])));
    }

    public function destroy(ContractVariation $contractVariation): JsonResponse
    {
        $contractVariation->delete();

        return $this->ok(null, 'Volume tambah-kurang dihapus.');
    }
}
