<?php

namespace Modules\Finance\Support;

use InvalidArgumentException;

/**
 * Registri preset bawaan per bank (P-3c, T3c.0) — pola DjpFormats (P-3b).
 *
 * MENGAPA ADA. Roadmap meminta "preset bawaan BCA/Mandiri/BNI/BRI HANYA dari
 * berkas ekspor nyata pemilik". Tidak ada bank Indonesia yang menerbitkan
 * spesifikasi kolom ekspornya, dan bank yang sama mengubahnya antar kanal
 * (KlikBCA Bisnis ≠ myBCA ≠ BCA API). Preset yang ditulis dari ingatan akan
 * TERIMPOR RAPI DAN SALAH — kolom debit terbaca kredit, tie-out tetap nol
 * karena saldo awal/akhir diketik operator. Karena itu registri ini hanya
 * boleh MENUNJUK berkas ekspor nyata milik pemilik yang benar-benar ada di
 * docs/samples/bank/ (bertanggal, dengan nama siapa yang memverifikasi), dan
 * sebuah entri tanpa berkas itu tidak punya pemetaan sama sekali: ia tidak
 * bisa dipilih di layar Impor, tidak dipakai job folder terpantau, dan yang
 * ia bawa hanya kalimat yang menyebut berkas apa yang harus diletakkan.
 *
 * APA YANG TIDAK DIKARANG. Dua berkas di docs/samples/ (rekening-koran-bca-
 * 2026-04.csv dan rekening-koran-mandiri-2026-02.sta) adalah CONTOH DEMO yang
 * cocok dengan data demo — README folder itu sendiri berkata "tanpa berkas
 * bank sungguhan". Keduanya TIDAK dinaikkan menjadi preset "BCA"/"Mandiri";
 * registri menyebutnya apa adanya lewat `demo_note`.
 *
 * describe() murni: klaim `verified_against` yang berkasnya tidak ada di pohon
 * diturunkan kembali menjadi "belum ada berkas ekspor nyata" DAN pemetaannya
 * ditahan (null) — klaim tidak pernah boleh mendahului buktinya, termasuk pada
 * deploy yang lupa menyalin docs/. Kalimatnya sampai ke API (`data.presets`),
 * kartu registri di layar Impor, dan README dari SATU sumber ini.
 *
 * Preset yang BISA dipakai hari ini adalah preset per REKENING (kolom
 * fin_bank_accounts.import_preset, T3c.1): disimpan operator dari pemetaan
 * yang baru saja berhasil dipratinjau atas berkas bank sungguhan miliknya —
 * itulah "berkas ekspor nyata" yang tidak perlu menunggu siapa pun.
 */
final class BankPresets
{
    public const BCA = 'bca';

    public const MANDIRI = 'mandiri';

    public const BNI = 'bni';

    public const BRI = 'bri';

    public const STATUS_ADA = 'ada';

    public const STATUS_MENUNGGU = 'menunggu berkas ekspor nyata';

    /** Folder berkas ekspor nyata milik pemilik, relatif terhadap akar repo. */
    public const SAMPLES_DIR = 'docs/samples/bank';

    public const README = self::SAMPLES_DIR.'/README.md';

    /** Awalan kalimat yang dipaku uji dan dicari harness — jangan diparafrasakan. */
    public const UNVERIFIED_PREFIX = 'BELUM ADA BERKAS EKSPOR NYATA';

    /**
     * Deklarasi mentah. verified_against: null, atau ['path' => …, 'date' =>
     * 'YYYY-MM-DD', 'by' => …] yang menunjuk berkas di SAMPLES_DIR — diisi HANYA
     * sesudah berkas ekspor nyata diletakkan pemilik dan pemetaannya dicocokkan
     * kolom demi kolom terhadap berkas itu (README §3–§4). `mapping` = bentuk
     * yang sama dengan BankStatementParseRequest TANPA period/opening/closing;
     * null selama berkasnya belum ada.
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(): array
    {
        return [
            [
                'key' => self::BCA,
                'label' => 'BCA',
                'bank' => 'Bank Central Asia',
                'channels' => ['KlikBCA Bisnis (ekspor mutasi rekening)', 'myBCA Bisnis'],
                'verified_against' => null,
                'mapping' => null,
                'demo_note' => 'docs/samples/rekening-koran-bca-2026-04.csv adalah contoh demo yang cocok dengan data demo — bukan berkas ekspor bank, dan bukan preset.',
            ],
            [
                'key' => self::MANDIRI,
                'label' => 'Mandiri',
                'bank' => 'Bank Mandiri',
                'channels' => ['Kopra by Mandiri (ekspor mutasi / MT940)', 'Mandiri Cash Management'],
                'verified_against' => null,
                'mapping' => null,
                'demo_note' => 'docs/samples/rekening-koran-mandiri-2026-02.sta adalah contoh demo MT940 yang cocok dengan data demo — bukan berkas ekspor bank, dan bukan preset.',
            ],
            [
                'key' => self::BNI,
                'label' => 'BNI',
                'bank' => 'Bank Negara Indonesia',
                'channels' => ['BNIDirect (ekspor mutasi rekening)'],
                'verified_against' => null,
                'mapping' => null,
                'demo_note' => null,
            ],
            [
                'key' => self::BRI,
                'label' => 'BRI',
                'bank' => 'Bank Rakyat Indonesia',
                'channels' => ['Qlola by BRI / BRImo Bisnis (ekspor mutasi rekening)'],
                'verified_against' => null,
                'mapping' => null,
                'demo_note' => null,
            ],
        ];
    }

    /**
     * Deklarasi mentah, sebelum describe() menurunkan apa pun — publik supaya uji
     * bisa memaku bahwa hari ini TIDAK ADA klaim verified_against dan TIDAK ADA
     * pemetaan sama sekali, bukan hanya bahwa klaim yang salah sudah diturunkan.
     *
     * @return list<array<string, mixed>>
     */
    public static function declared(): array
    {
        return self::entries();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::entries() as $entry) {
            $all[$entry['key']] = self::describe($entry);
        }

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        $all = self::all();

