<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Hanya PPK dan rentang tanggal: kuantitas dan rupiahnya DITURUNKAN oleh
 * WorkOrderBillingService dari register hour-meter dan kalender — tidak ada
 * field angka untuk diketik di sini, dengan sengaja.
 */
class WorkOrderBillingStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['required', 'integer', Rule::exists('prc_work_orders', 'id')->whereNull('deleted_at')],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
