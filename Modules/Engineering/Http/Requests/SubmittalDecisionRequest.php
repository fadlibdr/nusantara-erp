<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\SubmittalDecision;

/**
 * The MK's stamp, typed in — shared by both submittal types: the four values,
 * the stamp's date, its notes verbatim. Who may type it (eng.approve, not the
 * creator) is the route middleware plus the service's maker-checker guard.
 */
class SubmittalDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(SubmittalDecision::class)],
            'decided_at' => ['required', 'string', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.*' => 'Keputusan harus salah satu dari empat stempel: approved, approved_as_noted, revise_resubmit, rejected.',
        ];
    }
}
