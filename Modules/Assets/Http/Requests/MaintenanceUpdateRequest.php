<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Enums\MaintenanceType;

class MaintenanceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['sometimes', 'integer', Rule::exists('ast_assets', 'id')],
            'maintenance_date' => ['sometimes', 'date'],
            'maintenance_type' => ['sometimes', Rule::enum(MaintenanceType::class)],
            'vendor_id' => ['nullable', 'integer'], // cross-module: prc_vendors.id
            'cost' => ['sometimes', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'next_due_date' => ['nullable', 'date'],
            // F-7 — alasan gt:0 dan decimal:0,3 ada di MaintenanceStoreRequest:
            // nol adalah angka, "belum disetel" dikatakan dengan NULL, dan
            // 0,0004 yang dibulatkan kolomnya menjadi nol harus ditolak di
            // KEDUA pintu tulis, bukan hanya di pintu buat.
            'next_due_hour_meter' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'],
        ];
    }
}
