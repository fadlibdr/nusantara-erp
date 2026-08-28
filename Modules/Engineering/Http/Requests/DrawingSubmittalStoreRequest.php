<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\ReviewerParty;

class DrawingSubmittalStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Per-field shape only; superseding the previous revision and mirroring
     * the register live in DrawingSubmittalService. Decision columns are
     * deliberately not accepted here — a stamp is recorded through the
     * decision endpoint, by someone else.
     */
    public function rules(): array
    {
        return [
            'drawing_id' => ['required', 'integer', Rule::exists('eng_drawings', 'id')],
            'revision' => [
                'required', 'string', 'max:10',
                Rule::unique('eng_drawing_submittals', 'revision')->where('drawing_id', (int) $this->input('drawing_id')),
            ],
            'submitted_at' => ['required', 'string', 'date'],
            'reviewer_party' => ['required', Rule::enum(ReviewerParty::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'revision.unique' => 'Revisi ini sudah pernah diajukan untuk gambar yang sama.',
        ];
    }
}
