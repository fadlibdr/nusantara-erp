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
            // whereNull(deleted_at): tanpa itu proyek yang sudah diarsipkan LOLOS
            // validasi, lalu referenceProject() — yang memakai Project::find()
            // dan menghormati soft delete — tidak menemukannya, dan orang yang
            // berdiri tepat di titik proyek diberi tahu "jarak tidak terukur".
            'project_id' => ['nullable', 'integer', Rule::exists('prj_projects', 'id')->whereNull('deleted_at')],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            /*
             * Jam ponsel saat tombolnya ditekan — STRING, bukan `date`.
             *
             * Aturan `date` di sini membatalkan janji paket ini. Ia berjalan
             * SEBELUM service, jadi jam ponsel yang mengirim sampah
             * ("banana", "0000-00-00") menghasilkan 422: tidak ada baris, tidak
             * ada selfie, absennya hilang seluruhnya — padahal orangnya berdiri
             * di gerbang dan menekan tombol. Yang rusak cuma jamnya.
             * AttendanceClockService::deviceTime() sudah menelan yang tidak
             * bisa dibaca dan melanjutkan tanpa jam perangkat; docblock-nya
             * mengatakan itu sejak semula, dan aturan ini membuatnya kode mati.
             *
             * `max:64` tetap ada: satu megabyte teks di kolom ini bukan jam
             * ponsel, itu percobaan lain.
             */
            'device_at' => ['nullable', 'string', 'max:64'],
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
