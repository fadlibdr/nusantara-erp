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

            // P3 — from-opname mode: the header amounts come off the approved
            // measurement, so customer/contract/dpp are not required with it
            // (required_without covers termin_id; measurement_id joins it).
            'measurement_id' => ['nullable', 'integer', Rule::exists('prj_progress_measurements', 'id')],

            // Manual mode: customer/contract/dpp required when no termin and no
            // opname is given.
            'customer_id' => ['required_without_all:termin_id,measurement_id', 'integer', Rule::exists('crm_customers', 'id')],
            'contract_id' => ['required_without_all:termin_id,measurement_id', 'integer', Rule::exists('crm_contracts', 'id')],
            'project_id' => ['nullable', 'integer'],
            'description' => ['required_without_all:termin_id,measurement_id', 'string', 'max:500'],
            'dpp' => ['required_without_all:termin_id,measurement_id', 'numeric', 'min:0.01'],
            'ppn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // P3 — the uang muka door. Manual only: an advance has no termin
            // and no opname behind it.
            'is_advance' => ['nullable', 'boolean'],

            // Common
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'retention_withheld' => ['nullable', 'numeric', 'min:0'],
            // P3 — denda. The reason is required BY THE SERVICE whenever the
            // amount is non-zero (ArInvoiceService::assertPenaltyIsAccountedFor)
            // rather than here, so every door into the invoice — store, update,
            // opname claim — passes the same gate instead of three copies of it.
            'penalty_amount' => ['nullable', 'numeric', 'min:0'],
            'penalty_reason' => ['nullable', 'string', 'max:300'],
        ];
    }
}
