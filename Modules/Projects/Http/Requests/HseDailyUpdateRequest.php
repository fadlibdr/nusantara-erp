<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HseDailyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // project_id sengaja tidak ada: formulir K3 tidak pindah proyek
            // (service membuangnya juga).
            'report_date' => ['sometimes', 'string', 'date'],
            'toolbox_topic' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'],
            ...HseDailyStoreRequest::lineRules(),
        ];
    }

    public function messages(): array
    {
        return HseDailyStoreRequest::lineMessages();
    }
}
