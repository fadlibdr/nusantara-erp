<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Issue;

class IssueService
{
    public function create(array $data): Issue
    {
        return DB::transaction(function () use ($data): Issue {
            $items = Arr::pull($data, 'items', []);

            $issue = new Issue(Arr::except($data, ['code', 'status']));
            $issue->status = StockDocumentStatus::Draft;
            $issue->save(); // HasDocumentNumber fills the ISS code

            $this->syncItems($issue, $items);

            return $issue->load('items.item', 'warehouse');
        });
    }

    public function update(Issue $issue, array $data): Issue
    {
        $this->assertEditable($issue);

        return DB::transaction(function () use ($issue, $data): Issue {
            $items = Arr::pull($data, 'items');

            $issue->fill(Arr::except($data, ['code', 'status']));
            $issue->save();

            if (is_array($items)) {
                $this->syncItems($issue, $items); // lines are replaced wholesale
            }

            return $issue->load('items.item', 'warehouse');
        });
    }

    public function delete(Issue $issue): void
    {
        $this->assertEditable($issue);

        DB::transaction(function () use ($issue): void {
            $issue->items()->delete();
            $issue->delete();
        });
    }

    /**
     * Replace the lines of a draft issue.
     *
     * ATTRIBUTION RULE, which the material variance report is built on: the
     * LINE names the work package, the header is only its default. One bon can
     * serve two packages — ISS/2026/VII/0001 issues 150 zak semen for the
     * pasangan bata analysis (WBS C.1) and 80 btg besi beton for the pembesian
     * analysis (WBS B.3) — so a header-only attribution would have to be wrong
     * about one of the two lines, and a posted issue can no longer be split.
     * Copying the header down keeps the ordinary single-package bon at one
     * field for the storeman.
     *
     * An update that changes only the header therefore leaves existing lines
     * alone: lines are written only when the payload sends them.
     */
    private function syncItems(Issue $issue, array $items): void
    {
        $issue->items()->delete();

        foreach ($items as $item) {
            $issue->items()->create([
                'item_id' => $item['item_id'],
                'wbs_task_id' => $item['wbs_task_id'] ?? $issue->wbs_task_id,
                'qty' => round((float) ($item['qty'] ?? 0), 3),
                'unit_cost' => 0, // valued at warehouse avg cost when posted
                'amount' => 0,
            ]);
        }
    }

    private function assertEditable(Issue $issue): void
    {
        if (! $issue->status->isEditable()) {
            throw new LogicException("Issue {$issue->code} is {$issue->status->value} and can no longer be modified.");
        }
    }
}
