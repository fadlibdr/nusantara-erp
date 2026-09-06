<?php

namespace Modules\Core\Support;

/**
 * Preferensi per pengguna yang boleh disimpan, satu entri per kunci (P1-C).
 *
 * Dalam selera AttachableDocuments / WatchedDeadlines / PrintableDocuments:
 * SATU daftar deklaratif, jadi kunci berikutnya adalah satu entri array —
 * tidak pernah kolom baru, tidak pernah endpoint baru.
 *
 * KENAPA WHITELIST DAN BUKAN "simpan apa pun yang dikirim klien". Endpoint
 * `PUT core/me/preferences/{key}` sengaja tanpa gerbang izin — barisnya milik
 * pemanggil sendiri, dan tidak ada izin yang bisa membedakan "preferensi saya"
 * dari "preferensi saya". Tanpa daftar ini, endpoint itu adalah penyimpanan
 * bebas 16 KB × kunci sebanyak-banyaknya × jumlah pengguna, yang disalin ke
 * setiap backup dan setiap ekspor selamanya. Dengan daftar ini, apa yang bisa
 * disimpan seseorang persis apa yang dibaca aplikasi.
 *
 * Setiap entri:
 *   label       — nama Indonesia untuk pesan 422 (kuncinya sendiri juga disebut).
 *   max_bytes   — plafon per kunci; tidak pernah lebih dari MAX_BYTES.
 *   max_entries — jumlah maksimum anggota bila nilainya daftar, null bila bukan;
 *                 describe() mengirimkannya di meta.keys supaya klien tahu batas
 *                 yang berlaku sebelum menulis (verifikasi P1-C: meta dulu hanya
 *                 mengiklankan MAX_BYTES global, yang lebih longgar dari yang
 *                 sebenarnya dipakai setiap kunci).
 *   validate    — fn(mixed $value): ?string — null = sah, string = kalimat 422.
 *
 * KEJUJURAN: kunci yang belum pernah ditulis TIDAK ADA barisnya. Bawaan
 * ('normal' untuk kepadatan, [] untuk favorit) milik SPA, bukan server —
 * sebuah baris `density: 'normal'` yang ditulis server berbohong bahwa
 * orangnya pernah memilih.
 */
final class UserPreferences
{
    /** Plafon keras per nilai (JSON terkode), untuk kunci mana pun. */
    public const MAX_BYTES = 16384;

    private const FAVORITES_MAX = 50;

    private const RECENT_MAX = 20;

    private const HIDDEN_MAX = 32;

    private const RECENT_FIELDS = ['route', 'label', 'sub', 'at'];

    public const DENSITIES = ['compact', 'normal', 'comfortable'];

    /**
     * @return array<string, array{label: string, max_bytes: int, max_entries: ?int, validate: callable(mixed): ?string}>
     */
    public static function keys(): array
    {
        return [
            /*
             * Favorit — rute NAV yang dibintangi, urut sesuai urutan
             * pembintangan (app.js menggambarnya apa adanya). Keanggotaan NAV
             * diperiksa lewat SpaNav: bintang pada layar yang tidak ada adalah
             * baris mati yang dibawa-bawa selamanya.
             */
            'favorites' => [
                'label' => 'Favorit',
                'max_bytes' => 4096,
                'max_entries' => self::FAVORITES_MAX,
                'validate' => static fn (mixed $value): ?string => self::validateList(
                    $value,
                    self::FAVORITES_MAX,
                    'favorit',
                    static function (mixed $route): ?string {
                        if (! is_string($route) || $route === '' || strlen($route) > 120) {
                            return 'Setiap favorit harus berupa rute layar.';
                        }

                        return SpaNav::hasRoute($route)
                            ? null
                            : sprintf('Rute "%s" bukan layar mana pun di menu.', $route);
                    },
                ),
            ],

            /*
             * Terakhir dibuka — dokumen, bukan layar: rutenya `d/<resource>/<id>`
             * dan labelnya kode dokumen yang dibaca dari remah roti. Tidak ada
             * pemeriksaan keanggotaan NAV (dokumen memang tidak ada di menu);
             * yang dijaga adalah BENTUK dan jumlah field, supaya baris ini tidak
             * pernah menjadi tempat menitipkan data lain.
             */
            'recent' => [
                'label' => 'Terakhir dibuka',
                'max_bytes' => 8192,
                'max_entries' => self::RECENT_MAX,
                'validate' => static fn (mixed $value): ?string => self::validateList(
                    $value,
                    self::RECENT_MAX,
                    'dokumen',
                    static function (mixed $entry): ?string {
                        if (! is_array($entry) || array_is_list($entry)) {
                            return 'Setiap entri "Terakhir dibuka" harus berupa objek.';
                        }

                        $extra = array_diff(array_keys($entry), self::RECENT_FIELDS);
                        if ($extra !== []) {
                            return sprintf('Field tidak dikenal pada "Terakhir dibuka": %s.', implode(', ', $extra));
                        }

                        if (! isset($entry['route']) || ! is_string($entry['route']) || $entry['route'] === '' || strlen($entry['route']) > 200) {
                            return 'Setiap entri "Terakhir dibuka" harus punya rute.';
                        }

                        foreach (['label', 'sub', 'at'] as $field) {
                            $one = $entry[$field] ?? null;
                            if ($one !== null && (! is_string($one) || strlen($one) > 200)) {
                                return sprintf('Field "%s" pada "Terakhir dibuka" harus teks singkat.', $field);
                            }
                        }

                        return null;
                    },
                ),
            ],

            /*
             * Kepadatan (P1-B) — pindah dari localStorage `nusantara_erp_density
             * :<id>` ke sini. Nilainya SKALAR; itulah alasan kolom value
             * menyimpan JSON mentah, bukan array.
             */
            'density' => [
                'label' => 'Kepadatan',
                'max_bytes' => 64,
                'max_entries' => null,
                'validate' => static fn (mixed $value): ?string => is_string($value) && in_array($value, self::DENSITIES, true)
                    ? null
                    : sprintf('Kepadatan hanya boleh %s.', implode(', ', self::DENSITIES)),
            ],

            /*
             * Dicadangkan untuk P1-D (dasbor yang bisa diatur). Didaftarkan
             * SEKARANG supaya laci "Atur dasbor" nanti tidak perlu migrasi
             * kedua; validatornya sengaja hanya bentuk + plafon, karena daftar
             * widget yang sah baru ada di P1-D dan mengarangnya di sini berarti
             * menolak susunan yang belum sempat ditulis.
             */
            'dashboard.layout' => [
                'label' => 'Susunan dasbor',
                'max_bytes' => self::MAX_BYTES,
                'max_entries' => null,
                'validate' => static fn (mixed $value): ?string => is_array($value) && array_is_list($value)
                    ? null
                    : 'Susunan dasbor harus berupa daftar.',
            ],

            /*
             * Modul yang disembunyikan dari launcher #/home. Prefix grup NAV,
             * diperiksa lewat SpaNav dengan alasan yang sama seperti favorit.
             */
            'launcher.hidden' => [
                'label' => 'Modul disembunyikan',
                'max_bytes' => 512,
                'max_entries' => self::HIDDEN_MAX,
                'validate' => static fn (mixed $value): ?string => self::validateList(
                    $value,
                    self::HIDDEN_MAX,
                    'modul',
                    static function (mixed $prefix): ?string {
                        if (! is_string($prefix) || $prefix === '' || strlen($prefix) > 16) {
                            return 'Setiap modul harus berupa prefix menu.';
                        }

                        return SpaNav::hasPrefix($prefix)
                            ? null
                            : sprintf('Prefix "%s" bukan modul mana pun di menu.', $prefix);
                    },
                ),
            ],
        ];
    }

