<?php

namespace Modules\HrPayroll\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Services\AttachmentService;
use Modules\Core\Support\Erp;
use Modules\Core\Support\Geotag;
use Modules\HrPayroll\Enums\AttendanceStatus;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Project;

/**
 * Absen masuk dan pulang dari ponsel orang yang bersangkutan (F-4).
 *
 * TIGA ATURAN YANG MEMBENTUK SELURUH KELAS INI.
 *
 * 1. Posisi MENCATAT, tidak pernah MENOLAK. Di luar radius proyek, di dalam,
 *    atau tidak diketahui — absensinya tersimpan sama saja. Menolak absensi
 *    karena GPS berarti orang yang tetap bekerja hari itu tidak punya catatan
 *    sama sekali, dan yang paling sering kena adalah gudang berdinding beton,
 *    basement, dan ponsel murah — bukan orang yang berbohong.
 *
 * 2. Tidak tahu bukan nol. Proyek tanpa titik peta, atau ponsel yang menolak
 *    memberi posisi, menghasilkan `distance_m` NULL. Nol berarti "berdiri
 *    tepat di titik proyek". Menuliskan 0 untuk "tidak terukur" adalah satu
 *    perubahan kolom yang mengubah setiap layar di atasnya menjadi bohong,
 *    dan tidak ada satu pun yang akan mengeluh.
 *
 * 3. Waktu perangkat DICATAT, tidak pernah MENGGANTIKAN waktu server. Jam
 *    ponsel bisa diputar siapa saja; waktu server tidak. Tetapi antrean luring
 *    bisa mengirim catatan berjam-jam kemudian, jadi waktu server sendirian
 *    juga berbohong tentang kapan orangnya datang. Keduanya disimpan, dan yang
 *    otoritatif tetap yang kedua.
 *
 * Satu tempat jam ponsel BOLEH menentukan sesuatu: TANGGAL barisnya, dan hanya
 * bila ia masih masuk akal (±48 jam dari jam server). Antrean yang tersangkut
 * melewati tengah malam kalau tidak begitu akan menaruh absen kemarin di hari
 * ini, menabrak kunci unik (karyawan, tanggal), dan menimpa absensi hari ini
 * dengan absensi kemarin. Jam ponsel yang lebih maju dari server tetap
 * dipotong ke hari server: tidak ada absensi di masa depan.
 */
class AttendanceClockService
{
    public const SIDE_IN = 'check_in';

    public const SIDE_OUT = 'check_out';

