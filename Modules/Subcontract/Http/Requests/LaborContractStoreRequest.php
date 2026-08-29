<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontract\Enums\LaborPphScheme;

class LaborContractStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer'], // cross-module; the mandor check lives in the service
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            // pph21_ter valid secara BENTUK — service yang menolaknya 422
            // "belum diaktifkan" (pintu jujur asumsi #3), bukan validator.
            'pph_scheme' => ['required', Rule::enum(LaborPphScheme::class)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            // Alasan menembus gate prakualifikasi K3L/pakta (P0-E, berlaku
            // juga untuk mandor sejak P4); tersimpan hanya saat dipakai.
            'qualification_override_reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.wbs_code' => ['nullable', 'string', 'max:20'],
            'items.*.description' => ['required_without:items.*.boq_item_id', 'nullable', 'string', 'max:500'],
            'items.*.qty' => ['required_without:items.*.boq_item_id', 'nullable', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_rate' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