        if (! array_key_exists($key, $all)) {
            throw new InvalidArgumentException("Preset bank [{$key}] tidak terdaftar di BankPresets.");
        }

        return $all[$key];
    }

    /**
     * Daftar untuk jawaban API — urutan registri.
     *
     * @return list<array<string, mixed>>
     */
    public static function forApi(): array
    {
        return array_values(self::all());
    }

    /**
     * Lencana hitungan di kepala kartu layar — dari sini, bukan disusun SPA.
     *
     * @return array{total: int, verified: int, label: string}
     */
    public static function summary(): array
    {
        $all = self::all();
        $verified = count(array_filter($all, fn (array $entry): bool => (bool) $entry['verified']));

        return [
            'total' => count($all),
            'verified' => $verified,
            'label' => sprintf('%d dari %d bank punya berkas ekspor nyata', $verified, count($all)),
        ];
    }

    /**
     * Fungsi MURNI dari deklarasi ke entri yang dibaca layar/API — publik supaya
     * kasus "verified_against menunjuk berkas yang tidak ada" bisa diuji tanpa
     * kait khusus-uji di registri.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function describe(array $entry): array
    {
        $key = (string) $entry['key'];
        $bank = (string) $entry['bank'];
        $declared = $entry['verified_against'] ?? null;
        $stem = $key.'-<kanal>-<YYYY-MM-DD>';
        $verified = false;
        $against = null;

        if (is_array($declared) && isset($declared['path'], $declared['date'], $declared['by'])) {
            if (is_file(base_path((string) $declared['path']))) {
                $verified = true;
                $against = ['path' => (string) $declared['path'], 'date' => (string) $declared['date'], 'by' => (string) $declared['by']];
                $verification = sprintf(
                    'Diverifikasi terhadap %s (%s, %s) — cocokkan ulang bila %s mengubah tata letak ekspornya; register verifikasi di %s §4.',
                    $against['path'],
                    $against['date'],
                    $against['by'],
                    $bank,
                    self::README,
                );
            } else {
                $verification = sprintf(
                    '%s %s — registri menunjuk %s (%s, %s) tetapi berkasnya tidak ada di pohon ini; letakkan berkas ekspor nyata sesuai %s.',
                    self::UNVERIFIED_PREFIX,
                    $bank,
                    $declared['path'],
                    $declared['date'],
                    $declared['by'],
                    self::README,
                );
            }
        } else {
            $verification = sprintf(
                '%s %s — belum ada berkas ekspor nyata di %s/ untuk bank ini; preset bawaannya tidak ditulis dari ingatan. Simpan preset per rekening dari layar Impor sesudah pratinjau berkas Anda sendiri berhasil (%s).',
                self::UNVERIFIED_PREFIX,
                $bank,
                self::SAMPLES_DIR,
                self::README,
            );
        }

        // Pemetaan hanya ikut bila berkasnya ADA di pohon: klaim tanpa bukti
        // tidak boleh menghasilkan pemetaan yang bisa dipilih.
        $mapping = $verified && is_array($entry['mapping'] ?? null) && $entry['mapping'] !== [] ? $entry['mapping'] : null;
        $status = $verified ? self::STATUS_ADA : self::STATUS_MENUNGGU;

        return [
            'key' => $key,
            'label' => (string) $entry['label'],
            'bank' => $bank,
            'channels' => array_values((array) ($entry['channels'] ?? [])),
            'status' => $status,
            'status_label' => $verified ? 'Ada' : 'Menunggu berkas ekspor nyata',
            'verified' => $verified,
            'verified_against' => $against,
            'verification' => $verification,
            // Teks lencana layar — dari sini, apa adanya (pelajaran V3b-2/V2-5 P-3b).
            'badge_label' => $verified
                ? sprintf('Diverifikasi %s', $against['date'])
                : 'Belum ada berkas ekspor nyata',
            'selectable' => $mapping !== null,
            'mapping' => $mapping,
            'awaiting_file' => $verified ? null : sprintf(
                'Menunggu berkas %s/%s.<ekstensi asli> — ekspor nyata dari %s (diletakkan pemilik; lihat %s).',
                self::SAMPLES_DIR,
                $stem,
                implode(' / ', (array) ($entry['channels'] ?? [])),
                self::README,
            ),
            'sample_stem' => $stem,
            'demo_note' => $entry['demo_note'] ?? null,
        ];
    }
}
