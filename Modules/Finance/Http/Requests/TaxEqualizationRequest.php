<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fiscal year for the ekualisasi working papers. Defaults to the year of the
 * month just closed, the same reading TaxExportRequest applies: in January the
 * auditor is reconciling LAST year, and defaulting to a year one week old
 * would render four sheets that are complete only by accident.
 */
class TaxEqualizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
        ];
    }

    public function year(): int
    {
        return $this->integer('year') ?: (int) now()->subMonthNoOverflow()->year;
    }
}
