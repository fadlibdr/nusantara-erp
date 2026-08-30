<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HseDailyStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Bentuk baris bersama store/update (pola DailyReportStoreRequest).
     * daily_report_id sengaja TIDAK divalidasi (dan dibuang service): tautan
     * ke laporan harian adalah fakta turunan (proyek, tanggal), bukan isian.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function lineRules(): array
    {
        return [
            'apd' => ['sometimes', 'array'],
            // distinct: kategori ganda pecah di indeks unik dengan 500;
            // ditolak di sini dengan bahasa manusia.
            'apd.*.category' => ['required', 'string', 'max:60', 'distinct'],
            'apd.*.qty' => ['required', 'integer', 'min:0', 'max:65535'],
            'findings' => ['sometimes', 'array'],
            'findings.*.finding' => ['required', 'string', 'max:300'],
            'findings.*.follow_up' => ['nullable', 'string', 'max:300'],
            'findings.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'toolbox_attendees' => ['nullable', 'array'],
            'toolbox_attendees.*' => ['string', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    public static function lineMessages(): array
    {
        return [
            'apd.*.category.distinct' => 'Kategori APD yang sama tercantum dua kali.',
        ];
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            // 'string' sebelum 'date' — alasan yang sama dengan laporan harian:
            // angka JSON lolos 'date' lalu tersimpan sebagai timestamp 1970.
            'report_date' => ['required', 'string', 'date'],
            'toolbox_topic' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'],
            ...self::lineRules(),
        ];
    }

    public function messages(): array
    {
        return self::lineMessages();
    }
}
