<?php

namespace Modules\HrPayroll\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HrPayroll\Models\Attendance;

/**
 * Absensi harian — deliberately a register, not a pay input (finding #22,
 * half 2). Nothing here recalculates a payslip or a recap; the monthly
 * hr_attendance_recaps that payroll reads stays a separate, human-owned
 * document. The linkage (deriving the recap, prorating daily-rate pay) is
 * left unbuilt on purpose until the recap's role as payroll input of record
 * is redesigned around it.
 */
class AttendanceService
{
    public function __construct(private readonly AttendanceCorrectionService $corrections) {}

    /**
     * The site sheet: one date, one project, many employees, one transaction.
     *
     * Upsert against the (employee, date) unique key, so posting the corrected
     * sheet a second time fixes rows instead of doubling them — the clerk's
     * retry after a dropped connection must be idempotent, not additive.
     *
     * @param  array{date: string, project_id?: int|null, entries: list<array{employee_id: int, status: string, note?: string|null}>}  $data
     * @return array{created: int, updated: int}
     */
    public function bulkUpsert(array $data, ?int $recordedBy): array
    {
        return DB::transaction(function () use ($data, $recordedBy): array {
            $date = Carbon::parse($data['date'])->toDateString();
            $projectId = $data['project_id'] ?? null;

            $created = 0;
            $updated = 0;

            foreach ($data['entries'] as $entry) {
                // whereDate, not updateOrCreate(['date' => $date]): the date
                // cast STORES midnight timestamps, so a plain equality against
                // 'Y-m-d' finds nothing, re-inserts, and the clerk's retry dies
                // on the unique key instead of correcting the sheet.
                $attendance = Attendance::query()
                    ->where('employee_id', (int) $entry['employee_id'])
                    ->whereDate('date', $date)
                    ->first();

                $values = [
                    'status' => $entry['status'],
                    'project_id' => $projectId,
                    'note' => $entry['note'] ?? null,
                    'recorded_by' => $recordedBy,
                ];

                if ($attendance === null) {
                    /*
                     * Antara whereDate() di atas dan create() di sini, baris
                     * (karyawan, tanggal) yang sama bisa lahir dari pintu absen
                     * ponsel. Tanpa penjaga ini seluruh lembar 40 nama gagal
                     * dengan 500 karena satu orang menekan absen masuk pada
                     * detik yang salah (verifikasi F-4). Percobaan kedua
                     * menemukan barisnya dan memperlakukannya sebagai
                     * pembaruan — termasuk menulis jejaknya.
                     */
                    try {
                        Attendance::query()->create($values + [
                            'employee_id' => (int) $entry['employee_id'],
                            'date' => $date,
                        ]);
                        $created++;

                        continue;
                    } catch (UniqueConstraintViolationException) {
                        $attendance = Attendance::query()
                            ->where('employee_id', (int) $entry['employee_id'])
                            ->whereDate('date', $date)
                            ->firstOrFail();
                    }
                }

                // Sampai di sini baris itu ADA — ditemukan di awal, atau ditemukan
                // oleh percobaan kedua sesudah tabrakan kunci unik di atas.
                $attendance->fill($values);

                /*
                 * Lembar kerani menimpa baris yang mungkin diisi orangnya
                 * sendiri dari ponsel — jadi perubahannya berjejak, sama
                 * seperti pintu PUT. Alasannya DITULIS SISTEM, bukan
                 * diketik: menuntut satu kalimat per orang pada lembar 40
                 * nama berarti kerani berhenti memakai layarnya dan
                 * kembali ke kertas, dan absensi yang tidak tercatat sama
                 * sekali jauh lebih buruk daripada jejak beralasan generik.
                 * Kolom `source` yang membedakan keduanya di layar.
                 *
                 * Kolom jam masuk/pulang TIDAK ADA di $values, dan itu
                 * bukan kebetulan: lembar kertas tidak tahu jam berapa
                 * orangnya datang, dan menimpanya dengan null berarti
                 * lembar yang dikirim ulang menghapus bukti GPS hari itu.
                 */
                $pending = $this->corrections->pending($attendance);
                $attendance->save();
                $this->corrections->write(
                    $attendance,
                    $pending,
                    sprintf('Lembar absensi %s dikirim ulang%s.', $date, $this->byWhom($recordedBy)),
                    'bulk',
                    $recordedBy,
                );
                $updated++;
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /** " oleh Budi" — atau kosong, karena akun bisa hilang dan jejaknya tetap harus terbaca. */
    private function byWhom(?int $userId): string
    {
        if ($userId === null) {
            return '';
        }

        $name = User::query()->whereKey($userId)->value('name');

        return $name === null ? '' : ' oleh '.$name;
    }
}
