<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\ReviewerParty;

class DrawingSubmittalUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $submittal = $this->route('drawingSubmittal');

        return [
            'revision' => [
                'sometimes', 'string', 'max:10',
                Rule::unique('eng_drawing_submittals', 'revision')
                    ->where('drawing_id', (int) $submittal?->drawing_id)
                    ->ignore($submittal?->getKey()),
            ],
            'submitted_at' => ['sometimes', 'string', 'date'],
            'reviewer_party' => ['sometimes', Rule::enum(ReviewerParty::class)],
        ];
    }
}
