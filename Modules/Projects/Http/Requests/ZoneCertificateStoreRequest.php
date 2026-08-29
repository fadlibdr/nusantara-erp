<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\CertifyingParty;
use Modules\Projects\Enums\ZoneCertificateStatus;

class ZoneCertificateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Per-field shape only. The gate that WEIGHS this certificate against the
     * NCR register lives in ZoneCertificateService.
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'location_id' => ['required', 'integer', Rule::exists('core_locations', 'id')],
            'status' => ['required', Rule::enum(ZoneCertificateStatus::class)],
            'certified_at' => ['nullable', 'string', 'date'],
            'certified_by_party' => ['nullable', Rule::enum(CertifyingParty::class)],
            'certified_by_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
