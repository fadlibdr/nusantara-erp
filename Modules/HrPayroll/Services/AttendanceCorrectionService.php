<?php

namespace Modules\HrPayroll\Services;

use BackedEnum;
use DateTimeInterface;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\AttendanceCorrection;

/**
 * Menulis jejak setiap perubahan pada baris absensi yang SUDAH ADA.
 *
 * Satu tempat, bukan tiga. Tiga pintu bisa mengubah satu baris — PUT pengawas,
 * lembar kerani yang dikirim ulang, dan jam pulang kedua dari ponsel — dan
 * cacat yang paling sering ditemukan kampanye ini adalah aturan yang ditegakkan
 * di satu pintu lalu bocor di pintu kedua. Ketiganya memanggil kelas ini, jadi
 * "apa yang dicatat" tidak bisa berbeda antar pintu tanpa mengubah satu berkas.
 *
 * Dipanggil SEBELUM save(): ia membaca getDirty()/getOriginal(), yang setelah
 * save() sudah tidak mengatakan apa pun lagi.
 */
class AttendanceCorrectionService
{
    /**
     * Kolom yang tidak pernah menarik untuk dicatat: stempel waktu baris itu
     * sendiri, yang berubah pada setiap simpan dan tidak mengandung keputusan
     * siapa pun.
     *
     * @var list<string>
     */
    private const IGNORED = ['created_at', 'updated_at'];

    /**
     * Foto pengganti apa adanya: id lampiran. Jejaknya menyebut id, bukan
     * "berubah", supaya lampiran lama masih bisa dicari orang yang bertanya
     * foto mana yang dulu ada di sana.
     *
     * @return list<array{field: string, old_value: ?string, new_value: ?string}>
     */
    public function pending(Attendance $attendance): array
    {
        $rows = [];

        foreach ($attendance->getDirty() as $field => $new) {
            if (in_array($field, self::IGNORED, true)) {
                continue;
            }

            $old = $attendance->getOriginal($field);

            // getDirty() sudah membandingkan, tetapi getOriginal() mengembalikan
            // bentuk MENTAH dari basis data sedangkan atribut yang di-set bisa
            // berupa objek Carbon atau enum. Membandingkan bentuk teks keduanya
            // mencegah "2026-09-08 07:00:00" dianggap berbeda dari Carbon jam
            // yang sama — jejak palsu yang muncul setiap kali baris disimpan.
            $oldText = $this->text($attendance->getRawOriginal($field));
            $newText = $this->text($new);

            if ($oldText === $newText) {
                continue;
            }

            $rows[] = ['field' => $field, 'old_value' => $oldText, 'new_value' => $newText];
        }

        return $rows;
    }

    /**
     * Menyimpan jejak yang sudah dikumpulkan pending(). Dipanggil SESUDAH
     * save() karena baris baru belum punya id sebelum itu.
     *
     * @param  list<array{field: string, old_value: ?string, new_value: ?string}>  $rows
     */
    public function write(Attendance $attendance, array $rows, string $reason, string $source, ?int $userId): int
    {
        foreach ($rows as $row) {
            AttendanceCorrection::query()->create($row + [
                'attendance_id' => $attendance->id,
                'reason' => $reason,
                'source' => $source,
                'corrected_by' => $userId,
            ]);
        }

        return count($rows);
    }

    /**
     * Nilai apa pun menjadi teks yang bisa dibaca manusia — atau null bila
     * kolomnya memang kosong.
     *
     * null TIDAK boleh menjadi string kosong: "" berarti seseorang mengetik
     * catatan kosong, null berarti tidak pernah ada catatan, dan log yang
     * meratakan keduanya menghapus satu-satunya perbedaan yang ditanyakan orang
     * saat memeriksa koreksi.
     */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return mb_substr((string) $value, 0, 200);
    }
}
