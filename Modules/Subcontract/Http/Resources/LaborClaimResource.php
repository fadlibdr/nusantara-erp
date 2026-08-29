<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\Terbilang;

class LaborClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'labor_contract_id' => $this->labor_contract_id,
            'labor_contract' => $this->whenLoaded('laborContract', fn () => [
                'id' => $this->laborContract->id,
                'code' => $this->laborContract->code,
                'title' => $this->laborContract->title,
                'value' => $this->laborContract->value,
                'ppn_rate' => $this->laborContract->ppn_rate,
                'pph_rate' => $this->laborContract->pph_rate,
            ]),
            'claim_no' => (int) $this->claim_no,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'gross_amount' => $this->gross_amount,
            'ppn_amount' => $this->ppn_amount,
            'pph_amount' => $this->pph_amount,
            'kasbon_id' => $this->kasbon_id,
            // Aturan kejujuran P4: potongan kasbon menyebut KODE kasbonnya di
            // layar, bukan hanya angka — pembaca harus bisa menunjuk kasbon
            // mana yang dipulihkan potongan ini.
            'kasbon' => $this->whenLoaded('kasbon', fn () => [
                'id' => $this->kasbon->id,
                'code' => $this->kasbon->code,
            ]),
            'kasbon_deduction_amount' => $this->kasbon_deduction_amount,
            'net_payable' => $this->net_payable,
            'net_payable_terbilang' => Terbilang::rupiah($this->net_payable ?? 0),
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => LaborClaimItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
