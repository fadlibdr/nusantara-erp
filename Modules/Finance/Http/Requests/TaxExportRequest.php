<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tax period for an export. Defaults to the month just closed rather than the
 * current one — a tax officer preparing a filing is almost always working on
 * the period that has ended, and defaulting to a month still in progress
 * produces a file that is complete only by accident.
 */
class TaxExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
        ];
    }

    public function year(): int
    {
        return $this->integer('year') ?: (int) now()->subMonthNoOverflow()->year;
    }

    public function month(): int
    {
        return $this->integer('month') ?: (int) now()->subMonthNoOverflow()->month;
    }
}
