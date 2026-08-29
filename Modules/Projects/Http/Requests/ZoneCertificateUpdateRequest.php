<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\CertifyingParty;
use Modules\Projects\Enums\ZoneCertificateStatus;

class ZoneCertificateUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'integer', Rule::exists('core_locations', 'id')],
            'status' => ['sometimes', Rule::enum(ZoneCertificateStatus::class)],
            'certified_at' => ['sometimes', 'nullable', 'string', 'date'],
            'certified_by_party' => ['sometimes', 'nullable', Rule::enum(CertifyingParty::class)],
            'certified_by_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
