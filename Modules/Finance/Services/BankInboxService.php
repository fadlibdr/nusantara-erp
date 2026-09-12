<?php

namespace Modules\Finance\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Enums\BankStatementFormat;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankInboxFile;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Support\ImportPreset;
use Throwable;

/**
 * Folder terpantau rekening koran (P-3c, T3c.2) — satu-satunya permukaan
 * disk aplikasi ini, dan aplikasi HANYA MEMBACANYA.
 *
 * Bentuknya: <folder>/<KODE-REKENING>/<berkas>. Berkas sampai ke sana dari
 * luar aplikasi (scp/rclone/salinan manual oleh pemilik — bukan SFTP yang
 * ditarik aplikasi, bukan host-to-host; docs/KEPUTUSAN-INTEGRASI.md §10).
 * Tidak satu pun berkas ditulis, dipindah, diganti nama, atau dihapus di
 * sini; yang ditulis adalah LEDGER fin_bank_inbox_files, satu baris per
 * (jalur relatif, sha256 isi), sehingga pemeriksaan per jam idempoten:
 *
 *  - berkas yang sama (sha256 sama, nama sama) → baris yang sama, hanya
 *    checked_at bergerak; tidak diimpor dua kali;
 *  - berkas yang DIGANTI NAMA (isi sudah diimpor, nama lain) → `duplicate`;
 *  - berkas yang ISINYA berubah (nama sama, sha256 lain) → berkas baru; baris
 *    lama yang belum menjadi rekening koran (failed/ignored/duplicate) menjadi
 *    `superseded` supaya tidak dihitung lagi (putaran verifikasi V-folder-3).
 *
 * Baris yang DITOLAK SEBELUM DIBACA (tautan simbolik ke luar folder, sub-folder
 * asing, > 2 MB, hak akses) tidak punya sha256 isi — kuncinya diturunkan dari
 * jalur relatif (pathKey), bukan dari isi: isi berkas yang dinyatakan "tidak
 * dibaca" memang tidak pernah dibaca, dan berkas sebesar apa pun tidak pernah
 * masuk memori (V-folder-2, V-permukaan-6).
 *
 * Impor lewat BankStatementImportService yang SAMA dengan layar (tie-out,
 * rantai periode/saldo per rekening, identitas sha256 lintas rekening,
 * transaksional) — tidak ada jalur kedua yang lebih longgar. CSV di folder
 * tidak punya operator yang mengetik periode/saldo: keduanya DITURUNKAN dari
 * kolom saldo berkas (CsvStatementParser::deriveEndpoints) dan hanya bila
 * preset rekening memetakan kolom saldo; MT940 tidak butuh preset.
 *
 * Satu pemeriksaan pada satu waktu: kunci cache LOCK_KEY menahan tombol
 * "Periksa sekarang" yang ditekan tepat saat pemeriksaan terjadwal sedang berlangsung (V-folder-1);
 * jadwalnya sendiri withoutOverlapping(). Yang kedua tidak menunggu — ia
 * pulang dengan kalimat LOCKED_NOTE dan tidak menulis apa pun.
 *
 * Gagal → baris `failed` dengan kalimat Indonesia + SATU notifikasi per berkas
 * (dedupe judul + signature = 40 karakter pertama kunci baris, renag 7 hari),
 * bukan tiap jam; berhasil → satu notifikasi ringkas dengan tautan ke rekening
 * korannya. Template notifikasi null = generik (NotificationTemplates), dan
 * itu sengaja: tidak ada template WhatsApp/e-mail untuk peristiwa ini. Kalimat
 * notifikasi tidak pernah memuat jalur absolut server — hanya jalur relatif.
 *
 * Folder yang belum ada adalah keadaan bawaan setiap instalasi baru (termasuk
 * produksi sesudah deploy): scan() berkata begitu dan selesai tanpa galat,
 * tanpa baris ledger, tanpa notifikasi.
 */
class BankInboxService
{
    /** Kunci core_settings (SettingService::INTERNAL_KEYS) — stempel yang benar-benar ditulis pemeriksaan. */
    public const CHECKED_AT_KEY = 'bank_inbox.checked_at';

    public const CONFIGURED_VIA = 'BANK_INBOX_PATH';

    public const LAYOUT = '<folder terpantau>/<KODE-REKENING>/<berkas>';

    /** Relatif terhadap storage_path(): dikecualikan `rsync --delete` deploy dan ikut dicadangkan bersama lampiran. */
    public const DEFAULT_PATH = 'app/private/bank-inbox';

