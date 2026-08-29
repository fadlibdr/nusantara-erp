<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProgressMeasurementStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Per-field shape only. Everything that WEIGHS two values — the ceiling
     * against the contract and its approved CCO volumes, qty_prev against the
     * approved history, the period bounds — lives in MeasurementService, one
     * implementation for store and update (the DailyReportService split).
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            // 'string' before 'date': a JSON number would survive 'date' and be
            // cast as a UNIX timestamp (the DailyReportStoreRequest lesson).
            'period_start' => ['required', 'string', 'date'],
            'period_end' => ['required', 'string', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.boq_item_id' => ['required', 'integer', Rule::exists('est_boq_items', 'id')],
            'items.*.location_id' => ['nullable', 'integer', Rule::exists('core_locations', 'id')],
            // Signed: a correction opname legitimately measures a negative
            // volume this period. The service refuses one that would drive the
            // cumulative below zero.
            'items.*.qty_this' => ['required', 'numeric'],
            'items.*.notes' => ['nullable', 'string', 'max:300'],
        ];
    }
}
