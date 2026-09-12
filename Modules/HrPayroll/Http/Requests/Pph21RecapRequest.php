<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Masa untuk rekap PPh 21/26 bulanan. Bawaannya bulan yang baru lewat —
 * alasan yang sama dengan TaxExportRequest: rekap disiapkan untuk masa yang
 * sudah berakhir, dan bulan berjalan belum punya run yang disetujui.
 */
class Pph21RecapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
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
