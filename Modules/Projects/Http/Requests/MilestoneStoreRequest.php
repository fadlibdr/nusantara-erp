<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\Project;

class MilestoneStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'due_date' => ['required', 'date'],
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
     * A milestone may only release a termin of ITS OWN project's contract.
     *
     * The rule was 'integer|min:1' — an unchecked foreign key across a module
     * boundary. Any number passed, including the id of another customer's
     * termin, and nothing downstream re-checked it: the achievement alert would
     * name the wrong contract, and finance would be told to invoice a job that
     * had not moved. Silent, and only visible once the invoice was out.
     */
    private function terminBelongsToThisProject(): object
    {
        $contractId = Project::query()
            ->whereKey($this->integer('project_id'))
            ->value('contract_id');

        // A missing project, or a project with no contract, leaves $contractId
        // null — Rule::exists turns that into whereNull('contract_id'), which
        // matches nothing. That is the right answer: a termin cannot belong to
        // a contract that is not there.
        return Rule::exists('crm_contract_termins', 'id')->where('contract_id', $contractId);
    }
}
