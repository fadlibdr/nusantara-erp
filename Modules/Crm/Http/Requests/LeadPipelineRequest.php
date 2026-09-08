<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\LeadStatus;

/**
 * Muatan perpindahan tahap. Sengaja tipis: `status` harus nilai enum yang
 * sungguhan, dan alasan boleh kosong DI SINI — yang memutuskan wajib atau
 * tidaknya adalah LeadPipelineService, karena itu bergantung pada arah
 * perpindahannya, bukan pada bentuk muatannya.
 */
class LeadPipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(LeadStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
