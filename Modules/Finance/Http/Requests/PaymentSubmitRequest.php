<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Models\PaymentAllocation;

/**
 * Shapes and bounds the allocations a disbursement is submitted with.
 *
 * ap_bill or gl_account — an outgoing payment cannot settle an AR invoice, and
 * refusing it here rather than in the service means the SPA gets a field error
 * on the offending row instead of one sentence about the whole payload.
 *
 * The business rules stay in PaymentService: the bill must be approved, the
 * account must be in the SettleableLiabilities allowlist, the lines must not
 * overpay outstanding/ceiling, the two kinds must not mix, and they must sum
 * to the amount. A seeder or a console command reaches the service without
 * ever seeing this class, and those rules must hold for them too.
 */
class PaymentSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.payable_type' => [
                'required',
                Rule::in([
                    PaymentAllocation::TYPE_AP_BILL,
                    PaymentAllocation::TYPE_GL_ACCOUNT,
                    PaymentAllocation::TYPE_PETTY_CASH_FUND,
                ]),
            ],
            'allocations.*.payable_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
            // The SSP/NTPN/masa-pajak reference on a gl_account row. A memo,
            // not money — PaymentService keeps it out of the approval signature.
            'allocations.*.remark' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'allocations.required' => 'Pembayaran keluar harus dialokasikan ke tagihan vendor sebelum diajukan.',
            'allocations.*.payable_type.in' => 'Pembayaran keluar hanya dapat melunasi tagihan vendor atau akun kewajiban yang diizinkan.',
        ];
    }
}
