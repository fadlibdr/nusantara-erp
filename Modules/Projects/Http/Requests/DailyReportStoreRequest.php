<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\DailyReportRole;
use Modules\Projects\Enums\Weather;
use Modules\Projects\Rules\UniqueDailyReportDate;

class DailyReportStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Aturan bersama keempat tabel baris FM-10-12 (P0-A). Di sini hanya bentuk
     * per-baris; aturan yang MEMBANDINGKAN (turunan manpower_count vs angka
     * manual, qty_rejected ≤ qty_received, work_end > work_start dengan nilai
     * tersimpan) milik DailyReportService — satu implementasi untuk store dan
     * update.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function lineRules(): array
    {
        return [
            'manpower' => ['sometimes', 'array'],
            // distinct: duplikat jabatan pecah di indeks unik dengan 500;
            // ditolak di sini dengan bahasa manusia.
            'manpower.*.role_key' => ['required', Rule::enum(DailyReportRole::class), 'distinct'],
            'manpower.*.headcount' => ['required', 'integer', 'min:0', 'max:65535'],
            'manpower.*.notes' => ['nullable', 'string', 'max:200'],
            'equipment' => ['sometimes', 'array'],
            'equipment.*.asset_id' => ['nullable', 'integer', 'min:1'],
            'equipment.*.description' => ['required', 'string', 'max:150'],
            'equipment.*.qty' => ['required', 'integer', 'min:1', 'max:65535'],
            'equipment.*.hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'receipts' => ['sometimes', 'array'],
            'receipts.*.goods_receipt_id' => ['nullable', 'integer', 'min:1'],
            'receipts.*.item_id' => ['nullable', 'integer', 'min:1'],
            'receipts.*.description' => ['required', 'string', 'max:200'],
            'receipts.*.qty_received' => ['required', 'numeric', 'min:0.001'],
            'receipts.*.qty_rejected' => ['nullable', 'numeric', 'min:0'],
            'receipts.*.unit' => ['required', 'string', 'max:20'],
            'receipts.*.rejection_reason' => ['nullable', 'string', 'max:200'],
            'activity_lines' => ['sometimes', 'array'],
            'activity_lines.*.wbs_task_id' => ['nullable', 'integer', 'min:1'],
            'activity_lines.*.description' => ['required', 'string', 'max:300'],
            'activity_lines.*.progress_note' => ['nullable', 'string', 'max:150'],
            'activity_lines.*.target_note' => ['nullable', 'string', 'max:150'],
            'activity_lines.*.obstacle' => ['nullable', 'string', 'max:300'],
            'activity_lines.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public static function lineMessages(): array
    {
        return [
            'manpower.*.role_key.distinct' => 'Jabatan yang sama tercantum dua kali pada rincian tenaga kerja.',
        ];
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'report_date' => [
                'required',
                // 'string' sebelum 'date': angka JSON 20260325 lolos aturan
                // 'date' lalu di-cast model sebagai UNIX timestamp — tanggal
                // 1970 tersimpan diam-diam. Formulir selalu mengirim string.
                'string',
                'date',
                // Satu laporan per proyek per hari, dibandingkan per TANGGAL —
                // lihat prosa di UniqueDailyReportDate untuk cacat string-compare
                // yang digantikannya (SQLite: lolos validasi, 500 di indeks unik).
                new UniqueDailyReportDate($this->filled('project_id') ? (int) $this->input('project_id') : null),
            ],
            'weather_am' => ['nullable', Rule::enum(Weather::class)],
            'weather_pm' => ['nullable', Rule::enum(Weather::class)],
            'work_start' => ['nullable', 'date_format:H:i'],
            'work_end' => ['nullable', 'date_format:H:i'],
            'lost_hours_reason' => ['nullable', 'string', 'max:300'],
            // Wajib hanya TANPA rincian per jabatan: begitu baris manpower
            // dikirim, angkanya diturunkan (DailyReportService), dan angka
            // manual yang ikut terkirim harus cocok atau ditolak 422.
            'manpower_count' => ['required_without:manpower', 'nullable', 'integer', 'min:0'],
            'activities' => ['required', 'string'],
            'obstacles' => ['nullable', 'string'],
            'safety_notes' => ['nullable', 'string'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['string', 'max:500'],
            'materials' => ['nullable', 'array'],
            'materials.*.item_id' => ['required', 'integer', 'min:1'],
            'materials.*.qty_used' => ['required', 'numeric', 'min:0.001'],
            'materials.*.unit' => ['required', 'string', 'max:20'],
            ...self::lineRules(),
        ];
    }

    public function messages(): array
    {
        return self::lineMessages();
    }
}
