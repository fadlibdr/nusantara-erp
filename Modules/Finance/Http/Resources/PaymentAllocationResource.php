<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\PettyCashFund;

class PaymentAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * Tanpa ini layar pembayaran hanya bisa menulis "#3" — nomor baris
         * database, bukan nomor dokumen yang dikenal orang keuangan. Satu
         * query per alokasi masih aman: alokasi hanya dimuat pada tampilan
         * satu pembayaran (show/post), tidak pernah pada daftar.
         */
        $payable = $this->payable();

        return [
            'id' => $this->id,
            'payable_type' => $this->payable_type,
            'payable_id' => $this->payable_id,
            'payable_code' => $payable?->code,
            // Untuk baris gl_account: nama akunnya, supaya layar terkunci dan
            // dialog konfirmasi approver bisa menyebut kewajibannya dengan
            // kata-kata ("2-1110 Hutang Gaji & Upah"), bukan hanya kodenya.
            // Baris petty_cash_fund memakai nama dananya dengan alasan sama.
            'payable_label' => match (true) {
                $this->payable_type === PaymentAllocation::TYPE_GL_ACCOUNT && $payable instanceof Account => $payable->name,
                $this->payable_type === PaymentAllocation::TYPE_PETTY_CASH_FUND && $payable instanceof PettyCashFund => $payable->name,
                default => null,
            },
            'amount' => $this->amount,
            'remark' => $this->remark,
        ];
    }
}
