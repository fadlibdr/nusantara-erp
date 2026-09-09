<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Enums\MaintenanceType;

class MaintenanceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer', Rule::exists('ast_assets', 'id')],
            'maintenance_date' => ['required', 'date'],
            'maintenance_type' => ['required', Rule::enum(MaintenanceType::class)],
            'vendor_id' => ['nullable', 'integer'], // cross-module: prc_vendors.id
            'cost' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'next_due_date' => ['nullable', 'date', 'after:maintenance_date'],
            /*
             * F-7 — pemicu KEDUA, berdiri sendiri: sebuah kartu servis boleh
             * mengisi tanggal saja, jam saja, keduanya, atau tidak sama sekali
             * (yang terakhir tetap diteriaki pengawas tenggat).
             *
             * gt:0, BUKAN min:0. Nol adalah ANGKA — registri ambang tidak
             * pernah menyimpulkan "tidak ada batas" dari nilai nol (§24) — dan
             * "servis berikutnya pada jam ke-0" bukan kalimat yang berarti
             * apa pun untuk mesin mana pun. Menerimanya akan melahirkan
             * keadaan TANPA_ANGGARAN ("Tidak dianggarkan") di sisi jam,
             * tempat kalimat itu tidak punya arti. Yang berarti "belum
             * disetel" adalah NULL.
             */
            'next_due_hour_meter' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
