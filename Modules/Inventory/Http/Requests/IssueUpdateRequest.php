<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Issue;

/**
 * Extends the store request so the WBS attribution guard — which is the whole
 * reason inv_issue_items.wbs_task_id can be trusted — exists in exactly one
 * place. Two things differ: every field is optional here, and the project is
 * not writable at all — an issue keeps the project it was created with, and
 * every WBS task named in an update is checked against that stored project.
 */
class IssueUpdateRequest extends IssueStoreRequest
{
    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'required', 'integer', Rule::exists('inv_warehouses', 'id')],
            // project_id is deliberately NOT a rule — same contract as
            // DefectUpdateRequest: the project cannot be moved by an update.
            // FormRequest::validated() only returns validated keys, so a
            // payload project_id never reaches IssueService::update. When it
            // WAS writable, PUT {"project_id": <other>} with no items re-homed
            // the header while every stored line kept the OLD project's WBS
            // ids (lines are only rewritten when `items` is sent) — a
            // PRJ-2026-002 bon posting Rp 9.440.000 tagged to PRJ-2026-001's
            // B.3, permanent once posted. A draft charged to the wrong project
            // is deleted and raised again.
            'wbs_task_id' => $this->wbsTaskRules(),
            // Changeable on a draft (pointing the bon at its permit, or
            // detaching it — the detach re-runs the confirm_without_ipp
            // warning in IssueService); 'sometimes' so an update that does not
            // mention the permit leaves it alone.
            'ipp_id' => array_merge(['sometimes'], $this->crossModuleId('eng_work_permits_ipp')),
            'issue_date' => ['sometimes', 'required', 'date'],
            'purpose' => ['sometimes', 'required', 'string', 'max:500'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'integer', Rule::exists('inv_items', 'id')],
            'items.*.wbs_task_id' => $this->wbsTaskRules(),
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.001'],
        ];
    }

    /**
     * Always the project already stored on the issue — never the payload.
     *
     * The project cannot be moved by an update (see rules()), so the stored
     * value is the project this document will still have after the request.
     * Honouring a payload project_id here would reopen the hole by the side
     * door: {"project_id": <other>, "wbs_task_id": <other's leaf>} would
     * validate the task against a project the issue does not have and will
     * not get.
     */
    protected function issueProjectId(): ?int
    {
        $issue = $this->route('issue');

        if (! $issue instanceof Issue || $issue->project_id === null) {
            return null;
        }

        return (int) $issue->project_id;
    }
}
