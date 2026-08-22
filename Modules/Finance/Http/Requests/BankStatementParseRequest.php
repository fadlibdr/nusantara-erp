<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\BankStatementFormat;
use Modules\Finance\Services\CsvStatementParser;

/**
 * A statement arrives as TEXT inside the normal JSON envelope, not as a
 * multipart upload. The client reads the file with FileReader and posts its
 * contents; the server never stores the file.
 *
 * That is not a shortcut. api.js authenticates on a header and serialises every
 * body as JSON, so a multipart path would mean changing the transport for one
 * screen; and nothing in this application writes to disk, so an upload would
 * introduce a writable directory, a backup surface and a retention policy. The
 * evidence that matters — what was imported — is the per-line raw text and the
 * content hash, both of which survive in the database.
 */
class BankStatementParseRequest extends FormRequest
{
    /** ~2 MB of text. Comfortably above any real statement, safely below php-fpm's post_max_size. */
    private const MAX_CONTENT = 2_000_000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', Rule::exists('fin_bank_accounts', 'id')->whereNull('deleted_at')],
            'format' => ['required', Rule::in(array_column(BankStatementFormat::cases(), 'value'))],
            'content' => ['required', 'string', 'max:'.self::MAX_CONTENT],

            'mapping' => ['required_if:format,csv', 'array'],
            'mapping.delimiter' => ['required_if:format,csv', Rule::in(array_keys(CsvStatementParser::DELIMITERS))],
            'mapping.skip_rows' => ['nullable', 'integer', 'between:0,100'],
            'mapping.date_column' => ['required_if:format,csv', 'integer', 'between:0,200'],
            'mapping.date_format' => ['required_if:format,csv', Rule::in(array_keys(CsvStatementParser::DATE_FORMATS))],
            'mapping.description_column' => ['nullable', 'integer', 'between:0,200'],
            'mapping.reference_column' => ['nullable', 'integer', 'between:0,200'],
            'mapping.balance_column' => ['nullable', 'integer', 'between:0,200'],
            'mapping.amount_mode' => ['required_if:format,csv', Rule::in(CsvStatementParser::AMOUNT_MODES)],
            'mapping.debit_column' => ['required_if:mapping.amount_mode,debit_credit', 'nullable', 'integer', 'between:0,200'],
            'mapping.credit_column' => ['required_if:mapping.amount_mode,debit_credit', 'nullable', 'integer', 'between:0,200'],
            'mapping.amount_column' => ['nullable', 'integer', 'between:0,200'],
            'mapping.indicator_column' => ['nullable', 'integer', 'between:0,200'],
            'mapping.number_format' => ['required_if:format,csv', Rule::in(CsvStatementParser::NUMBER_FORMATS)],
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
