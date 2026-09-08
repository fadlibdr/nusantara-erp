<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Absen masuk/pulang dari ponsel sendiri.
 *
 * SEMUA yang berhubungan dengan posisi bersifat opsional, dan itu keputusan
 * desain, bukan kelonggaran: izin lokasi yang ditolak, fix yang kehabisan
 * waktu, dan ponsel tanpa GPS semuanya harus tetap bisa absen. Aturan paket
 * ini adalah MENCATAT, bukan MENOLAK — lihat AttendanceClockService.
 *
 * Yang TIDAK ada di sini juga disengaja: tidak ada `employee_id`. Pintu ini
 * hanya menulis absensi milik pemanggilnya sendiri, dan sebuah field yang bisa
 * menyebut orang lain akan menjadikannya pintu untuk mengabsenkan rekan yang
 * belum datang.
 */
class AttendanceClockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // kepemilikan baris ditegakkan controller: hanya milik pemanggil
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', Rule::exists('prj_projects', 'id')],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            // Jam ponsel saat tombolnya ditekan. Tidak dibatasi ke masa lalu:
            // jam yang salah setel adalah fakta yang ingin kita SIMPAN, dan
            // menolaknya berarti kehilangan absensinya sekalian. Service yang
            // memutuskan seberapa jauh jam ini boleh dipercaya.
            'device_at' => ['nullable', 'date'],
            'selfie_filename' => ['nullable', 'string', 'max:255'],
            // ~6,8 MB base64 untuk berkas 5 MB — batas yang sama dengan
            // core/attachments, ditolak di sini alih-alih oleh php-fpm (yang
            // menjawab 413 kosong tanpa satu kalimat pun).
            'selfie_content' => ['nullable', 'string', 'max:7000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.exists' => 'Proyek yang dipilih tidak ada.',
        ];
    }
}
