<?php

namespace Modules\Procurement\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\ProcurementPlan;

/**
 * Rencana Pengadaan / Pola Belanja (PBL) — P2.
 *
 * Register perencanaan yang disusun dari RAP: memetakan paket belanja ke metode,
 * target tanggal kontrak, dan PIC. Bukan komitmen — tidak Approvable, tidak
 * menggerakkan uang.
 */
class ProcurementPlanService
{
    public function create(array $data): ProcurementPlan
    {
        return DB::transaction(function () use ($data): ProcurementPlan {
            $items = Arr::pull($data, 'items', []);

            $plan = new ProcurementPlan(Arr::except($data, ['code']));
            $plan->save(); // HasDocumentNumber mengisi kode PBL

            $this->syncItems($plan, $items);

            return $plan->load('items');
        });
    }

    public function update(ProcurementPlan $plan, array $data): ProcurementPlan
    {
        return DB::transaction(function () use ($plan, $data): ProcurementPlan {
            $items = Arr::pull($data, 'items');

            $plan->fill(Arr::except($data, ['code']));
            $plan->save();

            if (is_array($items)) {
                $this->syncItems($plan, $items);
            }

            return $plan->load('items');
        });
    }

    public function delete(ProcurementPlan $plan): void
    {
        $plan->delete();
    }

    private function syncItems(ProcurementPlan $plan, array $items): void
    {
        $plan->items()->delete();

        $lineNo = 0;

        foreach ($items as $item) {
            $plan->items()->create([
                'line_no' => ++$lineNo,
                'boq_item_id' => $item['boq_item_id'] ?? null,
                'package' => $item['package'],
                'method' => $item['method'] ?? 'rfq',
                'estimated_amount' => isset($item['estimated_amount']) ? round((float) $item['estimated_amount'], 2) : null,
                'target_contract_date' => $item['target_contract_date'] ?? null,
                'pic' => $item['pic'] ?? null,
                'status' => $item['status'] ?? 'planned',
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }
}