    public const FOLDER_MISSING_NOTE = 'Folder terpantau belum ada di server; administrator membuatnya sesuai PANDUAN-ADMINISTRATOR §5.13. Sampai itu tidak ada berkas yang diperiksa.';

    /**
     * Folder ADA tetapi proses aplikasi tidak boleh membacanya (mis. disalin `scp -r` dari home
     * administrator dengan umask 077 → 0700 root, sementara aplikasi berjalan sebagai www-data).
     * Tanpa kalimat ini pemeriksaan pulang "0 berkas" dengan stempel yang bergerak tiap jam —
     * kegagalan diam yang justru dijanjikan tidak ada (V-close-1).
     */
    public const FOLDER_UNREADABLE_NOTE = 'Folder terpantau ada di server tetapi tidak bisa dibaca proses aplikasi (hak akses); pastikan pengguna aplikasi (www-data) boleh membaca foldernya — PANDUAN-ADMINISTRATOR §5.13. Sampai itu tidak ada berkas yang diperiksa.';

    public const FAILED_TITLE = 'Berkas rekening koran di folder terpantau gagal diimpor';

    /** Ubin layar menghitung pemeriksaan TERAKHIR (baris dengan checked_at = stempel), tabel = sejarah (V-folder-7). */
    public const COUNTS_NOTE = 'Ubin menghitung berkas pada pemeriksaan terakhir; tabel di bawah adalah seluruh sejarah ledger.';

    /** Kunci cache satu-pemeriksaan-pada-satu-waktu; 15 menit jauh di atas pemeriksaan terlama yang masuk akal. */
    public const LOCK_KEY = 'fin:bank-inbox';

    public const LOCK_SECONDS = 900;

    public const LOCKED_NOTE = 'Pemeriksaan folder terpantau lain sedang berjalan; coba lagi sebentar.';

    public const STATEMENT_DELETED_NOTE = 'Rekening koran hasil impor berkas ini sudah dihapus (obat pemetaan yang salah); bila berkasnya masih di folder, ia diimpor ulang pada pemeriksaan berikutnya.';

    private const EXTENSIONS = ['csv', 'txt', 'sta', '940', 'mt940'];

    private const RENAG_DAYS = 7;

    /**
     * Signature notifikasi = 40 karakter pertama kunci baris: core_notifications.document_code
     * adalah varchar(40) (migrasi Core 000140). sha256 utuh (64) LOLOS di SQLite (panjang tidak
     * ditegakkan) dan DITOLAK MySQL — galatnya ditelan NotificationService::guard(), jadi tidak ada
     * satu notifikasi pun yang lahir dan tidak ada galat yang terlihat; terukur di gerbang
     * erp_dryrun (4 uji merah), hijau di SQLite.
     */
    public const SIGNATURE_LENGTH = 40;

    public static function signature(string $sha256): string
    {
        return substr($sha256, 0, self::SIGNATURE_LENGTH);
    }

    /**
     * Kunci baris untuk berkas yang isinya TIDAK dibaca: sha256 dari (jenis, jalur relatif[, ukuran]).
     * Berbeda per berkas (dua berkas tak terbaca = dua notifikasi), sama antar jam (satu notifikasi,
     * bukan tiap jam), dan tidak pernah sama dengan sha256 isi mana pun yang mungkin.
     */
    public static function pathKey(string $kind, string $relative): string
    {
        return hash('sha256', $kind.'|'.$relative);
    }

    public function __construct(
        private readonly BankStatementImportService $imports,
        private readonly CsvStatementParser $csv,
        private readonly NotificationService $notifications,
        private readonly SettingService $settings,
    ) {}

    /**
     * Jalur folder terpantau. Bawaannya diselesaikan DI SINI, bukan di
     * config/erp.php: berkas konfigurasi itu di-`require` apa adanya oleh
     * penyedia data statis (DocumentFormatValidationTest) tanpa aplikasi yang
     * di-boot, dan `storage_path()` di dalamnya menjatuhkan SELURUH suite
     * ("Call to undefined method Container::storagePath()").
     */
    public function path(): string
    {
        $configured = trim((string) config('erp.bank_inbox.path'));

        return rtrim($configured !== '' ? $configured : storage_path(self::DEFAULT_PATH), '/');
    }

    /** Benar bila jalurnya bawaan (env kosong) — yang dilaporkan layar. */
    public function isDefaultPath(): bool
    {
        return trim((string) config('erp.bank_inbox.path')) === '';
    }

    public function maxBytes(): int
    {
        return (int) config('erp.bank_inbox.max_bytes', 2_000_000);
    }

