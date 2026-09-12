<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\BankStatementFormat;

/**
 * PUT finance/bank-accounts/{id}/import-preset (P-3c): nama + berkas yang BARU
 * SAJA dipratinjau + pemetaan layarnya. Berkasnya ikut dikirim (sebagai teks,
 * seperti pratinjau — tidak ada yang disimpan ke disk) karena preset mengingat
 * sel baris judul berkas itu, dan service menuntut pratinjau yang seimbang
 * atasnya. Format hanya CSV: MT940 tidak butuh preset.
 */
class BankImportPresetRequest extends FormRequest
{
    private const MAX_CONTENT = 2_000_000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'format' => ['required', Rule::in(array_column(BankStatementFormat::cases(), 'value'))],
            'content' => ['required', 'string', 'max:'.self::MAX_CONTENT],
            'mapping' => ['required_if:format,csv', 'array'],
        ] + BankStatementParseRequest::mappingRules($this->input('format') === BankStatementFormat::Csv->value) + [
            'mapping.period_start' => ['required_if:format,csv', 'date'],
            'mapping.period_end' => ['required_if:format,csv', 'date'],
            'mapping.opening_balance' => ['required_if:format,csv', 'numeric'],
            'mapping.closing_balance' => ['required_if:format,csv', 'numeric'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('format') !== BankStatementFormat::Csv->value) {
                return;
            }

            if (in_array($this->input('mapping.amount_mode'), ['single_signed', 'single_with_indicator'], true)
                && $this->input('mapping.amount_column') === null) {
                $validator->errors()->add('mapping.amount_column', 'Kolom nilai wajib dipilih untuk mode ini.');
            }
        });
    }

    public function mapping(): array
    {
        return array_filter(
            (array) $this->input('mapping', []),
            static fn ($value): bool => $value !== null && $value !== '',
        );
    }
}
