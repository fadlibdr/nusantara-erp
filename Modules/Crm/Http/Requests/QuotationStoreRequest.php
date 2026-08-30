<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\ScopeType;

class QuotationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('crm_customers', 'id')],
            'lead_id' => ['nullable', 'integer', Rule::exists('crm_leads', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'scope_type' => ['required', Rule::enum(ScopeType::class)],
            'valid_until' => ['nullable', 'date'],
            /*
             * P7 — "Metode Pelaksanaan". Hanya keberadaannya yang diperiksa di
             * sini; aturan yang sebenarnya — rujukan harus ke versi yang
             * BERLAKU, bukan yang sudah digantikan — ada di QuotationService,
             * karena sebuah Rule::exists tidak bisa menyatakannya dan seeder
             * yang memanggil service langsung harus tunduk pada aturan yang
             * sama. Tanpa baris ini kuncinya dibuang validated() tanpa suara:
             * penawaran tersimpan TANPA metode, dan tidak ada yang keliru
             * terlihat di mana pun.
             */
            'method_library_id' => ['nullable', 'integer', Rule::exists('core_method_library', 'id')->whereNull('deleted_at')],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'ppn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
