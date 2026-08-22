<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\IssueItem;
use Modules\Inventory\Models\IssueReturn;

/**
 * CRUD for the retur material draft. Everything that moves stock or money
 * lives in StockService::postIssueReturn(); this only assembles rows — but it
 * refuses at the door the two documents no return can ever be posted against
 * (an unposted bon, a field-report bon), so the storeman hears it while the
 * draft is still cheap to abandon rather than at posting time.
 */
class IssueReturnService
{
    public function create(array $data): IssueReturn
    {
        return DB::transaction(function () use ($data): IssueReturn {
            $items = Arr::pull($data, 'items', []);

            /** @var Issue $issue */
            $issue = Issue::query()->findOrFail($data['issue_id']);

            $this->assertReturnable($issue);

            $return = new IssueReturn(Arr::except($data, ['code', 'status', 'warehouse_id']));
            // Copied, never chosen: goods return to the warehouse they left.
            $return->warehouse_id = $issue->warehouse_id;
            $return->status = StockDocumentStatus::Draft;
            $return->save(); // HasDocumentNumber fills the RTM code

            $this->syncItems($return, $issue, $items);

            return $return->load('items.item', 'issue', 'warehouse');
        });
    }

    /**
     * One-click draft off the bon's detail screen: every line's remaining
     * returnable quantity (issued minus already returned through posted
     * returns), for the operator to trim down and post.
     */
    public function createFromIssue(Issue $issue, array $data): IssueReturn
    {
        $this->assertReturnable($issue);

        $items = [];

        foreach ($issue->items as $line) {
            $remaining = round((float) $line->qty - $line->qtyReturned(), 3);

            if ($remaining > 0) {
                $items[] = ['issue_item_id' => (int) $line->id, 'qty' => $remaining];
            }
        }

        if ($items === []) {
            throw new LogicException(
                "Seluruh material bon {$issue->code} sudah kembali lewat retur sebelumnya; tidak ada sisa untuk diretur."
            );
        }

        return $this->create([
            'issue_id' => $issue->id,
            'return_date' => $data['return_date'] ?? now()->toDateString(),
            'returned_by' => $data['returned_by'] ?? null,
            'reason' => $data['reason'],
            'items' => $items,
        ]);
    }

    public function update(IssueReturn $return, array $data): IssueReturn
    {
        $this->assertEditable($return);

        return DB::transaction(function () use ($return, $data): IssueReturn {
            $items = Arr::pull($data, 'items');

            // issue_id and warehouse_id are immovable — the requests never
            // validate them, so validated() cannot carry them here; a return
            // re-pointed at another bon would reverse cost that bon never
            // booked. A draft against the wrong bon is deleted and re-raised.
            $return->fill(Arr::except($data, ['code', 'status', 'issue_id', 'warehouse_id']));
            $return->save();

            if (is_array($items)) {
                $this->syncItems($return, $return->issue()->firstOrFail(), $items); // lines are replaced wholesale
            }

            return $return->load('items.item', 'issue', 'warehouse');
        });
    }

    public function delete(IssueReturn $return): void
    {
        $this->assertEditable($return);

        DB::transaction(function () use ($return): void {
            $return->items()->delete();
            $return->delete();
        });
    }

    /**
     * Replace the lines of a draft return. Every line must reference a line of
     * THE bon this return names — the reference is what carries the price the
     * goods left at, and a foreign line would return them at some other bon's
     * cost. item_id is copied from the issue line, never taken from the
     * payload: the return cannot bring back an article the bon never issued.
     */
    private function syncItems(IssueReturn $return, Issue $issue, array $items): void
    {
        $return->items()->delete();

        /** @var array<int, true> $seen issue line ids already used by an earlier line of this payload */
        $seen = [];

        foreach ($items as $item) {
            /** @var IssueItem $issueLine */
            $issueLine = IssueItem::query()->findOrFail($item['issue_item_id']);

            // One bon line, one retur line. The posting ceiling reads
            // qtyReturned() — posted documents only — per line, so two lines
            // naming the SAME bon line each pass alone: 30 + 30 against a
            // 40-zak bon posts 60, and the 20 phantom zak drive 5-1100 and
            // fin_project_costs negative. postIssueReturn() counts siblings
            // too; this refusal keeps the honest operator out of that trap
            // while the draft is still cheap.
            if (isset($seen[(int) $issueLine->id])) {
                throw new LogicException(
                    "Baris retur menunjuk baris bon #{$issueLine->id} dua kali; satu baris bon hanya boleh "
                    ."muncul sekali per retur {$return->code} — gabungkan kuantitasnya dalam satu baris."
                );
            }

            $seen[(int) $issueLine->id] = true;

            if ((int) $issueLine->issue_id !== (int) $issue->id) {
                throw new LogicException(
                    "Baris retur menunjuk baris bon lain (#{$issueLine->id} milik bon #{$issueLine->issue_id}); "
                    ."retur {$return->code} hanya boleh mengembalikan baris bon {$issue->code}."
                );
            }

            $return->items()->create([
                'issue_item_id' => $issueLine->id,
                'item_id' => $issueLine->item_id,
                'qty' => round((float) ($item['qty'] ?? 0), 3),
                'unit_cost' => 0, // frozen from the issue line at posting
                'amount' => 0,
            ]);
        }
    }

    /**
     * The two shapes no retur can ever be posted against, refused while the
     * draft is being written: StockService::postIssueReturn() re-checks both
     * inside its transaction, this is the early copy for the form.
     */
    private function assertReturnable(Issue $issue): void
    {
        if ($issue->status !== StockDocumentStatus::Posted) {
            throw new LogicException(
                "Bon {$issue->code} berstatus {$issue->status->value}; retur material hanya dapat dibuat "
                .'atas bon yang sudah diposting.'
            );
        }

        if ($issue->field_report_id !== null) {
            throw new LogicException(
                "Bon {$issue->code} dibuat otomatis dari pengesahan laporan lapangan; koreksi laporan "
                .'lapangannya, karena pengesahan dan pengeluaran suku cadang adalah satu peristiwa yang sama.'
            );
        }
    }

    private function assertEditable(IssueReturn $return): void
    {
        if (! $return->status->isEditable()) {
            throw new LogicException("Retur {$return->code} berstatus {$return->status->value} dan tidak dapat diubah lagi.");
        }
    }
}