    public function folderExists(): bool
    {
        $path = $this->path();

        return $path !== '' && is_dir($path);
    }

    /**
     * Folder ada DAN daftar isinya bisa dibaca. Dipisahkan dari folderExists() karena keduanya
     * adalah dua kegagalan yang berbeda dengan dua obat yang berbeda (buat foldernya vs perbaiki
     * hak aksesnya), dan yang kedua tidak terlihat sama sekali sebelum V-close-1.
     */
    public function folderReadable(): bool
    {
        return $this->folderExists() && $this->entries($this->path()) !== false;
    }

    public function lastCheckedAt(): ?string
    {
        $value = $this->settings->get(self::CHECKED_AT_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Satu pemeriksaan penuh. Idempoten; tidak menulis ke folder. Bila pemeriksaan
     * lain sedang memegang kuncinya: locked = true, tidak ada yang dibaca atau ditulis.
     *
     * @return array{folder_exists: bool, folder_readable: bool, locked: bool, checked_at: string|null, counts: array<string, int>}
     */
    public function scan(): array
    {
        $counts = ['seen' => 0, 'imported' => 0, 'failed' => 0, 'duplicate' => 0, 'ignored' => 0, 'unchanged' => 0];
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return ['folder_exists' => $this->folderExists(), 'folder_readable' => $this->folderReadable(), 'locked' => true, 'checked_at' => $this->lastCheckedAt(), 'counts' => $counts];
        }

        try {
            return $this->runScan($counts);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{folder_exists: bool, folder_readable: bool, locked: bool, checked_at: string, counts: array<string, int>}
     */
    private function runScan(array $counts): array
    {
        $now = CarbonImmutable::now();

        if (! $this->folderExists()) {
            $this->settings->set(self::CHECKED_AT_KEY, $now->toIso8601String());

            return ['folder_exists' => false, 'folder_readable' => false, 'locked' => false, 'checked_at' => $now->toIso8601String(), 'counts' => $counts];
        }

        $root = realpath($this->path()) ?: $this->path();
        $accounts = BankAccount::query()->where('is_active', true)->get()->keyBy('code');
        $rootEntries = $this->entries($root);

        // Akar yang tidak terbaca bukan "folder kosong": tidak satu berkas pun bisa terlihat, jadi
        // tidak ada baris ledger yang bisa ditulis untuk berkas yang mana pun. Yang bisa dikatakan
        // adalah keadaan foldernya — di layar dan di keluaran perintah, bukan diam (V-close-1).
        if ($rootEntries === false) {
            $this->settings->set(self::CHECKED_AT_KEY, $now->toIso8601String());

            return ['folder_exists' => true, 'folder_readable' => false, 'locked' => false, 'checked_at' => $now->toIso8601String(), 'counts' => $counts];
        }

        foreach ($rootEntries as $name) {
            $path = $root.'/'.$name;

            // Sub-folder yang sebenarnya TAUTAN SIMBOLIK tidak diikuti sama sekali: sebelum ini
            // is_dir() benar untuk tautan, scandir membaca daftar isi folder tujuan (di mana pun ia
            // berada di server), dan nama-nama berkas di luar folder terpantau muncul di ledger
            // sebagai "Diabaikan" — daftar direktori asing yang dipulangkan API kepada setiap
            // pemegang fin.view, dengan kalimat yang justru mengaku tidak mengikuti tautan
            // (V-close-2). Satu baris untuk tautannya, tanpa membaca apa pun di baliknya.
            if (is_link($path) && is_dir($path)) {
                $counts['seen']++;
                $status = $this->record($name.'/', null, BankInboxFile::IGNORED, sprintf(
                    'Sub-folder %s adalah tautan simbolik; tidak diikuti (daftar isi folder tujuannya tidak dibaca).',
                    $name,
                ), null, $now, self::pathKey('symlink', $name.'/'), null, null);
                $counts[$status]++;

                continue;
            }

            if (is_dir($path)) {
                // Nama yang tidak mungkin menjadi kode rekening: setiap berkasnya tetap DICATAT
                // `ignored` dengan kalimatnya — dilewati bisu adalah kegagalan diam (V-permukaan-3).
                $badName = ! preg_match(BankAccount::CODE_PATTERN, $name);
                $inside = $this->entries($path);

                // Sub-folder ada tetapi daftar isinya tidak terbaca: berkas di dalamnya tidak
                // terlihat sama sekali, jadi yang dicatat adalah SUB-FOLDERNYA — satu baris, satu
                // notifikasi (dedupe per jalur), bukan "0 berkas" tiap jam (V-close-1).
                if ($inside === false) {
                    $counts['seen']++;
                    $status = $this->record($name.'/', $accounts->get($name), BankInboxFile::FAILED, sprintf(
                        'Sub-folder %s tidak bisa dibaca (hak akses); berkas di dalamnya tidak terlihat sama sekali. Pastikan pengguna aplikasi (www-data) boleh membaca foldernya — PANDUAN-ADMINISTRATOR §5.13.',
                        $name,
                    ), null, $now, self::pathKey('unreadable-dir', $name.'/'), null, null, notify: true);
                    $counts[$status]++;

                    continue;
                }

                foreach ($inside as $file) {
                    $filePath = $path.'/'.$file;

                    if (! is_file($filePath)) {
                        continue;   // sub-folder bersarang tidak dibaca
                    }

                    $counts['seen']++;
                    $relative = $name.'/'.$file;

                    try {
                        $status = $badName
                            ? $this->record($relative, null, BankInboxFile::IGNORED, sprintf(
                                'Sub-folder %s bukan nama yang sah untuk kode rekening (huruf/angka/titik/strip/garis bawah, tanpa spasi); berkas tidak dibaca.',
                                $name,
                            ), null, $now, self::pathKey('ignored', $relative), $this->size($filePath), $this->mtime($filePath))
                            : $this->handle($root, $relative, $filePath, $accounts->get($name), $now);
                    } catch (Throwable $e) {
                        // Satu berkas tidak boleh menghentikan pemeriksaan berkas lain (V-folder-2).
                        $status = $this->recordUnprocessable($relative, $accounts->get($name), $e, $now);
                    }

                    $counts[$status]++;
                }

                continue;
            }

            if (is_file($path)) {
                $counts['seen']++;
                $status = $this->record($name, null, BankInboxFile::IGNORED,
                    'Berkas di akar folder terpantau tidak dibaca; letakkan di sub-folder kode rekening (mis. BANK-BCA-OPS/).',
                    null, $now, self::pathKey('ignored', $name), $this->size($path), $this->mtime($path));
                $counts[$status]++;
            }
        }

        $this->settings->set(self::CHECKED_AT_KEY, $now->toIso8601String());

        return ['folder_exists' => true, 'folder_readable' => true, 'locked' => false, 'checked_at' => $now->toIso8601String(), 'counts' => $counts];
    }

    /**
     * Muatan layar/API: tidak ada jalur absolut, kalimat dari sini.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $exists = $this->folderExists();
        $readable = $exists && $this->folderReadable();
        $files = BankInboxFile::query()->with(['bankAccount', 'bankStatement'])->orderByDesc('checked_at')->orderByDesc('id')->limit(500)->get();
        $counts = ['imported' => 0, 'failed' => 0, 'duplicate' => 0, 'ignored' => 0];
        $stamp = $this->lastCheckedAt();

        if ($stamp !== null) {
            // Pemeriksaan terakhir = baris yang distempel checked_at yang sama dengan stempel yang
            // ditulis pemeriksaan itu (satu $now untuk seluruh pemeriksaan), dalam zona waktu aplikasi.
            $at = CarbonImmutable::parse($stamp)->setTimezone((string) config('app.timezone'));

            foreach (BankInboxFile::query()->where('checked_at', $at)->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
                if (array_key_exists($row->status, $counts)) {
                    $counts[$row->status] = (int) $row->n;
                }
            }
        }

        return [
            'folder' => [
                'exists' => $exists,
                'configured_via' => self::CONFIGURED_VIA,
                'is_default' => $this->isDefaultPath(),
                'layout' => self::LAYOUT,
                'readable' => $readable,
                'note' => match (true) {
                    ! $exists => self::FOLDER_MISSING_NOTE,
                    ! $readable => self::FOLDER_UNREADABLE_NOTE,
                    default => null,
                },
            ],
            'last_checked_at' => $stamp,
            'counts' => $counts,
            'counts_note' => self::COUNTS_NOTE,
            'accounts' => BankAccount::query()->where('is_active', true)->orderBy('code')->get()
                ->map(fn (BankAccount $account): array => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'preset' => $this->presetReadiness($account),
                    'subfolder' => $this->subfolderReadiness($account),
                ])->values()->all(),
            'files' => $files->map(function (BankInboxFile $file): array {
                // Rekening koran hasil impornya sudah dihapus (obat pemetaan yang salah): dikatakan di
                // layar, bukan "Diimpor" dengan kolom rekening koran kosong (V-folder-4).
                $statementGone = in_array($file->status, [BankInboxFile::IMPORTED, BankInboxFile::DUPLICATE], true)
                    && $file->bank_statement_id !== null && $file->bankStatement === null;

                return [
                    'id' => $file->id,
                    'relative_path' => $file->relative_path,
                    'status' => $statementGone ? BankInboxFile::STATEMENT_DELETED : $file->status,
                    'status_label' => $statementGone ? BankInboxFile::STATUS_LABELS[BankInboxFile::STATEMENT_DELETED] : $file->statusLabel(),
                    'error' => $statementGone ? self::STATEMENT_DELETED_NOTE : $file->error,
                    'size' => $file->size,
                    'file_mtime' => $file->file_mtime?->toIso8601String(),
                    'first_seen_at' => $file->first_seen_at?->toIso8601String(),
                    'checked_at' => $file->checked_at?->toIso8601String(),
                    'bank_account' => $file->bankAccount ? ['id' => $file->bankAccount->id, 'code' => $file->bankAccount->code, 'name' => $file->bankAccount->name] : null,
                    'bank_statement' => $file->bankStatement ? ['id' => $file->bankStatement->id, 'code' => $file->bankStatement->code] : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array{name: string|null, auto_csv: bool, note: string}
     */
    public function presetReadiness(BankAccount $account): array
    {
        $preset = $account->importPreset();

        if ($preset === null) {
            return ['name' => null, 'auto_csv' => false,
                'note' => 'Tanpa preset: berkas CSV rekening ini di folder akan gagal; MT940 tetap dibaca. Simpan preset dari tab Impor.'];
        }

        $name = (string) $preset['name'];

        if (! ImportPreset::hasBalanceColumn($preset)) {
            return ['name' => $name, 'auto_csv' => false,
                'note' => "Preset «{$name}» tidak memetakan kolom saldo: berkas CSV rekening ini di folder akan gagal (periode dan saldo tidak bisa diturunkan); impor lewat layar, atau simpan ulang preset dengan kolom saldo."];
        }

        return ['name' => $name, 'auto_csv' => true,
            'note' => "Preset «{$name}» memetakan kolom saldo: berkas CSV rekening ini dibaca dari folder; periode dan saldo diturunkan dari kolom saldo berkas."];
    }

    /**
     * Kode rekening lama yang tidak memenuhi BankAccount::CODE_PATTERN (Request baru menolaknya) tidak
     * bisa menjadi nama sub-folder — kartu Kesiapan mengatakannya, bukan menggambar sub-folder yang
     * tidak akan pernah dibaca (V-permukaan-3).
     *
     * @return array{valid: bool, note: string|null}
     */
    public function subfolderReadiness(BankAccount $account): array
    {
        if (preg_match(BankAccount::CODE_PATTERN, (string) $account->code) !== 1) {
            return ['valid' => false, 'readable' => true, 'note' => sprintf(
                'Kode rekening %s tidak bisa menjadi nama sub-folder (huruf/angka/titik/strip/garis bawah, tanpa spasi); ubah kodenya di Keuangan › Rekening Bank agar berkasnya bisa dibaca dari folder.',
                $account->code,
            )];
        }

        // Sub-folder rekening ini ada tetapi tidak terbaca: kartu Kesiapan mengatakannya, karena
        // ledger hanya bisa menyebut sub-foldernya dan bukan berkas yang ada di dalamnya (V-close-1).
        $path = $this->path().'/'.$account->code;

        if (is_dir($path) && ! is_link($path) && $this->entries($path) === false) {
            return ['valid' => true, 'readable' => false, 'note' => sprintf(
                'Sub-folder %s ada tetapi tidak bisa dibaca proses aplikasi (hak akses); berkas di dalamnya tidak terlihat sama sekali — PANDUAN-ADMINISTRATOR §5.13.',
                $account->code,
            )];
        }

        return ['valid' => true, 'readable' => true, 'note' => null];
    }

    // ------------------------------------------------------------- per berkas

    /**
     * Daftar isi satu direktori, atau FALSE bila direktorinya tidak bisa dibaca — dua hal yang
     * sebelumnya sama-sama menjadi daftar kosong, sehingga folder tanpa hak baca tidak bisa
     * dibedakan dari folder kosong dan pemeriksaan pulang diam (V-close-1).
     *
     * `protected` supaya uji bisa menyuntik kegagalan hak akses: suite berjalan sebagai root, dan
     * root menembus chmod 000 (CAP_DAC_OVERRIDE) — tanpa seam ini cabang ini tidak bisa diuji
     * di sini sama sekali.
     *
     * @return list<string>|false nama entri, urut, tanpa yang tersembunyi
     */
    protected function entries(string $dir): array|false
    {
        $names = @scandir($dir);

        if ($names === false) {
            return false;
        }

        return array_values(array_filter($names, static fn (string $name): bool => $name !== '.' && $name !== '..' && ! str_starts_with($name, '.')));
    }

    /**
     * @return string status ledger yang dihasilkan (kunci counts)
     */
    private function handle(string $root, string $relative, string $path, ?BankAccount $account, CarbonImmutable $now): string
    {
        $code = explode('/', $relative, 2)[0];

        // Jalur nyata harus tetap di bawah folder: tautan simbolik yang menunjuk ke luar tidak dibaca —
        // targetnya tidak disentuh sama sekali (tidak dihash, tidak diukur, tidak di-stat).
        $real = realpath($path);

        if ($real === false || ! str_starts_with($real, $root.'/')) {
            return $this->record($relative, null, BankInboxFile::IGNORED,
                'Berkas menunjuk ke luar folder terpantau (tautan simbolik); tidak dibaca.', null, $now, self::pathKey('symlink', $relative), null, null);
        }

        clearstatcache(true, $path);
        $size = $this->size($path);
        $mtime = $this->mtime($path);

        if ($account === null) {
            return $this->record($relative, null, BankInboxFile::IGNORED,
                "Sub-folder {$code} bukan kode rekening bank yang aktif; berkas tidak dibaca.", null, $now, self::pathKey('ignored', $relative), $size, $mtime);
        }

        // Ukuran diperiksa SEBELUM satu byte pun dibaca: berkas sebesar apa pun tidak masuk memori.
        if ($size > $this->maxBytes()) {
            return $this->record($relative, $account, BankInboxFile::FAILED, sprintf(
                'Berkas lebih dari 2 MB (%s byte); rekening koran sebulan tidak sebesar ini — periksa berkasnya.',
                number_format($size, 0, ',', '.'),
            ), null, $now, self::pathKey('oversize', $relative.'|'.$size), $size, $mtime, notify: true);
        }

        $raw = $this->read($path);

        if ($raw === false) {
            return $this->record($relative, $account, BankInboxFile::FAILED,
                'Berkas tidak bisa dibaca (hak akses); pastikan www-data boleh membaca folder dan berkasnya.',
                null, $now, self::pathKey('unreadable', $relative), $size, $mtime, notify: true);
        }

        $sha = hash('sha256', $raw);
        $existing = BankInboxFile::query()->where('relative_path', $relative)->where('sha256', $sha)->first();

        // Sudah pernah diproses dan selesai — dan rekening korannya masih ada: hanya stempelnya
        // yang bergerak. Rekening koran yang sudah dihapus = berkas ini diproses lagi (V-folder-4).
        if ($existing !== null && $this->isSettled($existing)) {
            $existing->forceFill(['checked_at' => $now, 'size' => $size, 'file_mtime' => $mtime])->save();

            return 'unchanged';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS, true)) {
            return $this->record($relative, $account, BankInboxFile::FAILED,
                sprintf('Ekstensi .%s tidak dikenal; yang dibaca hanya .csv, .txt, .sta, .940, .mt940.', $extension), null, $now, $sha, $size, $mtime, notify: true);
        }

        $content = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        $format = $extension === 'csv' || ($extension === 'txt' && ! str_contains($content, ':61:'))
            ? BankStatementFormat::Csv->value
            : BankStatementFormat::Mt940->value;

        try {
            // Identitas normalisasi service yang sama dengan layar: salinan berganti nama, salinan
            // yang beda byte tak-berarti, dan berkas yang diimpor lewat layar — semuanya satu cabang,
            // dan kalimatnya menyebut kanal dari FAKTANYA (V-folder-5).
            $imported = BankStatement::query()->where('content_hash', $this->imports->hash($format, $content))->first();

            if ($imported !== null) {
                return $this->record($relative, $account, BankInboxFile::DUPLICATE,
                    $this->duplicateSentence($imported, $relative), $imported->id, $now, $sha, $size, $mtime);
            }

            $mapping = [];

            if ($format === BankStatementFormat::Csv->value) {
                $preset = $account->importPreset();

                if ($preset === null) {
                    throw new LogicException(
                        "Rekening {$account->code} belum punya preset impor. Simpan preset dari layar Impor sesudah pratinjau pemetaan Anda berhasil."
                    );
                }

                if (! ImportPreset::hasBalanceColumn($preset)) {
                    throw new LogicException(sprintf(
                        'Preset «%s» rekening %s tidak memetakan kolom saldo: preset tanpa kolom saldo tidak bisa diimpor otomatis; impor lewat layar.',
                        (string) $preset['name'],
                        $account->code,
                    ));
                }

                // SATU jalur dengan layar: header diperiksa oleh resolveMapping, periode/saldo dari berkas.
                $mapping = $this->imports->resolveMapping($account, $format, $content, [], true);
                $mapping = ImportPreset::merge($preset, $this->csv->deriveEndpoints($content, $mapping, (string) $preset['name']));
            }

            $preview = $this->imports->preview($account, $format, $content, $mapping, unattended: true);

            if ($preview['blockers'] !== []) {
                throw new LogicException(implode(' ', $preview['blockers']));
            }

            $statement = $this->imports->import($account, $format, $content, $mapping, null, unattended: true);
        } catch (LogicException $e) {
            // Kalah balapan dari pemeriksaan/operator lain yang mengimpor berkas yang sama di tengah
            // (import() memantulkan "Berkas ini sudah diimpor…" — BankStatementImportService): itu
            // salinan, bukan kegagalan, dan tidak dibunyikan sebagai gagal (V-folder-1).
            if (str_starts_with($e->getMessage(), 'Berkas ini sudah diimpor')) {
                $imported = BankStatement::query()->where('content_hash', $this->imports->hash($format, $content))->first();

                if ($imported !== null) {
                    return $this->record($relative, $account, BankInboxFile::DUPLICATE,
                        $this->duplicateSentence($imported, $relative), $imported->id, $now, $sha, $size, $mtime);
                }
            }

            return $this->record($relative, $account, BankInboxFile::FAILED, $e->getMessage(), null, $now, $sha, $size, $mtime, notify: true);
        } catch (Throwable $e) {
            // Bukan LogicException = bukan penolakan yang dirancang; tetap dicatat
            // sebagai baris gagal supaya pemeriksaan berkas lain berjalan terus.
            Log::error("fin:bank-inbox: {$relative}: ".get_class($e).': '.$e->getMessage());

            return $this->record($relative, $account, BankInboxFile::FAILED,
                'Berkas tidak bisa diproses ('.class_basename($e).'); rincian di log aplikasi. Coba impor lewat layar.', null, $now, $sha, $size, $mtime, notify: true);
        }

        $status = $this->record($relative, $account, BankInboxFile::IMPORTED, null, $statement->id, $now, $sha, $size, $mtime);

        if ($status !== BankInboxFile::IMPORTED) {
            return $status;   // baris pemenang balapan sudah menyebut rekening korannya; jangan diumumkan dua kali
        }

        $this->notifications->system(
            'fin.update',
            "Rekening koran {$statement->code} diimpor dari folder terpantau",
            sprintf(
                'Berkas %s untuk rekening %s %s diimpor sebagai %s (%d mutasi). Cocokkan mutasinya di Rekonsiliasi Bank.',
                $relative,
                $account->code,
                $account->name,
                $statement->code,
                $statement->line_count,
            ),
            "/bank-recon?tab=statements&account={$account->id}&statement={$statement->id}",
            null,
            self::signature($sha),
            null,   // template null = generik: tidak ada template kanal luar untuk peristiwa ini, sengaja
        );

        return $status;
    }

    /**
     * Kalimat salinan menyebut kanal dari faktanya: baris ledger `imported` lain yang menunjuk rekening
     * koran itu → "Isi berkas sama dengan <jalur>"; tanpa baris ledger dan tanpa operator → tanpa klaim
     * kanal; hanya bila imported_by terisi disebut "(lewat layar Impor)".
     */
    private function duplicateSentence(BankStatement $statement, string $relative): string
    {
        $ledger = BankInboxFile::query()
            ->where('bank_statement_id', $statement->id)
            ->where('status', BankInboxFile::IMPORTED)
            ->where('relative_path', '!=', $relative)
            ->orderBy('id')
            ->first();

        if ($ledger !== null) {
            return sprintf('Isi berkas sama dengan %s yang sudah diimpor sebagai %s.', $ledger->relative_path, $statement->code);
        }

        return $statement->imported_by === null
            ? "Berkas ini sudah diimpor sebagai {$statement->code}."
            : "Berkas ini sudah diimpor sebagai {$statement->code} (lewat layar Impor).";
    }

    /** Baris yang selesai dan rekening korannya masih ada — tidak diproses ulang dan tidak boleh diturunkan. */
    private function isSettled(BankInboxFile $row): bool
    {
        return in_array($row->status, [BankInboxFile::IMPORTED, BankInboxFile::DUPLICATE], true)
            && $row->bank_statement_id !== null
            && BankStatement::query()->whereKey($row->bank_statement_id)->exists();
    }

    private function recordUnprocessable(string $relative, ?BankAccount $account, Throwable $e, CarbonImmutable $now): string
    {
        Log::error("fin:bank-inbox: {$relative}: ".get_class($e).': '.$e->getMessage());

        try {
            return $this->record($relative, $account, BankInboxFile::FAILED,
                'Berkas tidak bisa diproses ('.class_basename($e).'); rincian di log aplikasi. Coba impor lewat layar.',
                null, $now, self::pathKey('error', $relative), null, null, notify: true);
        } catch (Throwable $inner) {
            Log::error("fin:bank-inbox: {$relative}: baris ledger gagal ditulis: ".$inner->getMessage());

            return BankInboxFile::FAILED;
        }
    }

    /**
     * Tulis/segarkan baris ledger; notifikasi gagal sekali per berkas (signature dari kunci baris).
     * Baris `imported`/`duplicate` yang rekening korannya masih ada TIDAK PERNAH diturunkan — proses
     * yang kalah balapan hanya menyentuh stempelnya (V-folder-1). Baris lain untuk jalur yang sama
     * yang belum menjadi rekening koran menjadi `superseded` (V-folder-3).
     *
     * @return string status (kunci counts)
     */
    private function record(
        string $relative,
        ?BankAccount $account,
        string $status,
        ?string $error,
        ?int $statementId,
        CarbonImmutable $now,
        string $sha,
        ?int $size,
        ?CarbonImmutable $mtime,
        bool $notify = false,
    ): string {
        $row = BankInboxFile::query()->where('relative_path', $relative)->where('sha256', $sha)->first();

        if ($row !== null && $status !== BankInboxFile::IMPORTED && $this->isSettled($row)) {
            $row->forceFill(['checked_at' => $now])->save();

            return 'unchanged';
        }

        $fields = [
            'size' => $size ?? 0,
            'file_mtime' => $mtime,
            'status' => $status,
            'bank_account_id' => $account?->id,
            'bank_statement_id' => $statementId,
            'error' => $error,
            'first_seen_at' => $row?->first_seen_at ?? $now,
            'checked_at' => $now,
        ];

        try {
            ($row ?? (new BankInboxFile)->forceFill(['relative_path' => $relative, 'sha256' => $sha]))->forceFill($fields)->save();
        } catch (UniqueConstraintViolationException) {
            // Proses lain baru saja melahirkan baris yang sama: baca ulang; bila ia sudah selesai,
            // jangan ditimpa — bila belum, tulis di atasnya.
            $row = BankInboxFile::query()->where('relative_path', $relative)->where('sha256', $sha)->first();

            if ($row === null) {
                throw new LogicException("Baris ledger {$relative} tidak bisa ditulis.");
            }

            if ($status !== BankInboxFile::IMPORTED && $this->isSettled($row)) {
                $row->forceFill(['checked_at' => $now])->save();

                return 'unchanged';
            }

            $row->forceFill($fields)->save();
        }

        BankInboxFile::query()
            ->where('relative_path', $relative)
            ->where('sha256', '!=', $sha)
            ->whereIn('status', [BankInboxFile::FAILED, BankInboxFile::IGNORED, BankInboxFile::DUPLICATE])
            ->update(['status' => BankInboxFile::SUPERSEDED, 'updated_at' => $now]);

        if ($notify && $status === BankInboxFile::FAILED) {
            $this->notifications->system(
                'fin.update',
                self::FAILED_TITLE,
                sprintf('Berkas %s untuk rekening %s: %s', $relative, $account ? "{$account->code} {$account->name}" : '?', (string) $error),
                '/bank-recon?tab=inbox',
                self::RENAG_DAYS,
                self::signature($sha),
                null,   // template null = generik, sengaja
            );
        }

        return $status;
    }

    /**
     * Satu-satunya tempat isi berkas dibaca — sesudah ukurannya lolos batas. Protected supaya uji
     * (yang berjalan sebagai root, yang chmod tidak menghalanginya) bisa menyuntikkan pembaca yang gagal.
     */
    protected function read(string $path): string|false
    {
        return @file_get_contents($path);
    }

    private function size(string $path): int
    {
        return (int) @filesize($path);
    }

    private function mtime(string $path): ?CarbonImmutable
    {
        $mtime = @filemtime($path);

        return $mtime === false ? null : CarbonImmutable::createFromTimestamp($mtime);
    }
}