    /**
     * Whitelist sebagaimana KLIEN perlu tahu — satu entri per kunci dengan
     * kedua plafon yang benar-benar berlaku padanya.
     *
     * Kenapa berbentuk ini. Sampai verifikasi P1-C (6 Sep 2026) meta hanya
     * membawa daftar nama kunci dan MAX_BYTES, "supaya prefs.js tidak menyalin
     * daftar kunci ke klien" — tetapi prefs.js tidak pernah membaca meta sama
     * sekali dan tetap menyalin justru angka-angka yang tidak ada di sana
     * (50 favorit, 20 entri terakhir), sementara meta yang dipercaya mentah
     * akan membangun daftar favorit 16 KB yang ditolak server pada 4096.
     * Sekarang yang diumumkan adalah plafon yang berlaku, dan prefs.js
     * membacanya.
     *
     * @return list<array{key: string, label: string, max_bytes: int, max_entries: ?int}>
     */
    public static function describe(): array
    {
        $out = [];

        foreach (self::keys() as $key => $entry) {
            $out[] = [
                'key' => $key,
                'label' => $entry['label'],
                'max_bytes' => min($entry['max_bytes'], self::MAX_BYTES),
                'max_entries' => $entry['max_entries'],
            ];
        }

        return $out;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::keys());
    }

    /** Ukuran nilai sebagaimana ia akan DISIMPAN (JSON terkode), dalam byte. */
    public static function encodedBytes(mixed $value): int
    {
        /*
         * Diukur PERSIS seperti kolomnya menulis: cast 'json' milik Eloquent
         * memanggil json_encode tanpa flag, jadi setiap karakter non-ASCII
         * menjadi \uXXXX. Dengan JSON_UNESCAPED_UNICODE (yang dipakai di sini
         * sampai 6 Sep 2026) satu nilai bisa terukur 16.384 byte dan mendarat
         * 49.144 byte di kolom — rasio 3× untuk emoji, terukur di kedua driver
         * (verifikasi P1-C putaran 2). Angka di pesan 422 harus angka yang
         * benar-benar disimpan; kalau tidak, plafonnya bohong pada arah yang
         * berbahaya (TEXT MySQL 65.535 byte).
         */
        return strlen((string) json_encode($value));
    }

    /**
     * Kalimat penolakan untuk nilai ini, atau null bila sah. Kuncinya SELALU
     * disebut: pesan "Nilai tidak valid" pada endpoint yang melayani lima kunci
     * tidak memberi tahu siapa pun kunci mana yang salah.
     */
    public static function reject(string $key, mixed $value): ?string
    {
        $entry = self::keys()[$key] ?? null;

        if ($entry === null) {
            return sprintf('Preferensi "%s" tidak dikenal.', $key);
        }

        $limit = min($entry['max_bytes'], self::MAX_BYTES);
        $bytes = self::encodedBytes($value);

        if ($bytes > $limit) {
            return sprintf('Nilai preferensi "%s" berukuran %d byte, melebihi batas %d byte.', $key, $bytes, $limit);
        }

        $error = ($entry['validate'])($value);

        return $error === null ? null : sprintf('%s: %s', $key, $error);
    }

    /**
     * @param  callable(mixed): ?string  $each
     */
    private static function validateList(mixed $value, int $max, string $noun, callable $each): ?string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return 'Nilainya harus berupa daftar.';
        }

        if (count($value) > $max) {
            return sprintf('Maksimal %d %s.', $max, $noun);
        }

        foreach ($value as $one) {
            $error = $each($one);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }
}
