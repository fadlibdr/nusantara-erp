<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArInvoiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // From-termin mode: termin_id present, header amounts derived.
            'termin_id' => ['nullable', 'integer', Rule::exists('crm_contract_termins', 'id')],
            // Temuan #32: pengakuan sadar bahwa milestone syarat termin belum
            // tercapai. Harus lolos validated() agar sampai ke
            // ArInvoiceService::createFromTermin — tanpa baris ini flag dari
            // alur konfirmasi SPA terbuang dan 422-nya berulang tanpa jalan
            // keluar.
            'confirm_unachieved_milestone' => ['sometimes', 'boolean'],
            'withhold_retention' => ['nullable', 'boolean'],

            // Manual mode: customer/contract/dpp required when no termin given.
            'customer_id' => ['required_without:termin_id', 'integer', Rule::exists('crm_customers', 'id')],
            'contract_id' => ['required_without:termin_id', 'integer', Rule::exists('crm_contracts', 'id')],
            'project_id' => ['nullable', 'integer'],
            'description' => ['required_without:termin_id', 'string', 'max:500'],
            'dpp' => ['required_without:termin_id', 'numeric', 'min:0.01'],
            'ppn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Common
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'retention_withheld' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
