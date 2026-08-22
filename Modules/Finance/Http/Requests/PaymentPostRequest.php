<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\WithholdingType;
use Modules\Finance\Models\PaymentAllocation;

class PaymentPostRequest extends FormRequest
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
                    PaymentAllocation::TYPE_AR_INVOICE,
                    PaymentAllocation::TYPE_AP_BILL,
                    PaymentAllocation::TYPE_GL_ACCOUNT,
                    PaymentAllocation::TYPE_PETTY_CASH_FUND,
                ]),
            ],
            'allocations.*.payable_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
            // Memo on a gl_account row; the allowlist, ceiling and no-mixing
            // rules stay in PaymentService for the same seeder/console reason
            // as the rest.
            'allocations.*.remark' => ['nullable', 'string', 'max:150'],

            // Tax the customer kept back out of a receipt. Whether a
            // certificate number is mandatory depends on the kind of
            // withholding, so PaymentService decides that — here it is only
            // shaped and bounded.
            'withholdings' => ['sometimes', 'array'],
            'withholdings.*.ar_invoice_id' => ['required', 'integer', Rule::exists('fin_ar_invoices', 'id')],
            'withholdings.*.type' => ['required', Rule::enum(WithholdingType::class)],
            'withholdings.*.amount' => ['required', 'numeric', 'min:0.01'],
            'withholdings.*.certificate_no' => ['nullable', 'string', 'max:100'],
            'withholdings.*.certificate_date' => ['nullable', 'date'],
            // Temuan #15: alasan tertulis potongan lain-lain (denda). Wajib
            // atau tidaknya diputuskan PaymentService per jenis — di sini
            // hanya dibentuk dan dibatasi, sama seperti certificate_no.
            'withholdings.*.reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
