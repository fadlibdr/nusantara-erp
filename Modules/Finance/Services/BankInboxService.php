<?php

namespace Modules\Finance\Services;

use Carbon\CarbonImmutable;
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
 *  - berkas yang DIGANTI NAMA (sha256 sudah ada, nama lain) → `duplicate`;
 *  - berkas yang ISINYA berubah (nama sama, sha256 lain) → berkas baru.
 *
 * Impor lewat BankStatementImportService yang SAMA dengan layar (tie-out,
 * rantai periode/saldo per rekening, identitas sha256 lintas rekening,
 * transaksional) — tidak ada jalur kedua yang lebih longgar. CSV di folder
 * tidak punya operator yang mengetik periode/saldo: keduanya DITURUNKAN dari
 * kolom saldo berkas (CsvStatementParser::deriveEndpoints) dan hanya bila
 * preset rekening memetakan kolom saldo; MT940 tidak butuh preset.
 *
 * Gagal → baris `failed` dengan kalimat Indonesia + SATU notifikasi per berkas
 * (dedupe judul + signature = 40 karakter pertama sha256, renag 7 hari), bukan tiap jam; berhasil
 * → satu notifikasi ringkas dengan tautan ke rekening korannya. Template
 * notifikasi null = generik (NotificationTemplates), dan itu sengaja: tidak
 * ada template WhatsApp/e-mail untuk peristiwa ini. Kalimat notifikasi tidak
 * pernah memuat jalur absolut server — hanya jalur relatif di bawah folder.
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

    public const FOLDER_MISSING_NOTE = 'Folder terpantau belum ada di server; administrator membuatnya sesuai PANDUAN-ADMINISTRATOR §5.13. Sampai itu tidak ada berkas yang diperiksa.';

    public const FAILED_TITLE = 'Berkas rekening koran di folder terpantau gagal diimpor';

    private const EXTENSIONS = ['csv', 'txt', 'sta', '940', 'mt940'];

    /** Kode rekening sebagai nama sub-folder: huruf/angka/titik/strip/garis bawah, tanpa pemisah jalur. */
    private const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/';

    private const RENAG_DAYS = 7;

    /**
     * Signature notifikasi = 40 karakter pertama sha256 berkas: core_notifications.document_code
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

    public function __construct(
        private readonly BankStatementImportService $imports,
        private readonly CsvStatementParser $csv,
        private readonly NotificationService $notifications,
        private readonly SettingService $settings,
    ) {}

    public function path(): string
    {
        return rtrim((string) config('erp.bank_inbox.path'), '/');
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

    public function lastCheckedAt(): ?string
    {
        $value = $this->settings->get(self::CHECKED_AT_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Satu pemeriksaan penuh. Idempoten; tidak menulis ke folder.
     *
     * @return array{folder_exists: bool, checked_at: string, counts: array<string, int>}
     */
    public function scan(): array
    {
        $now = CarbonImmutable::now();
        $counts = ['seen' => 0, 'imported' => 0, 'failed' => 0, 'duplicate' => 0, 'ignored' => 0, 'unchanged' => 0];

        if (! $this->folderExists()) {
            $this->settings->set(self::CHECKED_AT_KEY, $now->toIso8601String());

            return ['folder_exists' => false, 'checked_at' => $now->toIso8601String(), 'counts' => $counts];
        }

        $root = realpath($this->path()) ?: $this->path();
        $accounts = BankAccount::query()->where('is_active', true)->get()->keyBy('code');

        foreach ($this->entries($root) as $name) {
            $path = $root.'/'.$name;

            if (is_dir($path)) {
                if (! preg_match(self::CODE_PATTERN, $name)) {
                    continue;   // nama yang bukan kode rekening yang mungkin: tidak dibaca, tidak dicatat
                }

                foreach ($this->entries($path) as $file) {
                    $filePath = $path.'/'.$file;

                    if (! is_file($filePath)) {
                        continue;   // sub-folder bersarang tidak dibaca
                    }

                    $counts['seen']++;
                    $status = $this->handle($root, $name.'/'.$file, $filePath, $accounts->get($name), $now);
                    $counts[$status]++;
                }

                continue;
            }

            if (is_file($path)) {
                $counts['seen']++;
                $status = $this->record($name, $path, null, BankInboxFile::IGNORED,
                    'Berkas di akar folder terpantau tidak dibaca; letakkan di sub-folder kode rekening (mis. BANK-BCA-OPS/).', null, $now);
                $counts[$status]++;
            }
        }

        $this->settings->set(self::CHECKED_AT_KEY, $now->toIso8601String());

        return ['folder_exists' => true, 'checked_at' => $now->toIso8601String(), 'counts' => $counts];
    }

    /**
     * Muatan layar/API: tidak ada jalur absolut, kalimat dari sini.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $exists = $this->folderExists();
        $files = BankInboxFile::query()->with(['bankAccount', 'bankStatement'])->orderByDesc('checked_at')->orderByDesc('id')->limit(500)->get();
        $counts = ['imported' => 0, 'failed' => 0, 'duplicate' => 0, 'ignored' => 0];

        foreach (BankInboxFile::query()->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            if (array_key_exists($row->status, $counts)) {
                $counts[$row->status] = (int) $row->n;
            }
        }

        return [
            'folder' => [
                'exists' => $exists,
                'configured_via' => self::CONFIGURED_VIA,
                'is_default' => (string) config('erp.bank_inbox.path') === storage_path('app/private/bank-inbox'),
                'layout' => self::LAYOUT,
                'note' => $exists ? null : self::FOLDER_MISSING_NOTE,
            ],
            'last_checked_at' => $this->lastCheckedAt(),
            'counts' => $counts,
            'accounts' => BankAccount::query()->where('is_active', true)->orderBy('code')->get()
                ->map(fn (BankAccount $account): array => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'preset' => $this->presetReadiness($account),
                ])->values()->all(),
            'files' => $files->map(fn (BankInboxFile $file): array => [
                'id' => $file->id,
                'relative_path' => $file->relative_path,
                'status' => $file->status,
                'status_label' => $file->statusLabel(),
                'error' => $file->error,
                'size' => $file->size,
                'file_mtime' => $file->file_mtime?->toIso8601String(),
                'first_seen_at' => $file->first_seen_at?->toIso8601String(),
                'checked_at' => $file->checked_at?->toIso8601String(),
                'bank_account' => $file->bankAccount ? ['id' => $file->bankAccount->id, 'code' => $file->bankAccount->code, 'name' => $file->bankAccount->name] : null,
                'bank_statement' => $file->bankStatement ? ['id' => $file->bankStatement->id, 'code' => $file->bankStatement->code] : null,
            ])->values()->all(),
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

    // ------------------------------------------------------------- per berkas

    /**
     * @return list<string> nama entri, urut, tanpa yang tersembunyi
     */
    private function entries(string $dir): array
    {
        $names = @scandir($dir);

        if ($names === false) {
            return [];
        }

        return array_values(array_filter($names, static fn (string $name): bool => $name !== '.' && $name !== '..' && ! str_starts_with($name, '.')));
    }

    /**
     * @return string status ledger yang dihasilkan (kunci counts)
     */
    private function handle(string $root, string $relative, string $path, ?BankAccount $account, CarbonImmutable $now): string
    {
        $code = explode('/', $relative, 2)[0];

        // Jalur nyata harus tetap di bawah folder: symlink yang menunjuk ke luar tidak dibaca.
        $real = realpath($path);

        if ($real === false || ! str_starts_with($real, $root.'/')) {
            return $this->record($relative, $path, null, BankInboxFile::IGNORED,
                'Berkas menunjuk ke luar folder terpantau (tautan simbolik); tidak dibaca.', null, $now);
        }

        if ($account === null) {
            return $this->record($relative, $path, null, BankInboxFile::IGNORED,
                "Sub-folder {$code} bukan kode rekening bank yang aktif; berkas tidak dibaca.", null, $now);
        }

        clearstatcache(true, $path);
        $size = (int) @filesize($path);

        if ($size > $this->maxBytes()) {
            return $this->record($relative, $path, $account, BankInboxFile::FAILED, sprintf(
                'Berkas lebih dari 2 MB (%s byte); rekening koran sebulan tidak sebesar ini — periksa berkasnya.',
                number_format($size, 0, ',', '.'),
            ), null, $now, notify: true, sha: null);
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return $this->record($relative, $path, $account, BankInboxFile::FAILED,
                'Berkas tidak bisa dibaca (hak akses); pastikan www-data boleh membaca folder dan berkasnya.', null, $now, notify: true, sha: null);
        }

        $sha = hash('sha256', $raw);
        $existing = BankInboxFile::query()->where('relative_path', $relative)->where('sha256', $sha)->first();

        // Sudah pernah diproses dan selesai: hanya stempelnya yang bergerak.
        if ($existing !== null && in_array($existing->status, [BankInboxFile::IMPORTED, BankInboxFile::DUPLICATE], true)) {
            $existing->forceFill(['checked_at' => $now, 'size' => $size, 'file_mtime' => $this->mtime($path)])->save();

            return 'unchanged';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS, true)) {
            return $this->record($relative, $path, $account, BankInboxFile::FAILED,
                sprintf('Ekstensi .%s tidak dikenal; yang dibaca hanya .csv, .txt, .sta, .940, .mt940.', $extension), null, $now, notify: true, sha: $sha);
        }

        $content = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        $format = $extension === 'csv' || ($extension === 'txt' && ! str_contains($content, ':61:'))
            ? BankStatementFormat::Csv->value
            : BankStatementFormat::Mt940->value;

        // Salinan berganti nama: sha256 yang sama sudah diimpor lewat folder di bawah jalur lain.
        $twin = BankInboxFile::query()->where('sha256', $sha)->where('status', BankInboxFile::IMPORTED)->whereNotNull('bank_statement_id')->first();

        if ($twin !== null) {
            $statement = BankStatement::query()->find($twin->bank_statement_id);

            return $this->record($relative, $path, $account, BankInboxFile::DUPLICATE, sprintf(
                'Isi berkas sama dengan %s yang sudah diimpor sebagai %s.',
                $twin->relative_path,
                $statement?->code ?? '?',
            ), $statement?->id, $now, sha: $sha);
        }

        try {
            // Berkas yang sudah diimpor lewat layar Impor: identitas normalisasi service yang sama.
            $imported = BankStatement::query()->where('content_hash', $this->imports->hash($format, $content))->first();

            if ($imported !== null) {
                return $this->record($relative, $path, $account, BankInboxFile::DUPLICATE,
                    "Berkas ini sudah diimpor sebagai {$imported->code} (lewat layar Impor).", $imported->id, $now, sha: $sha);
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

            $preview = $this->imports->preview($account, $format, $content, $mapping);

            if ($preview['blockers'] !== []) {
                throw new LogicException(implode(' ', $preview['blockers']));
            }

            $statement = $this->imports->import($account, $format, $content, $mapping, null);
        } catch (LogicException $e) {
            return $this->record($relative, $path, $account, BankInboxFile::FAILED, $e->getMessage(), null, $now, notify: true, sha: $sha);
        } catch (Throwable $e) {
            // Bukan LogicException = bukan penolakan yang dirancang; tetap dicatat
            // sebagai baris gagal supaya pemeriksaan berkas lain berjalan terus.
            Log::error("fin:bank-inbox: {$relative}: ".get_class($e).': '.$e->getMessage());

            return $this->record($relative, $path, $account, BankInboxFile::FAILED,
                'Berkas tidak bisa diproses ('.class_basename($e).'); rincian di log aplikasi. Coba impor lewat layar.', null, $now, notify: true, sha: $sha);
        }

        $status = $this->record($relative, $path, $account, BankInboxFile::IMPORTED, null, $statement->id, $now, sha: $sha);

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
     * Tulis/segarkan baris ledger; notifikasi gagal sekali per berkas (signature = sha256).
     *
     * @return string status (kunci counts)
     */
    private function record(
        string $relative,
        string $path,
        ?BankAccount $account,
        string $status,
        ?string $error,
        ?int $statementId,
        CarbonImmutable $now,
        bool $notify = false,
        ?string $sha = null,
    ): string {
        $sha ??= hash('sha256', (string) @file_get_contents($path));
        clearstatcache(true, $path);

        $row = BankInboxFile::query()->firstOrNew(['relative_path' => $relative, 'sha256' => $sha]);
        $row->forceFill([
            'size' => (int) @filesize($path),
            'file_mtime' => $this->mtime($path),
            'status' => $status,
            'bank_account_id' => $account?->id,
            'bank_statement_id' => $statementId,
            'error' => $error,
            'first_seen_at' => $row->exists ? $row->first_seen_at : $now,
            'checked_at' => $now,
        ])->save();

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

    private function mtime(string $path): ?CarbonImmutable
    {
        $mtime = @filemtime($path);

        return $mtime === false ? null : CarbonImmutable::createFromTimestamp($mtime);
    }
}
