<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class IssueStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('inv_warehouses', 'id')],
            'project_id' => $this->crossModuleId('prj_projects'),
            // eng_work_permits_ipp — existence here, everything with a message
            // (project match, approved-only, wbs inheritance and the
            // confirm_without_ipp warning) in IssueService::applyIppRules.
            'ipp_id' => $this->crossModuleId('eng_work_permits_ipp'),
            'wbs_task_id' => $this->wbsTaskRules(),
            'issue_date' => ['required', 'date'],
            'purpose' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            'items.*.wbs_task_id' => $this->wbsTaskRules(),
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    public function messages(): array
    {
        return [
            'wbs_task_id.exists' => 'Tugas WBS yang dipilih bukan bagian dari WBS proyek ini.',
            'items.*.wbs_task_id.exists' => 'Tugas WBS pada baris ini bukan bagian dari WBS proyek ini.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->refuseNonWorkPackageWbsTasks($validator);
        });
    }

    /**
     * Rules for a nullable id owned by another module (Projects).
     *
     * Same four lines as GoodsReceiptStoreRequest::crossModuleId(), copied per
     * house style. The rule was ['nullable', 'integer'] with a comment naming
     * the table instead of a check against it: project_id 999999 was accepted,
     * posted Rp 9.300.000 into 5-1100 HPP Material and fin_project_costs, and
     * that cost then belonged to NO project — invisible to EVM's AC, to
     * totalsByCategory and to the PSAK 115 cost base, while a no-project bon
     * would at least have landed honestly in 6-4100 overhead.
     *
     * @return array<int, mixed>
     */
    protected function crossModuleId(string $table): array
    {
        $rules = ['nullable', 'integer'];

        if (Schema::hasTable($table)) {
            $rules[] = Rule::exists($table, 'id')->whereNull('deleted_at');
        }

        return $rules;
    }

    /**
     * Rules for a WBS task id owned by another module (Projects).
     *
     * The rule was ['nullable', 'integer'], which is how the column came to be
     * worthless: 999999 passed, nothing downstream re-checked it, and the form
     * asked a storeman to type a raw primary key. A foreign key nobody
     * validates does not stay empty, it fills up with confident garbage — and a
     * material variance report cannot tell garbage from a real attribution.
     *
     * The task must belong to THIS issue's project. A null project narrows the
     * rule to whereNull('project_id') and therefore matches nothing, which is
     * the right answer: material charged to no project cannot be charged to one
     * of that project's work packages either.
     *
     * Guarded by Schema::hasTable so Inventory still validates on an
     * installation without Projects — that module owns the table, not us.
     *
     * @return array<int, mixed>
     */
    protected function wbsTaskRules(): array
    {
        $rules = ['nullable', 'integer'];

        if (Schema::hasTable('prj_wbs_tasks')) {
            $rules[] = Rule::exists('prj_wbs_tasks', 'id')->where('project_id', $this->issueProjectId());
        }

        return $rules;
    }

    /**
     * The project every WBS task on this document is checked against.
     */
    protected function issueProjectId(): ?int
    {
        $projectId = $this->integer('project_id');

        return $projectId > 0 ? $projectId : null;
    }

    /**
     * A work package is a LEAF that CARRIES A BOQ ITEM — anything else cannot
     * produce material theory, so tagging it fills a field the variance report
     * throws away.
     *
     * Two refusals, told apart so the storeman knows which mistake he made:
     *
     * PARENTS. ProjectService::generateWbsFromBoq builds parents out of BOQ
     * SECTIONS — on PRJ-2026-001 that is A, B and C. Material tagged to
     * "B Pekerjaan Struktur" belongs to one of B's children; say so.
     *
     * THEORY-LESS LEAVES. Being a leaf is not enough: theory = AHSP coefficient
     * x BOQ qty, joined through prj_wbs_tasks.boq_item_id, so a leaf whose
     * boq_item_id is null computes NO theory and its lines are dropped — or
     * reported as an infinite overrun — indistinguishably from an untagged bon.
     * On PRJ-2026-001 that is C.1 and C.2 (childless, boq_item_id null, 14,67%
     * of the project by weight): the migration's own worked example — 150 zak
     * Semen Portland "pasangan bata, WBS C.1" — used to pass this guard and
     * produce an uncomputable attribution. Checking has-children only, as this
     * method first did, waved both leaves through.
     *
     * The C.1/C.2 rows themselves are Projects' data to repair (backfill
     * boq_item_id or regenerate the WBS from the BOQ); refusing here is
     * forward-only and fabricates nothing.
     */
    protected function refuseNonWorkPackageWbsTasks(Validator $validator): void
    {
        if (! Schema::hasTable('prj_wbs_tasks')) {
            return;
        }

        foreach ($this->wbsTaskIdsByKey() as $key => $taskId) {
            if (DB::table('prj_wbs_tasks')->where('parent_id', $taskId)->exists()) {
                $validator->errors()->add(
                    $key,
                    'Tugas WBS yang dipilih masih punya sub-tugas; pilih paket pekerjaan paling bawah.',
                );

                continue;
            }

            $task = DB::table('prj_wbs_tasks')->where('id', $taskId)->first(['boq_item_id']);

            // A task that does not exist at all was already refused by the
            // exists rule; a second message here would only bury the first.
            if ($task !== null && $task->boq_item_id === null) {
                $validator->errors()->add(
                    $key,
                    'Tugas WBS yang dipilih tidak terhubung ke item BOQ, sehingga pemakaian material tidak dapat dibandingkan dengan analisa harga satuan.',
                );
            }
        }
    }

    /**
     * Every WBS task named on the document, keyed by the field that named it so
     * the message lands on the line the storeman has to fix.
     *
     * @return array<string, int>
     */
    protected function wbsTaskIdsByKey(): array
    {
        $ids = [];

        if ($this->integer('wbs_task_id') > 0) {
            $ids['wbs_task_id'] = $this->integer('wbs_task_id');
        }

        foreach ((array) $this->input('items', []) as $index => $item) {
            $lineTaskId = is_array($item) ? (int) ($item['wbs_task_id'] ?? 0) : 0;

            if ($lineTaskId > 0) {
                $ids["items.{$index}.wbs_task_id"] = $lineTaskId;
            }
        }

        return $ids;
    }
}
