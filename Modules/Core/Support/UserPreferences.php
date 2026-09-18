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

    /*
     * Plafon jumlah widget. Katalog P1-D berisi 19; angka di sini sedikit lebih
     * longgar supaya menambah widget di rilis berikutnya tidak menolak susunan
     * yang baru saja sah, dan tetap jauh di bawah plafon byte — sebuah dasbor
     * dengan 24 kartu sudah bukan dasbor.
     */
    private const DASHBOARD_MAX = 24;

    /** Field yang boleh ada pada satu entri susunan dasbor. */
    private const DASHBOARD_FIELDS = ['id', 'size'];

    private const RECENT_FIELDS = ['route', 'label', 'sub', 'at'];

    /*
     * Tampilan tersimpan (UI-4c). 20 per orang, dan itu plafon ORANG, bukan
     * per layar: seseorang yang menyimpan dua tampilan di sepuluh layar sudah
     * memakai seluruh jatahnya, dan itu memang jumlah tampilan yang bisa
     * diingat siapa pun.
     */
    private const VIEWS_MAX = 20;

    private const VIEW_FIELDS = ['route', 'name', 'query'];

    private const VIEW_NAME_MAX = 60;

    /*
     * Panjang query. Daftar tersibuk mendeklarasikan empat filter; ditambah
     * kotak cari, pasangan tanggal dan urutan, sebuah tampilan penuh muat jauh
     * di bawah angka ini. Ia ada supaya kolom `query` tidak pernah menjadi
     * tempat menitipkan 8 KB yang kebetulan berbentuk query string.
     */
    private const VIEW_QUERY_MAX = 240;

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
             * Susunan dasbor (P1-D). Didaftarkan sejak P1-C dengan validator
             * bentuk saja — "daftar widget yang sah baru ada di P1-D dan
             * mengarangnya di sini berarti menolak susunan yang belum sempat
             * ditulis". P1-D menulisnya, jadi validatornya sekarang memeriksa
             * ISI: id yang benar-benar ada di katalog SPA (SpaWidgets membaca
             * registry.js, alasan yang sama dengan SpaNav untuk favorit) dan
             * ukuran yang benar-benar dikenal.
             *
             * KENAPA ITU PENTING DI SINI dan bukan cukup di klien: laci "Atur
             * dasbor" tidak akan pernah mengirim id karangan, tetapi endpoint
             * ini tanpa gerbang izin — barisnya milik pemanggil sendiri — dan
             * tanpa daftar ini ia adalah penyimpanan bebas 16 KB per pengguna
             * yang disalin ke setiap backup selamanya. Whitelist per KUNCI
             * menutup pintu itu; whitelist per ISI menutup pintu yang sama satu
             * lapis lebih dalam.
             *
             * Duplikat ditolak, bukan didiamkan: dua entri dengan id yang sama
             * hanya bisa menggambar satu kartu (resolveLayout membuang yang
             * kedua), jadi menyimpannya berarti menyimpan baris yang tidak akan
             * pernah berarti apa-apa.
             */
            'dashboard.layout' => [
                'label' => 'Susunan dasbor',
                'max_bytes' => self::MAX_BYTES,
                'max_entries' => self::DASHBOARD_MAX,
                'validate' => static function (mixed $value): ?string {
                    if (! is_array($value) || ! array_is_list($value)) {
                        return 'Susunan dasbor harus berupa daftar.';
                    }

                    if (count($value) > self::DASHBOARD_MAX) {
                        return sprintf('Maksimal %d widget.', self::DASHBOARD_MAX);
                    }

                    $seen = [];

                    foreach ($value as $entry) {
                        if (! is_array($entry) || array_is_list($entry)) {
                            return 'Setiap widget harus berupa objek {id, size}.';
                        }

                        $extra = array_diff(array_keys($entry), self::DASHBOARD_FIELDS);
                        if ($extra !== []) {
                            return sprintf('Field tidak dikenal pada susunan dasbor: %s.', implode(', ', $extra));
                        }

                        $id = $entry['id'] ?? null;
                        if (! is_string($id) || $id === '' || strlen($id) > 64) {
                            return 'Setiap widget harus punya id.';
                        }

                        if (! SpaWidgets::has($id)) {
                            return sprintf('Widget "%s" tidak ada di katalog dasbor.', $id);
                        }

                        if (isset($seen[$id])) {
                            return sprintf('Widget "%s" disebut dua kali.', $id);
                        }
                        $seen[$id] = true;

                        $size = $entry['size'] ?? null;
                        if ($size !== null && ! in_array($size, SpaWidgets::SIZES, true)) {
                            return sprintf('Ukuran widget hanya boleh %s.', implode(', ', SpaWidgets::SIZES));
                        }
                    }

                    return null;
                },
            ],

            /*
             * Kanal notifikasi LUAR yang dipilih orangnya (P-3a, T3a.2;
             * webpush sejak P-3e, T3e.3): SATU kunci bool per kanal di
             * DeliveryGate::USER_CHANNELS. Kunci yang tidak disebut = nyala
             * (bawaan milik pengirim: kanal yang mati diam-diam untuk semua
             * orang bukan bawaan). Dibaca DeliveryGate::userEnabled di kotak
             * keluar, Kirim ulang, dan job — mematikannya di sini benar-benar
             * membuat baris `skipped` "Dimatikan pengguna di Profil".
             *
             * Di UserPreferences dan bukan tabel baru, dengan sengaja: ini
             * persis "apa pun yang seseorang PILIH untuk dirinya sendiri"
             * (CONVENTIONS §15), plafon dan whitelist-nya sudah ada, endpoint
             * dan cerminnya sudah ada — dan tidak butuh migrasi. Yang
             * membutuhkan kolom sungguhan adalah opt-in WhatsApp berstempel
             * waktu (T3a.3, users.whatsapp_opt_in_at): persetujuan bukan
             * preferensi, dan preferensi boleh dihapus tanpa jejak.
             */
            'notify.channels' => [
                'label' => 'Kanal notifikasi',
                'max_bytes' => 256,
                'max_entries' => null,
                'validate' => static function (mixed $value): ?string {
                    if (! is_array($value) || array_is_list($value)) {
                        // Bentuknya DITURUNKAN dari USER_CHANNELS, tidak dieja:
                        // kalimat yang mengeja kanalnya membusuk diam-diam pada
                        // kanal berikutnya, dan itu persis yang terjadi pada P-3e
                        // (putaran penutup, V-5) — pesan yang ADA untuk
                        // memberi tahu bentuk yang benar menyebut dua dari tiga.
                        return sprintf(
                            'Kanal notifikasi harus berupa objek {%s}.',
                            implode(', ', array_map(static fn (string $channel): string => $channel.': true/false', DeliveryGate::USER_CHANNELS)),
                        );
                    }

                    $extra = array_diff(array_keys($value), DeliveryGate::USER_CHANNELS);
                    if ($extra !== []) {
                        return sprintf('Kanal tidak dikenal: %s.', implode(', ', $extra));
                    }

                    foreach ($value as $channel => $on) {
                        if (! is_bool($on)) {
                            return sprintf('Nilai kanal "%s" harus true atau false.', $channel);
                        }
                    }

                    return null;
                },
            ],

            /*
             * Jam tenang (P-3a, T3a.2): {start:"HH:MM", end:"HH:MM"} WIB, atau
             * false = tanpa jam tenang (bukan null: kolomnya NOT NULL, dan
             * preferensi tidak punya DELETE). Aturannya milik QuietHours — pengirim
             * membaca nilai yang SAMA lewat kelas yang sama, jadi yang lolos
             * validasi di sini pasti bisa dihitung di sana.
             */
            'notify.quiet_hours' => [
                'label' => 'Jam tenang',
                'max_bytes' => 64,
                'max_entries' => null,
                'validate' => static fn (mixed $value): ?string => QuietHours::reject($value),
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

            /*
             * Tampilan tersimpan (UI-4c) — keadaan layar daftar yang diberi nama.
             *
             * `query` adalah PERSIS query string yang syncUrl() milik list.js
             * tulis ke bilah alamat, dan yang seedFromUrl() sudah tahu cara
             * membacanya. Menyimpan bentuk lain — {filters:{…}, sort:…} — berarti
             * penyerialisasi kedua dan pembaca kedua untuk keadaan yang sama,
             * dan keduanya akan hanyut terhadap daftar filter yang berubah
             * setiap paket. Sebuah tampilan tersimpan karena itu persis sebuah
             * tautan yang disalin dari bilah alamat, dengan nama.
             *
             * `query` boleh null: itulah bentuk tampilan "semua" setelah
             * ConvertEmptyStringsToNull menyentuhnya (lihat rejectViewQuery).
             *
             * Yang TIDAK disimpan: `page`. "Halaman 3 dari saringan saya" bukan
             * sebuah tampilan — ia sebuah posisi, dan posisi itu menunjuk baris
             * yang sama sekali berbeda besok pagi.
             *
             * Keanggotaan NAV diperiksa lewat SpaNav, alasan yang sama dengan
             * favorit; ditambah syarat `r/` — tampilan tersimpan adalah keadaan
             * LAYAR DAFTAR, dan sebuah tampilan untuk #/dashboard tidak punya arti.
             *
             * Bentuk `query` diperiksa RUAS PER RUAS, bukan hanya panjangnya:
             * tanpa itu kolom ini adalah string bebas 240 byte per entri yang
             * ikut ke setiap backup selamanya — pintu yang sama yang ditutup
             * whitelist per kunci, satu lapis lebih dalam (lihat dashboard.layout).
             */
            'list.views' => [
                'label' => 'Tampilan tersimpan',
                'max_bytes' => 8192,
                'max_entries' => self::VIEWS_MAX,
                'validate' => static fn (mixed $value): ?string => self::validateList(
                    $value,
                    self::VIEWS_MAX,
                    'tampilan',
                    static function (mixed $entry): ?string {
                        if (! is_array($entry) || array_is_list($entry)) {
                            return 'Setiap tampilan tersimpan harus berupa objek {route, name, query}.';
                        }

                        $extra = array_diff(array_keys($entry), self::VIEW_FIELDS);
                        if ($extra !== []) {
                            return sprintf('Field tidak dikenal pada tampilan tersimpan: %s.', implode(', ', $extra));
                        }

                        $route = $entry['route'] ?? null;
                        if (! is_string($route) || ! str_starts_with($route, 'r/') || strlen($route) > 120) {
                            return 'Setiap tampilan tersimpan harus punya rute layar daftar ("r/<sumber daya>").';
                        }

                        if (! SpaNav::hasRoute($route)) {
                            return sprintf('Rute "%s" bukan layar mana pun di menu.', $route);
                        }

                        $name = $entry['name'] ?? null;
                        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > self::VIEW_NAME_MAX) {
                            return sprintf('Nama tampilan wajib diisi dan maksimal %d karakter.', self::VIEW_NAME_MAX);
                        }

                        return self::rejectViewQuery($entry['query'] ?? '');
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
     * Query sebuah tampilan tersimpan: kosong, atau query string sungguhan.
     *
     * Diperiksa ruas per ruas — `k=v&k=v` — dengan kosakata karakter yang
     * PERSIS bisa dihasilkan `URLSearchParams.toString()` (form-urlencoded:
     * spasi jadi `+`, sisanya persen). Sebuah nilai yang tidak bisa lahir dari
     * bilah filter tidak punya urusan tersimpan di sini.
     */
    private static function rejectViewQuery(mixed $query): ?string
    {
        /*
         * `null` DAN `''` sama-sama berarti tampilan "semua" — sah, dan memang
         * berguna: ia jalan kembali dari tampilan tersaring ke daftar utuh.
         *
         * Keduanya harus diterima karena `''` TIDAK PERNAH SAMPAI KE SINI:
         * ConvertEmptyStringsToNull adalah middleware global (bootstrap/app.php),
         * jadi query kosong yang dikirim SPA mendarat sebagai null dan disimpan
         * sebagai null. Validator yang hanya menerima string akan menolak satu-
         * satunya bentuk yang bisa benar-benar dikirim — dan lolos di uji yang
         * memanggil reject() langsung, tanpa melewati HTTP.
         */
        if ($query === null || $query === '') {
            return null;
        }

        if (! is_string($query)) {
            return 'Query tampilan harus berupa teks.';
        }

        if (strlen($query) > self::VIEW_QUERY_MAX) {
            return sprintf('Query tampilan berukuran %d karakter, melebihi batas %d.', strlen($query), self::VIEW_QUERY_MAX);
        }

        foreach (explode('&', $query) as $pair) {
            if (preg_match('/^[A-Za-z0-9_.\-\[\]]+=[A-Za-z0-9*_.\-~%+]*$/', $pair) !== 1) {
                return sprintf('Ruas "%s" pada query tampilan bukan pasangan kunci=nilai yang sah.', $pair);
            }
        }

        return null;
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
