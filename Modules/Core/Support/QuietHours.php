<?php

namespace Modules\Core\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jam tenang per pengguna (P-3a, T3a.2): jendela harian ketika kanal LUAR
 * (e-mail, WhatsApp) MENUNDA, TIDAK PERNAH MEMBUANG — dan tidak pernah
 * menyentuh kanal dalam aplikasi.
 *
 * Sebuah alarm pukul 02.00 tetap tercatat di kotak masuk seketika (kanal
 * kebenaran); baris kotak keluarnya mendapat next_attempt_at = akhir jam
 * tenang, status tetap `queued`, job-nya diantrekan dengan delay yang sama.
 * Tiga permukaan menghitungnya lewat kelas ini: kotak keluar saat menulis,
 * Kirim ulang, dan job saat berjalan (backoff yang jatuh di dalam jendela).
 *
 * ZONA WAKTU: Asia/Jakarta, tetap. Pengguna sistem ini bekerja di WIB dan
 * tidak ada kolom zona per pengguna; menyimpan zona per orang sebelum ada
 * pengguna di luar WIB adalah kolom yang tidak dibaca siapa pun. Bila suatu
 * hari perlu, tempatnya di sini, satu kali.
 *
 * Nilainya preferensi P1-C `notify.quiet_hours`: {start:"HH:MM", end:"HH:MM"}
 * atau FALSE (= tidak ada jam tenang; baris `false` adalah pilihan yang
 * pernah dibuat, berbeda dari kunci yang belum pernah ditulis — keduanya
 * berarti "tanpa jam tenang" bagi pengirim). Bukan null: kolom value
 * core_user_preferences NOT NULL, dan tidak ada DELETE untuk preferensi
 * (CONVENTIONS §15: kunci baru = satu entri, tidak pernah endpoint baru).
 * start ≠ end: jendela kosong dan jendela 24 jam sama-sama bukan jam tenang,
 * dan yang kedua adalah "matikan kanal" yang sudah punya sakelarnya sendiri.
 *
 * KASUS YANG PALING MUDAH SALAH — jendela melintasi tengah malam (22:00–06:00):
 *   pukul 23:30 → di dalam, lanjut BESOK 06:00
 *   pukul 02:00 → di dalam, lanjut HARI INI 06:00
 *   pukul 06:00 → di luar (akhir eksklusif), 22:00 → di dalam (awal inklusif)
 */
final class QuietHours
{
    public const ZONE = 'Asia/Jakarta';

    public const PREF = 'notify.quiet_hours';

    private const FIELDS = ['start', 'end'];

    private const POSTPONED_PREFIX = 'Ditunda oleh jam tenang penerima (';

    private function __construct(public readonly string $start, public readonly string $end) {}

    /** Kalimat 422 untuk nilai preferensi, atau null bila sah. */
    public static function reject(mixed $value): ?string
    {
        if ($value === false) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            return 'Jam tenang harus berupa objek {start, end} dengan jam "HH:MM", atau false untuk mematikannya.';
        }

        $extra = array_diff(array_keys($value), self::FIELDS);
        if ($extra !== []) {
            return sprintf('Field tidak dikenal pada jam tenang: %s.', implode(', ', $extra));
        }

        foreach (self::FIELDS as $field) {
            $one = $value[$field] ?? null;
            if (! is_string($one) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $one)) {
                return sprintf('Jam tenang "%s" harus berupa jam "HH:MM" (00:00–23:59).', $field);
            }
        }

        if ($value['start'] === $value['end']) {
            return 'Jam mulai dan jam selesai jam tenang tidak boleh sama — untuk mematikan kanal seharian pakai sakelar kanalnya.';
        }

        return null;
    }

    /** Null = tanpa jam tenang: kunci belum pernah ditulis, `false`, atau nilai yang tidak sah. */
    public static function fromPreference(mixed $value): ?self
    {
        if (! is_array($value) || self::reject($value) !== null) {
            return null;
        }

        return new self($value['start'], $value['end']);
    }

    public static function forUser(User $user): ?self
    {
        return self::fromPreference(DeliveryGate::preference($user, self::PREF));
    }

    public function crossesMidnight(): bool
    {
        return $this->start > $this->end;
    }

    /** Awal inklusif, akhir eksklusif — dalam WIB, apa pun zona $at. */
    public function contains(CarbonInterface $at): bool
    {
        $clock = CarbonImmutable::instance($at)->setTimezone(self::ZONE)->format('H:i');

        return $this->crossesMidnight()
            ? $clock >= $this->start || $clock < $this->end
            : $clock >= $this->start && $clock < $this->end;
    }

    /**
     * Saat kanal luar boleh mencoba lagi bila $at berada di dalam jendela;
     * null bila di luar. Dipulangkan dalam zona aplikasi supaya Eloquent
     * menyimpan angka yang benar (app.timezone bisa saja bukan WIB).
     */
    public function resumeAt(CarbonInterface $at): ?CarbonImmutable
    {
        if (! $this->contains($at)) {
            return null;
        }

        $local = CarbonImmutable::instance($at)->setTimezone(self::ZONE);
        [$hour, $minute] = array_map('intval', explode(':', $this->end));
        $end = $local->setTime($hour, $minute, 0);

        // Melintasi tengah malam dan kita masih di sisi malamnya: akhirnya
        // adalah besok pagi. Di sisi paginya (00:00–end) akhirnya hari ini.
        if ($this->crossesMidnight() && $local->format('H:i') >= $this->start) {
            $end = $end->addDay();
        }

        return $end->setTimezone((string) config('app.timezone', self::ZONE));
    }

    public function label(): string
    {
        return "{$this->start}–{$this->end} WIB";
    }

    /** Kalimat yang tampil di kolom "Galat / alasan" untuk baris `queued` yang ditunda. */
    public function postponedSentence(CarbonImmutable $until): string
    {
        return sprintf(
            self::POSTPONED_PREFIX.'%s) sampai %s WIB — tidak dibuang; pemberitahuan di dalam aplikasi sudah masuk.',
            $this->label(),
            $until->setTimezone(self::ZONE)->format('d M Y H:i'),
        );
    }

    /**
     * Apakah teks di kolom error adalah kalimat penundaan di atas — bukan
     * jawaban penyedia. DeliverNotification::failed() membawa "pesan penyedia
     * terakhir" ke baris `failed`; kalimat "tidak dibuang" bukan pesan
     * penyedia dan tidak boleh ikut (verifikasi P-3a, 12 Sep 2026).
     */
    public static function isPostponedSentence(?string $text): bool
    {
        return str_starts_with(trim((string) $text), self::POSTPONED_PREFIX);
    }

    /** @return array{start: string, end: string, zone: string} */
    public function toArray(): array
    {
        return ['start' => $this->start, 'end' => $this->end, 'zone' => self::ZONE];
    }
}
