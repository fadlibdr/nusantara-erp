<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FakturPajakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // e-Faktur format 010.000-26.00000001 (kode+status transaksi,
            // nomor seri) — validated loosely, DJP owns the real format.
            //
            // The uniqueness is not loose: DJP issues each nomor seri once, and
            // two invoices carrying one serial put two FK records under it in
            // the e-Faktur file with nothing on the screen flagging them. The
            // service refuses it too; this reports it on the field. Ignoring
            // this invoice keeps re-registering the same number on the same
            // invoice legal — correcting a typo is not a duplicate.
            'faktur_pajak_no' => [
                'required',
                'string',
                'max:40',
                Rule::unique('fin_ar_invoices', 'faktur_pajak_no')
                    ->ignore($this->route('arInvoice')?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'faktur_pajak_no.unique' => 'Nomor faktur pajak ini sudah dipakai invoice lain; '
                .'satu nomor seri dari DJP hanya boleh dipakai satu faktur.',
        ];
    }
}
