<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Models\Project;

class MilestoneUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'due_date' => ['sometimes', 'date'],
            'achieved_date' => ['nullable', 'date'],
            'termin_id' => ['nullable', 'integer', $this->terminBelongsToThisProject()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'termin_id.exists' => 'Termin yang dipilih bukan termin kontrak proyek ini.',
        ];
    }

    /**
     * Same guard as on create, read from the milestone being edited: the project
     * cannot be moved here, so its contract is the one that decides which
     * termins this milestone is allowed to release.
     */
    private function terminBelongsToThisProject(): object
    {
        $milestone = $this->route('milestone');

        $contractId = $milestone instanceof Milestone
            ? Project::query()->whereKey($milestone->project_id)->value('contract_id')
            : null;

        return Rule::exists('crm_contract_termins', 'id')->where('contract_id', $contractId);
    }
}
