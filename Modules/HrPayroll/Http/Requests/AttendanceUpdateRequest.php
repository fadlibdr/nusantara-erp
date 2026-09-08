<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HrPayroll\Enums\AttendanceStatus;

/**
 * employee_id and date stay immovable — together they ARE the row's identity
 * (the unique key the bulk upsert lands on). Moving a record to another day is
 * a delete plus a new entry, each visible in the register on its own date.
 *
 * `reason` WAJIB sejak F-4. Sebelum absensi bisa diisi orangnya sendiri, pintu
 * ini adalah kerani membetulkan ketikannya sendiri; sesudahnya, ia menimpa apa
 * yang seorang karyawan catatkan tentang dirinya — termasuk jam datang dan jam
 * pulang. Perubahan seperti itu tanpa alasan tertulis adalah perubahan yang
 * tidak bisa dipertanggungjawabkan tiga bulan kemudian, dan jejaknya
 * (hr_attendance_corrections) hanya berguna kalau kolom alasannya benar-benar
 * berisi kalimat manusia.
 *
 * Jam masuk/pulang BOLEH dikoreksi di sini — "lupa absen pulang" adalah kasus
 * paling sering di lapangan. Yang TIDAK bisa dikoreksi: koordinat, jarak, dan
 * ambang geofence. Itu hasil pengukuran, bukan pendapat; mengizinkan
 * pengeditannya berarti tanda "di luar lokasi" bisa dihapus tanpa jejak oleh
 * orang yang paling berkepentingan menghapusnya.
 */
class AttendanceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'project_id' => ['nullable', 'integer', Rule::exists('prj_projects', 'id')],
            'note' => ['nullable', 'string', 'max:200'],
            // Hadir di badan permintaan = "ubah"; TIDAK hadir = "jangan
            // sentuh". null eksplisit karena itu berarti "kosongkan", dan
            // itulah yang dibutuhkan untuk membatalkan jam pulang yang salah.
            'check_in_at' => ['sometimes', 'nullable', 'date'],
            'check_out_at' => ['sometimes', 'nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan koreksi wajib diisi — koreksi absensi tersimpan sebagai jejak, dan jejak tanpa alasan tidak menjelaskan apa pun.',
            'reason.min' => 'Alasan koreksi terlalu pendek untuk bisa dibaca orang lain nanti.',
        ];
    }
}