    /** Sejauh mana jam ponsel boleh menyimpang sebelum tanggalnya tidak dipercaya lagi. */
    private const DEVICE_CLOCK_TOLERANCE_HOURS = 48;

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly AttendanceCorrectionService $corrections,
    ) {}

    /** Radius yang berlaku HARI INI, dalam meter. Distempel ke baris saat jaraknya terukur. */
    public function geofenceMetres(): int
    {
        return Erp::int('hr.attendance.geofence_metres', 500);
    }

    /**
     * @param  array{project_id?: int|null, latitude?: float|null, longitude?: float|null, accuracy_m?: int|null, device_at?: string|null, selfie_filename?: string|null, selfie_content?: string|null}  $data
     * @return array{attendance: Attendance, outcome: string, message: string, selfie_error: ?string}
     */
    public function clock(string $side, Employee $employee, array $data, ?int $userId): array
    {
        $this->assertKnownSide($side);

        $serverNow = Carbon::now();
        $deviceAt = $this->deviceTime($data['device_at'] ?? null);
        $date = $this->workingDate($deviceAt, $serverNow);

        /*
         * Sekali ulang pada tabrakan kunci unik (verifikasi F-4).
         *
         * rowFor() melakukan SELECT lalu save() melakukan INSERT, dan di
         * antaranya baris (karyawan, tanggal) yang sama bisa lahir dari pintu
         * lain: lembar kerani yang dikirim bersamaan, tab kedua, ponsel kedua.
         * Tanpa penjaga ini pintu yang aturannya "MENCATAT, tidak pernah
         * MENOLAK" menjawab HTTP 500 — penolakan paling keras yang tersedia —
         * dan absennya hilang. Percobaan kedua menemukan baris yang sudah ada
         * dan menempel padanya. Idiom rumah yang sama dipakai
         * DailyReportService, HseDailyService dan BankStatementImportService.
         */
        try {
            return $this->write($side, $employee, $data, $userId, $serverNow, $deviceAt, $date);
        } catch (UniqueConstraintViolationException) {
            return $this->write($side, $employee, $data, $userId, $serverNow, $deviceAt, $date);
        }
    }

    /**
     * @return array{attendance: Attendance, outcome: string, message: string, selfie_error: ?string}
     */
    private function write(string $side, Employee $employee, array $data, ?int $userId, Carbon $serverNow, ?Carbon $deviceAt, string $date): array
    {
        return DB::transaction(function () use ($side, $employee, $data, $userId, $serverNow, $deviceAt, $date): array {
            $attendance = $this->rowFor($employee, $date, $data, $userId);

            $existingAt = $attendance->exists ? $attendance->{"{$side}_at"} : null;

            if ($existingAt !== null && $this->isSameEvent($attendance, $side, $deviceAt)) {
                return $this->reply($attendance, 'duplicate', $this->duplicateMessage($side), null);
            }

            if ($side === self::SIDE_IN && $existingAt !== null) {
                // Yang PERTAMA menang. Absen masuk kedua di hari yang sama
                // hampir selalu jempol yang menekan dua kali, dan jam datang
                // yang bergeser maju diam-diam adalah jam datang yang salah.
                return $this->reply($attendance, 'kept_earlier', sprintf(
                    'Absen masuk hari ini sudah tercatat pukul %s dan tetap dipakai.',
                    $existingAt->format('H:i'),
                ), null);
            }

            $reference = $this->referenceProject($data['project_id'] ?? null, $attendance);
            $position = $this->position($data);
            $distance = $this->distanceMetres($reference, $position);

            $attendance->fill([
                "{$side}_at" => $serverNow,
                "{$side}_device_at" => $deviceAt,
                "{$side}_latitude" => $position['latitude'],
                "{$side}_longitude" => $position['longitude'],
                "{$side}_accuracy_m" => $position['accuracy_m'],
                "{$side}_project_id" => $reference?->id,
                "{$side}_distance_m" => $distance,
                // Distempel hanya bila jaraknya benar-benar terukur: ambang
                // tanpa jarak adalah angka yang tidak menjawab pertanyaan apa
                // pun, dan pasangan (jarak, ambang) yang setengah terisi
                // membuat Attendance::outsideGeofence() harus menebak.
                "{$side}_geofence_m" => $distance === null ? null : $this->geofenceMetres(),
            ]);

            $replaced = $existingAt !== null;
            $pending = $replaced ? $this->corrections->pending($attendance) : [];

            $attendance->save();

            if ($replaced) {
                $this->corrections->write($attendance, $pending, sprintf(
                    'Absen pulang dicatat ulang dari ponsel; jam pulang sebelumnya %s.',
                    $existingAt->format('H:i'),
                ), 'clock', $userId);
            }

            $selfieError = $this->attachSelfie($attendance, $side, $data, $userId);

            return $this->reply(
                $attendance,
                $replaced ? 'replaced' : 'recorded',
                $this->recordedMessage($side, $attendance, $serverNow),
                $selfieError,
            );
        });
    }

    /**
     * Baris (karyawan, tanggal) yang ada, atau baris baru yang belum disimpan.
     *
     * Baris baru lahir berstatus HADIR: menekan "Absen masuk" adalah pernyataan
     * kehadiran, dan status apa pun selain itu berarti layar mencatat sesuatu
     * yang tidak dikatakan siapa pun. Kalau kemudian kerani menandainya absen,
     * pertentangan itu TERLIHAT di jejak koreksi — bukan diselesaikan diam-diam
     * di sini.
     */
    private function rowFor(Employee $employee, string $date, array $data, ?int $userId): Attendance
    {
        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        if ($attendance !== null) {
            // project_id yang SUDAH terisi tidak disentuh: kerani memindahkan
            // baris ke proyek yang benar, dan ponsel tidak boleh mengembalikannya.
            if ($attendance->project_id === null && ($data['project_id'] ?? null) !== null) {
                $attendance->project_id = (int) $data['project_id'];
            }

            return $attendance;
        }

        return new Attendance([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => AttendanceStatus::Hadir,
            'project_id' => $data['project_id'] ?? null,
            'recorded_by' => $userId,
        ]);
    }

    /**
     * Proyek yang dipakai sebagai acuan jarak — yang diminta permintaan ini,
     * kalau tidak ada, proyek barisnya.
     */
    private function referenceProject(?int $requested, Attendance $attendance): ?Project
    {
        $id = $requested ?? $attendance->project_id;

        return $id === null ? null : Project::query()->find($id);
    }

    /**
     * @return array{latitude: ?float, longitude: ?float, accuracy_m: ?int}
     */
    private function position(array $data): array
    {
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;

        // Setengah pasangan koordinat bukan posisi. Menyimpan lintang saja dan
        // membiarkan bujurnya null menghasilkan titik yang tidak pernah bisa
        // diukur, tetapi tampak "ada" di setiap layar yang memeriksa satu kolom.
        if (! Geotag::isValidLatitude($latitude) || ! Geotag::isValidLongitude($longitude)) {
            return ['latitude' => null, 'longitude' => null, 'accuracy_m' => null];
        }

        $accuracy = $data['accuracy_m'] ?? null;

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'accuracy_m' => $accuracy === null ? null : max(0, (int) round((float) $accuracy)),
        ];
    }

    /**
     * Meter dari titik proyek, atau NULL bila salah satu sisi tidak diketahui.
     *
     * Dua sisi, dua cara gagal, satu jawaban: proyek tanpa koordinat dan ponsel
     * tanpa fix sama-sama berarti tidak ada yang tahu seberapa jauh orangnya.
     */
    private function distanceMetres(?Project $project, array $position): ?int
    {
        if ($project === null || $position['latitude'] === null) {
            return null;
        }

        if (! Geotag::isValidLatitude($project->latitude) || ! Geotag::isValidLongitude($project->longitude)) {
            return null;
        }

        return (int) round(Geotag::distanceMetres(
            (float) $project->latitude,
            (float) $project->longitude,
            $position['latitude'],
            $position['longitude'],
        ));
    }

    /**
     * Jam ponsel, DIPINDAHKAN ke zona waktu aplikasi.
     *
     * Ponsel mengirim ISO-8601 ber-offset ("2026-09-08T00:30:00.000Z" untuk
     * pukul 07.30 WIB). Carbon::parse memahaminya dengan benar sebagai SAAT,
     * tetapi objek yang dihasilkan masih ber-zona UTC — dan dua hal pecah kalau
     * ia dipakai apa adanya:
     *
     *  - `->toDateString()` menjawab tanggal UTC. Absen pukul 06.30 WIB adalah
     *    23.30 UTC HARI SEBELUMNYA, jadi setiap absen pagi sebelum pukul tujuh
     *    akan diarsipkan ke tanggal kemarin — dan menabrak kunci unik
     *    (karyawan, tanggal) milik hari kemarin.
     *  - kolom `*_device_at` tersimpan sebagai jam dinding UTC di samping
     *    `*_at` yang tersimpan sebagai jam dinding WIB, sehingga layar
     *    menampilkan "tercatat 17:41, ditekan di ponsel 10:41" untuk satu
     *    tombol yang ditekan sekali (terukur di chromium, 8 Sep 2026).
     *
     * Keduanya kelihatan seperti bug antrean luring, bukan seperti zona waktu.
     */
    private function deviceTime(?string $raw): ?Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($raw)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            // Jam ponsel yang mengirim sampah bukan alasan menolak absensinya.
            return null;
        }

        /*
         * Jam yang bukan jam sama sekali dibuang, bukan disimpan.
         *
         * Carbon::parse('0000-00-00 00:00:00') TIDAK melempar — ia memulangkan
         * tahun nol, dan menyimpannya berarti dua hal: kolomnya berisi angka
         * yang tidak pernah menjadi waktu di ponsel siapa pun, dan MySQL dengan
         * sql_mode ketat MENOLAK menulisnya, sehingga absensinya gagal justru
         * karena hal yang seharusnya diabaikan. Jendelanya lebar dengan sengaja:
         * yang dibuang di sini hanya yang mustahil, sedangkan jam yang salah
         * setel bertahun-tahun tetap DICATAT (pemakaiannya yang dibatasi ±48
         * jam di workingDate()).
         */
        return $parsed->year >= 2000 && $parsed->year <= 2100 ? $parsed : null;
    }

    /**
     * Hari kerja mana yang dicatat baris ini.
     *
     * Jam ponsel dipakai HANYA di sini, HANYA untuk tanggalnya, dan hanya bila
     * ia masih dalam ±48 jam dari jam server. Di luar itu jam ponsel salah
     * setel, dan tanggal server adalah tebakan yang jauh lebih baik daripada
     * membuat absensi di tahun 2019.
     */
    private function workingDate(?Carbon $deviceAt, Carbon $serverNow): string
    {
        $reference = $serverNow;

        if ($deviceAt !== null
            && abs($deviceAt->diffInHours($serverNow, true)) <= self::DEVICE_CLOCK_TOLERANCE_HOURS) {
            $reference = $deviceAt;
        }

        // Tidak ada absensi di masa depan — aturan yang sama dengan lembar
        // kerani (AttendanceBulkRequest).
        return $reference->greaterThan($serverNow)
            ? $serverNow->toDateString()
            : $reference->toDateString();
    }

    /**
     * Apakah ini peristiwa yang SAMA, dikirim ulang?
     *
     * Antrean luring mengirim ulang butir yang sama sesudah jaringan pulih, dan
     * jam ponsel yang ikut tersimpan di butir itu tidak berubah saat dikirim
     * ulang. Itulah satu-satunya tanda yang membedakan "kirim ulang" dari
     * "orang ini benar-benar menekan tombolnya dua kali", dan tanpa tanda itu
     * setiap percobaan ulang menggeser jam pulang.
     */
    private function isSameEvent(Attendance $attendance, string $side, ?Carbon $deviceAt): bool
    {
        $stored = $attendance->{"{$side}_device_at"};

        if ($deviceAt === null || $stored === null) {
            return false;
        }

        return $stored->equalTo($deviceAt);
    }

    /**
     * Selfie lewat mesin lampiran yang sudah ada — bukan penyimpanan foto kedua.
     *
     * Foto yang DITOLAK (bukan gambar, terlalu besar, ekstensi tidak diizinkan)
     * tidak boleh menjatuhkan absensinya: orangnya tetap datang. Barisnya sudah
     * tersimpan sebelum baris ini berjalan, dan kegagalannya dikembalikan
     * sebagai kalimat yang dibaca pemakai, bukan sebagai 422 yang menghapus
     * kehadirannya.
     */
    private function attachSelfie(Attendance $attendance, string $side, array $data, ?int $userId): ?string
    {
        $content = $data['selfie_content'] ?? null;

        if ($content === null || $content === '') {
            return null;
        }

        try {
            $attachment = $this->attachments->store(
                $attendance,
                $data['selfie_filename'] ?? 'selfie.jpg',
                $content,
                sprintf('Selfie %s %s', $side === self::SIDE_IN ? 'masuk' : 'pulang', $attendance->date->toDateString()),
                $userId,
                [
                    'latitude' => $attendance->{"{$side}_latitude"},
                    'longitude' => $attendance->{"{$side}_longitude"},
                    'accuracy_m' => $attendance->{"{$side}_accuracy_m"},
                ],
            );
        } catch (LogicException $e) {
            return $e->getMessage();
        }

        $attendance->forceFill(["{$side}_attachment_id" => $attachment->id])->save();

        return null;
    }

    private function recordedMessage(string $side, Attendance $attendance, Carbon $serverNow): string
    {
        $label = $side === self::SIDE_IN ? 'Absen masuk' : 'Absen pulang';
        $distance = $attendance->{"{$side}_distance_m"};
        $outside = $attendance->outsideGeofence($side);

        $where = match (true) {
            $distance === null => 'Jarak ke lokasi proyek tidak terukur.',
            $outside === true => sprintf('Tercatat DI LUAR lokasi proyek — %s dari titik proyek.', self::distanceText($distance)),
            default => sprintf('Di lokasi proyek — %s dari titik proyek.', self::distanceText($distance)),
        };

        return sprintf('%s tercatat pukul %s. %s', $label, $serverNow->format('H:i'), $where);
    }

    private function duplicateMessage(string $side): string
    {
        return $side === self::SIDE_IN
            ? 'Absen masuk ini sudah tercatat sebelumnya — tidak ada yang berubah.'
            : 'Absen pulang ini sudah tercatat sebelumnya — tidak ada yang berubah.';
    }

    /**
     * Jarak sebagai kalimat, SATU tempat.
     *
     * Dipakai service (pesan jawaban) dan Resource (kolom layar). Dua salinan
     * rumus yang sama adalah cacat yang paling sering ditemukan kampanye ini:
     * yang satu diperbaiki, yang lain tidak, dan tidak ada uji yang merah.
     */
    public static function distanceText(int $metres): string
    {
        return $metres >= 1000
            ? number_format($metres / 1000, 1, ',', '.').' km'
            : $metres.' m';
    }

    /**
     * @return array{attendance: Attendance, outcome: string, message: string, selfie_error: ?string}
     */
    private function reply(Attendance $attendance, string $outcome, string $message, ?string $selfieError): array
    {
        return [
            'attendance' => $attendance,
            'outcome' => $outcome,
            'message' => $message,
            'selfie_error' => $selfieError,
        ];
    }

    private function assertKnownSide(string $side): void
    {
        if (! in_array($side, [self::SIDE_IN, self::SIDE_OUT], true)) {
            throw new LogicException("Sisi absensi \"{$side}\" tidak dikenal.");
        }
    }
}
