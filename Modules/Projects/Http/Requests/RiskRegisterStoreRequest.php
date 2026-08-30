<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Skor dan tingkat SENGAJA tidak divalidasi di sini: RiskRegisterService
 * membuangnya tanpa membaca — nilai risiko adalah aritmetika L×S, bukan isian.
 */
class RiskRegisterStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /** @return array<string, array<int, mixed>> */
    public static function sharedRules(): array
    {
        return [
            'activity' => ['required', 'string', 'max:200'],
            'hazard' => ['required', 'string', 'max:300'],
            'impact' => ['nullable', 'string', 'max:300'],
            'likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'severity' => ['required', 'integer', 'min:1', 'max:5'],
            'controls' => ['nullable', 'string', 'max:500'],
            // Berpasangan-atau-kosong ditegakkan service (nilai efektif
            // update parsial ikut diperiksa di sana).
            'residual_likelihood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'residual_severity' => ['nullable', 'integer', 'min:1', 'max:5'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            ...self::sharedRules(),
        ];
    }
}
