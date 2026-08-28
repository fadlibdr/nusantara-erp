<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\GatePassDirection;

class GatePassStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * vendor_id and counterparty are BOTH nullable on purpose: a registered
     * vendor is referenced, an unregistered counterparty is written out, and
     * an internal movement (transfer between own warehouses, transfer_id set)
     * may honestly have neither — the sheet then rules ASAL/TUJUAN blank.
     *
     * goods_receipt_id / transfer_id are shared-ID references (no FK): the
     * pass POINTS AT the inventory document it escorts, it does not own it.
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'direction' => ['required', Rule::enum(GatePassDirection::class)],
            'pass_date' => ['required', 'string', 'date'],
            'vehicle_no' => ['nullable', 'string', 'max:20'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('prc_vendors', 'id')],
            'counterparty' => ['nullable', 'string', 'max:200'],
            'goods_receipt_id' => ['nullable', 'integer', 'min:1'],
            'transfer_id' => ['nullable', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['required', 'string', 'max:20'],
            'items.*.notes' => ['nullable', 'string', 'max:200'],
        ];
    }
}
