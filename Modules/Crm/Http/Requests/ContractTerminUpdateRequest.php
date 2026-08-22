<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deliberately two fields wide.
 *
 * A termin's amount and percent are the basis of every invoice raised against
 * the contract and belong to the schedule as a whole — they move through
 * ContractService, which checks that the percents still cover 100% and refuses
 * once anything is billed. What is safe to correct on a single row is when it
 * falls due and the wording of the condition, so that is all this accepts.
 */
class ContractTerminUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'due_date' => ['nullable', 'date'],
            'billing_condition' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
