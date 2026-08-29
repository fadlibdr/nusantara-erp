<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AwardDecisionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rfq_id' => ['required', 'integer', Rule::exists('prc_rfqs', 'id')->whereNull('deleted_at')],
            'vendor_id' => ['required', 'integer', Rule::exists('prc_vendors', 'id')->whereNull('deleted_at')],
            'rab_amount' => ['required', 'numeric', 'min:0'],
            'awarded_amount' => ['required', 'numeric', 'min:0'],
            // Wajib-bila-deviasi ditegakkan di AwardDecisionService (butuh nilai
            // terhitung), bukan di sini — pesan penolakannya menyebut alasannya.
            'deviation_reason' => ['nullable', 'string', 'max:500'],
            'committee' => ['nullable', 'array'],
            'committee.*.nama' => ['required_with:committee', 'string', 'max:120'],
            'committee.*.jabatan' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
