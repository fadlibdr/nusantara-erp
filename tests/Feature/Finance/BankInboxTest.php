<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\Notification;
use Modules\Core\Services\SettingService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankInboxFile;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Services\BankInboxService;
use Modules\Finance\Services\BankStatementImportService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Folder terpantau (P-3c, T3c.2): satu-satunya permukaan disk, dan aplikasi
 * HANYA MEMBACANYA. Ledger fin_bank_inbox_files yang ditulis, sehingga
 * pemeriksaan per jam idempoten; impor lewat BankStatementImportService yang
 * sama (tie-out, rantai, identitas); gagal → satu notifikasi per berkas,
 * bukan tiap jam; folder yang belum ada → diam, keluar 0.
 */
class BankInboxTest extends ErpTestCase
{
    use FinanceFixtures;

    private string $root;

    private BankAccount $bank;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->admin = $this->adminUser();   // pemegang fin.update/fin.create: penerima notifikasi
        $this->bank = $this->makeBankAccount('1-1210', ['code' => 'BANK-BCA-OPS']);
        $this->root = sys_get_temp_dir().'/bank-inbox-'.uniqid();
        config(['erp.bank_inbox.path' => $this->root]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeTree($this->root);
        }

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function drop(string $relative, string $content): string
    {
        $path = $this->root.'/'.$relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);

        return $path;
    }

    private function mt940(string $lines, string $ref = 'STMT260331', string $opening = 'C260301IDR1000000000,00', string $closing = 'C260331IDR1200000000,00', string $account = 'BCA/1234567890'): string
    {
        return implode("\n", [':20:'.$ref, ':25:'.$account, ':28C:00003/001', ':60F:'.$opening, $lines, ':62F:'.$closing]);
    }

    private function marchMt940(): string
    {
        return $this->mt940(implode("\n", [
            ':61:2603100310C150000000,00NTRFINV-1//BCA0001',
            ':86:Transfer masuk PT Graha Sentosa',
            ':61:2603150315D50000000,00NTRFPAY-1//BCA0002',
            ':86:Pembayaran vendor',
            ':61:2603200320C100000000,00NTRFINV-2//BCA0003',
            ':86:Transfer masuk termin 2',
        ]));
    }

    private function aprilMt940(): string
    {
        return $this->mt940(
            ':61:2604050405C100000000,00NTRFINV-3//BCA0004',
            'STMT260430', 'C260401IDR1200000000,00', 'C260430IDR1300000000,00',
        );
    }

    private function csv(): string
    {
        return implode("\n", [
            'Tanggal;Keterangan;Cabang;Debit;Kredit;Saldo',
            '10/03/2026;TRSF E-BANKING CR PT GRAHA;0001;;250.000.000,00;1.250.000.000,00',
            '15/03/2026;BIAYA ADM;0001;50.000.000,00;;1.200.000.000,00',
        ]);
    }

    private function savePreset(array $overrides = []): void
    {
        app(BankStatementImportService::class)->savePreset($this->bank, 'BCA KlikBCA', 'csv', $this->csv(), array_merge([
            'delimiter' => ';', 'skip_rows' => 1, 'date_column' => 0, 'date_format' => 'dd/mm/yyyy', 'description_column' => 1,
            'amount_mode' => 'debit_credit', 'debit_column' => 3, 'credit_column' => 4, 'balance_column' => 5, 'number_format' => 'id',
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_200_000_000,
        ], $overrides), $this->admin->id);
        $this->bank->refresh();
    }

    private function scan(): array
    {
        return app(BankInboxService::class)->scan();
    }

    private function alarms(): Collection
    {
        return Notification::query()->where('event', Notification::SYSTEM)->where('user_id', $this->admin->id)->orderBy('id')->get();
    }

    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas', 'email' => str()->random(8).'@nusantara.test', 'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** Potret folder: nama, ukuran, inode, mtime setiap entri — untuk membuktikan aplikasi tidak menulis. */
    private function snapshot(string $dir): array
    {
        clearstatcache(true);
        $out = [$dir => [filemtime($dir), fileinode($dir)]];

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            $out[$path] = [filemtime($path), fileinode($path), is_file($path) ? filesize($path) : null];

            if (is_dir($path)) {
                $out += $this->snapshot($path);
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ folder tidak ada

    public function test_a_missing_folder_says_so_exits_zero_and_touches_nothing(): void
    {
        $this->assertDirectoryDoesNotExist($this->root);

        $this->artisan('fin:bank-inbox')
            ->expectsOutputToContain('Folder terpantau belum ada')
            ->assertExitCode(0);

        $this->assertSame(0, BankInboxFile::query()->count());
        $this->assertCount(0, $this->alarms());
        $this->assertDirectoryDoesNotExist($this->root, 'perintah TIDAK boleh membuat foldernya');
        // Stempel "terakhir diperiksa" tetap ditulis: pemeriksaannya memang berjalan.
        $this->assertNotNull(app(SettingService::class)->get(BankInboxService::CHECKED_AT_KEY));
    }

    // ----------------------------------------------------------------- MT940

    public function test_a_new_mt940_file_is_imported_and_announced_once_with_a_relative_path(): void
    {
        $this->drop('BANK-BCA-OPS/maret.sta', $this->marchMt940());

        $summary = $this->scan();

        $this->assertSame(['seen' => 1, 'imported' => 1, 'failed' => 0, 'duplicate' => 0, 'ignored' => 0, 'unchanged' => 0], $summary['counts']);

        $row = BankInboxFile::query()->sole();
        $this->assertSame('BANK-BCA-OPS/maret.sta', $row->relative_path);
        $this->assertSame('imported', $row->status);
        $this->assertSame(hash('sha256', $this->marchMt940()), $row->sha256);
        $this->assertSame($this->bank->id, $row->bank_account_id);
        $this->assertNull($row->error);

        $statement = BankStatement::query()->findOrFail($row->bank_statement_id);
        $this->assertSame(3, $statement->line_count);
        $this->assertNull($statement->imported_by, 'impor dari folder tidak punya operator');

        $alarms = $this->alarms();
        $this->assertCount(1, $alarms);
        $this->assertSame("Rekening koran {$statement->code} diimpor dari folder terpantau", $alarms[0]->title);
        $this->assertSame(
            "Berkas BANK-BCA-OPS/maret.sta untuk rekening BANK-BCA-OPS BCA Operasional diimpor sebagai {$statement->code} (3 mutasi). Cocokkan mutasinya di Rekonsiliasi Bank.",
            $alarms[0]->body,
        );
        $this->assertSame("/bank-recon?tab=statements&account={$this->bank->id}&statement={$statement->id}", $alarms[0]->link);
        $this->assertStringNotContainsString($this->root, $alarms[0]->body, 'jalur absolut server bocor ke notifikasi');
        $this->assertNull($alarms[0]->template, 'template null = generik, sengaja');
    }

    public function test_the_same_file_on_the_next_hour_adds_no_row_no_statement_and_no_notification(): void
    {
        $this->drop('BANK-BCA-OPS/maret.sta', $this->marchMt940());
        $this->scan();
        $before = app(SettingService::class)->get(BankInboxService::CHECKED_AT_KEY);

        $this->travel(1)->hours();
        $summary = $this->scan();

        $this->assertSame(1, $summary['counts']['unchanged']);
        $this->assertSame(0, $summary['counts']['imported']);
        $this->assertSame(1, BankInboxFile::query()->count());
        $this->assertSame(1, BankStatement::query()->count());
        $this->assertCount(1, $this->alarms());
        $this->assertNotSame($before, app(SettingService::class)->get(BankInboxService::CHECKED_AT_KEY));
        $this->assertTrue(BankInboxFile::query()->sole()->checked_at->greaterThan(now()->subMinute()));
    }

    public function test_a_renamed_copy_is_a_duplicate_not_a_new_file(): void
    {
        $this->drop('BANK-BCA-OPS/maret.sta', $this->marchMt940());
        $this->scan();
        $this->drop('BANK-BCA-OPS/maret-salinan.sta', $this->marchMt940());

        $summary = $this->scan();

        $this->assertSame(1, $summary['counts']['duplicate']);
        $copy = BankInboxFile::query()->where('relative_path', 'BANK-BCA-OPS/maret-salinan.sta')->sole();
        $this->assertSame('duplicate', $copy->status);
        $this->assertSame(BankStatement::query()->sole()->id, $copy->bank_statement_id);
        $this->assertSame('Isi berkas sama dengan BANK-BCA-OPS/maret.sta yang sudah diimpor sebagai '.BankStatement::query()->sole()->code.'.', $copy->error);
        $this->assertSame(1, BankStatement::query()->count());
        $this->assertCount(1, $this->alarms(), 'salinan bukan peristiwa');
    }

    public function test_a_file_whose_content_changed_under_the_same_name_is_a_new_file(): void
    {
        $this->drop('BANK-BCA-OPS/koran.sta', $this->marchMt940());
        $this->scan();
        $this->drop('BANK-BCA-OPS/koran.sta', $this->aprilMt940());

        $summary = $this->scan();

        $this->assertSame(1, $summary['counts']['imported']);
        $rows = BankInboxFile::query()->where('relative_path', 'BANK-BCA-OPS/koran.sta')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->sha256, $rows[1]->sha256);
        $this->assertSame(2, BankStatement::query()->count());
    }

    public function test_a_statement_for_another_account_number_is_refused_and_says_which(): void
    {
        $this->drop('BANK-BCA-OPS/lain.sta', $this->mt940(':61:2603100310C200000000,00NTRFX//R1', 'X', 'C260301IDR1000000000,00', 'C260331IDR1200000000,00', 'MANDIRI/9988776655'));

        $this->scan();

        $row = BankInboxFile::query()->sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Rekening koran ini untuk rekening MANDIRI/9988776655', (string) $row->error);
        $this->assertSame(0, BankStatement::query()->count());
    }

    // ------------------------------------------------------------------- CSV

    public function test_a_csv_with_a_balance_column_preset_derives_period_and_balances_from_the_file(): void
    {
        $this->savePreset();
        $this->drop('BANK-BCA-OPS/maret.csv', $this->csv());

        $summary = $this->scan();

        $this->assertSame(1, $summary['counts']['imported'], json_encode(BankInboxFile::query()->pluck('error')));
        $statement = BankStatement::query()->sole();
        $this->assertSame('2026-03-10', $statement->period_start->toDateString());
        $this->assertSame('2026-03-15', $statement->period_end->toDateString());
        // saldo awal = saldo baris pertama − mutasi pertama = 1.250.000.000 − 250.000.000
        $this->assertSame('1000000000.00', $statement->opening_balance);
        $this->assertSame('1200000000.00', $statement->closing_balance);
        $this->assertSame(2, $statement->line_count);
        $this->assertSame('2026-03-10', $statement->parse_options['period_start']);
        $this->assertSame(5, $statement->parse_options['balance_column']);
    }

    public function test_a_csv_preset_without_a_balance_column_cannot_be_imported_unattended(): void
    {
        $this->savePreset(['balance_column' => null]);
        $this->drop('BANK-BCA-OPS/maret.csv', $this->csv());

        $this->scan();

        $row = BankInboxFile::query()->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame(
            'Preset «BCA KlikBCA» rekening BANK-BCA-OPS tidak memetakan kolom saldo: preset tanpa kolom saldo tidak bisa diimpor otomatis; impor lewat layar.',
            $row->error,
        );
        $this->assertSame(0, BankStatement::query()->count());
    }

    public function test_a_csv_for_an_account_without_a_preset_fails_with_the_way_out(): void
    {
        $this->drop('BANK-BCA-OPS/maret.csv', $this->csv());

        $this->scan();

        $row = BankInboxFile::query()->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame(
            'Rekening BANK-BCA-OPS belum punya preset impor. Simpan preset dari layar Impor sesudah pratinjau pemetaan Anda berhasil.',
            $row->error,
        );
    }

    public function test_a_csv_whose_header_shifted_fails_naming_the_column(): void
    {
        $this->savePreset();
        $this->drop('BANK-BCA-OPS/april.csv', str_replace('Debit', 'Mutasi', $this->csv()));

        $this->scan();

        $row = BankInboxFile::query()->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame("Kolom 4 pada preset «BCA KlikBCA» diharapkan 'Debit', berkas berisi 'Mutasi'.", $row->error);
    }

    /** dd/mm tanpa tahun tidak bisa menurunkan periode — dikatakan, bukan ditebak. */
    public function test_a_preset_whose_date_format_has_no_year_cannot_derive_the_period(): void
    {
        $noYear = str_replace(['10/03/2026', '15/03/2026'], ['10/03', '15/03'], $this->csv());
        app(BankStatementImportService::class)->savePreset($this->bank, 'Tanpa tahun', 'csv', $noYear, [
            'delimiter' => ';', 'skip_rows' => 1, 'date_column' => 0, 'date_format' => 'dd/mm', 'description_column' => 1,
            'amount_mode' => 'debit_credit', 'debit_column' => 3, 'credit_column' => 4, 'balance_column' => 5, 'number_format' => 'id',
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_200_000_000,
        ], $this->admin->id);
        $this->bank->refresh();
        $this->drop('BANK-BCA-OPS/maret.csv', $noYear);

        $this->scan();

        $row = BankInboxFile::query()->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame(
            'Format tanggal dd/mm pada preset «Tanpa tahun» tidak memuat tahun, jadi periode tidak bisa diturunkan dari berkas; impor lewat layar.',
            $row->error,
        );
    }

    // ---------------------------------------------------------- penolakan impor

    public function test_a_file_that_does_not_tie_out_fails_and_is_announced_once_not_every_hour(): void
    {
        $this->drop('BANK-BCA-OPS/salah.sta', $this->mt940(':61:2603100310C150000000,00NTRFINV-1//BCA0001'));

        $this->scan();
        $this->travel(1)->hours();
        $this->scan();
        $this->travel(1)->hours();
        $this->scan();

        $row = BankInboxFile::query()->sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Berkas tidak seimbang', (string) $row->error);

        $alarms = $this->alarms();
        $this->assertCount(1, $alarms, 'satu notifikasi per berkas, bukan tiap jam');
        $this->assertSame('Berkas rekening koran di folder terpantau gagal diimpor', $alarms[0]->title);
        $this->assertStringStartsWith('Berkas BANK-BCA-OPS/salah.sta untuk rekening BANK-BCA-OPS BCA Operasional: Berkas tidak seimbang', $alarms[0]->body);
        $this->assertSame('/bank-recon?tab=inbox', $alarms[0]->link);
        $this->assertSame(hash('sha256', $this->mt940(':61:2603100310C150000000,00NTRFINV-1//BCA0001')), $alarms[0]->document_code, 'signature = sha256 berkas');
        $this->assertStringNotContainsString($this->root, $alarms[0]->body);
    }

    public function test_a_broken_chain_fails_and_is_retried_on_the_next_hour_once_the_gap_is_filled(): void
    {
        $this->drop('BANK-BCA-OPS/april.sta', $this->aprilMt940());
        $this->drop('BANK-BCA-OPS/maret.sta', $this->mt940(':61:2603100310C200000000,00NTRFX//R1', 'STMT260331', 'C260301IDR1000000000,00', 'C260331IDR1100000000,00'));

        $this->scan();

        // Maret ditutup 1,1 M, April dibuka 1,2 M: yang mana pun yang masuk dulu, yang lain putus rantai.
        $this->assertSame(1, BankStatement::query()->count());
        $failed = BankInboxFile::query()->where('status', 'failed')->sole();
        $this->assertStringContainsString('Ada periode yang belum diimpor di antaranya', (string) $failed->error);
    }

    public function test_a_file_already_imported_from_the_screen_is_a_duplicate(): void
    {
        app(BankStatementImportService::class)->import($this->bank, 'mt940', $this->marchMt940(), [], $this->admin->id);
        $this->drop('BANK-BCA-OPS/maret.sta', $this->marchMt940());

        $this->scan();

        $row = BankInboxFile::query()->sole();
        $this->assertSame('duplicate', $row->status);
        $this->assertSame(BankStatement::query()->sole()->id, $row->bank_statement_id);
        $this->assertSame('Berkas ini sudah diimpor sebagai '.BankStatement::query()->sole()->code.' (lewat layar Impor).', $row->error);
        $this->assertCount(0, $this->alarms());
    }

    // ------------------------------------------------------------ folder & berkas

    public function test_a_subfolder_that_is_not_an_active_account_code_and_a_file_at_the_root_are_ignored_with_a_sentence(): void
    {
        $this->makeBankAccount('1-1220', ['code' => 'BANK-MDR-PRJ', 'is_active' => false]);
        $this->drop('BANK-XYZ/x.sta', $this->marchMt940());
        $this->drop('BANK-MDR-PRJ/y.sta', $this->marchMt940());
        $this->drop('akar.sta', $this->marchMt940());

        $summary = $this->scan();

        $this->assertSame(3, $summary['counts']['ignored']);
        $this->assertSame(0, BankStatement::query()->count());
        $this->assertSame(
            'Sub-folder BANK-XYZ bukan kode rekening bank yang aktif; berkas tidak dibaca.',
            BankInboxFile::query()->where('relative_path', 'BANK-XYZ/x.sta')->sole()->error,
        );
        $this->assertSame(
            'Sub-folder BANK-MDR-PRJ bukan kode rekening bank yang aktif; berkas tidak dibaca.',
            BankInboxFile::query()->where('relative_path', 'BANK-MDR-PRJ/y.sta')->sole()->error,
        );
        $this->assertSame(
            'Berkas di akar folder terpantau tidak dibaca; letakkan di sub-folder kode rekening (mis. BANK-BCA-OPS/).',
            BankInboxFile::query()->where('relative_path', 'akar.sta')->sole()->error,
        );
        $this->assertCount(0, $this->alarms());
    }

    public function test_an_oversized_file_and_an_unknown_extension_fail_with_a_sentence_not_an_exception(): void
    {
        $this->drop('BANK-BCA-OPS/besar.sta', str_repeat('x', 2_000_001));
        $this->drop('BANK-BCA-OPS/koran.pdf', '%PDF-1.4');

        $this->scan();

        $this->assertSame(
            'Berkas lebih dari 2 MB (2.000.001 byte); rekening koran sebulan tidak sebesar ini — periksa berkasnya.',
            BankInboxFile::query()->where('relative_path', 'BANK-BCA-OPS/besar.sta')->sole()->error,
        );
        $this->assertSame(
            'Ekstensi .pdf tidak dikenal; yang dibaca hanya .csv, .txt, .sta, .940, .mt940.',
            BankInboxFile::query()->where('relative_path', 'BANK-BCA-OPS/koran.pdf')->sole()->error,
        );
    }

    public function test_a_latin1_file_is_read_and_hidden_files_and_nested_folders_are_skipped(): void
    {
        $latin1 = mb_convert_encoding(str_replace('Pembayaran vendor', 'Pembayaran vendor — Café', $this->marchMt940()), 'ISO-8859-1', 'UTF-8');
        $this->assertFalse(mb_check_encoding($latin1, 'UTF-8'));
        $this->drop('BANK-BCA-OPS/maret.sta', $latin1);
        $this->drop('BANK-BCA-OPS/.tersembunyi.sta', $this->aprilMt940());
        $this->drop('BANK-BCA-OPS/arsip/lama.sta', $this->aprilMt940());

        $summary = $this->scan();

        $this->assertSame(1, $summary['counts']['seen']);
        $this->assertSame('imported', BankInboxFile::query()->sole()->status);
        $this->assertStringContainsString('Café', BankStatement::query()->sole()->lines[1]->description);
    }

    /** Aplikasi TIDAK menulis ke folder: potret nama/ukuran/inode/mtime sebelum = sesudah, termasuk sesudah impor berhasil dan gagal. */
    public function test_the_application_never_writes_moves_or_deletes_anything_in_the_folder(): void
    {
        $this->savePreset();
        $this->drop('BANK-BCA-OPS/maret.sta', $this->marchMt940());
        $this->drop('BANK-BCA-OPS/maret.csv', $this->csv());
        $this->drop('BANK-BCA-OPS/salah.sta', $this->mt940(':61:2603100310C150000000,00NTRFINV-1//BCA0001'));
        $this->drop('BANK-XYZ/x.sta', 'x');
        sleep(1);   // mtime bergranularitas detik: tulisan sesudah ini pasti terlihat
        $before = $this->snapshot($this->root);

        $this->scan();
        $this->travel(1)->hours();
        $this->scan();

        $this->assertSame($before, $this->snapshot($this->root));
        $this->assertSame(['imported', 'failed', 'failed', 'ignored'], BankInboxFile::query()->orderBy('relative_path')->pluck('status')->all());
    }

    // ------------------------------------------------------------------- API

    public function test_the_ledger_api_returns_relative_paths_only_and_needs_fin_view(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->drop('BANK-BCA-OPS/maret.sta', $this->marchMt940());
        $this->scan();

        $this->actingAs($this->userWith(['fin.view']), 'sanctum');
        $response = $this->getJson('/api/finance/bank-inbox')->assertOk();
        $data = $response->json('data');

        $this->assertTrue($data['folder']['exists']);
        $this->assertSame('BANK_INBOX_PATH', $data['folder']['configured_via']);
        $this->assertSame('<folder terpantau>/<KODE-REKENING>/<berkas>', $data['folder']['layout']);
        $this->assertNotNull($data['last_checked_at']);
        $this->assertSame('BANK-BCA-OPS/maret.sta', $data['files'][0]['relative_path']);
        $this->assertSame('Diimpor', $data['files'][0]['status_label']);
        $this->assertSame(BankStatement::query()->sole()->code, $data['files'][0]['bank_statement']['code']);
        $this->assertSame(['imported' => 1, 'failed' => 0, 'duplicate' => 0, 'ignored' => 0], $data['counts']);
        $this->assertStringNotContainsString($this->root, $response->getContent(), 'jalur absolut server bocor ke API');

        $account = collect($data['accounts'])->firstWhere('code', 'BANK-BCA-OPS');
        $this->assertFalse($account['preset']['auto_csv']);
        $this->assertSame('Tanpa preset: berkas CSV rekening ini di folder akan gagal; MT940 tetap dibaca. Simpan preset dari tab Impor.', $account['preset']['note']);

        $this->actingAs($this->userWith(['hr.view']), 'sanctum');
        $this->getJson('/api/finance/bank-inbox')->assertForbidden();
    }

    public function test_the_ledger_api_says_when_the_folder_does_not_exist_and_has_never_been_checked(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->userWith(['fin.view']), 'sanctum');

        $data = $this->getJson('/api/finance/bank-inbox')->assertOk()->json('data');

        $this->assertFalse($data['folder']['exists']);
        $this->assertSame(
            'Folder terpantau belum ada di server; administrator membuatnya sesuai PANDUAN-ADMINISTRATOR §5.13. Sampai itu tidak ada berkas yang diperiksa.',
            $data['folder']['note'],
        );
        $this->assertNull($data['last_checked_at']);
        $this->assertSame([], $data['files']);
    }

    public function test_running_the_check_from_the_screen_needs_fin_create_and_returns_the_summary(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->drop('BANK-BCA-OPS/maret.sta', $this->marchMt940());

        $this->actingAs($this->userWith(['fin.view']), 'sanctum');
        $this->postJson('/api/finance/bank-inbox/run')->assertForbidden();
        $this->assertSame(0, BankInboxFile::query()->count());

        $this->actingAs($this->userWith(['fin.view', 'fin.create']), 'sanctum');
        $data = $this->postJson('/api/finance/bank-inbox/run')->assertOk()->json('data');

        $this->assertSame(1, $data['summary']['counts']['imported']);
        $this->assertSame('imported', $data['files'][0]['status']);
    }

    /** Preset dengan kolom saldo → rekening siap diimpor tanpa operator; kalimatnya dari server. */
    public function test_the_accounts_list_says_which_accounts_can_take_a_csv_unattended(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->savePreset();

        $this->actingAs($this->userWith(['fin.view']), 'sanctum');
        $account = collect($this->getJson('/api/finance/bank-inbox')->json('data.accounts'))->firstWhere('code', 'BANK-BCA-OPS');

        $this->assertTrue($account['preset']['auto_csv']);
        $this->assertSame('BCA KlikBCA', $account['preset']['name']);
        $this->assertSame('Preset «BCA KlikBCA» memetakan kolom saldo: berkas CSV rekening ini dibaca dari folder; periode dan saldo diturunkan dari kolom saldo berkas.', $account['preset']['note']);
    }

    // -------------------------------------------------------------- penjadwal

    /** Cadence-nya yang dipaku, bukan sekadar namanya (pola SchedulerHeartbeatTest). */
    public function test_the_inbox_check_is_scheduled_hourly(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('php artisan fin:bank-inbox')->assertExitCode(0);

        // schedule:list merapikan kolom ekspresi cron ("0   * * * *"), jadi spasinya longgar — ekspresinya yang dipaku.
        Artisan::call('schedule:list');
        $this->assertMatchesRegularExpression('/^\s*0\s+\*\s+\*\s+\*\s+\*\s+php artisan fin:bank-inbox/m', Artisan::output(),
            'fin:bank-inbox harus hourly() (menit 0 tiap jam), bukan cadence lain');
    }

    public function test_the_command_prints_the_counts_and_exits_zero_even_when_a_file_failed(): void
    {
        $this->drop('BANK-BCA-OPS/salah.sta', $this->mt940(':61:2603100310C150000000,00NTRFINV-1//BCA0001'));

        $this->artisan('fin:bank-inbox')
            ->expectsOutputToContain('1 berkas: 0 diimpor, 1 gagal, 0 salinan, 0 diabaikan, 0 tidak berubah')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------------- layar

    /** Tab "Folder terpantau" membaca ledger dari API, tidak mengklaim penjadwal hidup, dan tidak menjanjikan "otomatis dari bank". */
    public function test_the_screen_reads_the_ledger_from_the_api_and_makes_no_promise_the_code_does_not_keep(): void
    {
        $screen = (string) file_get_contents(public_path('app/js/views/bankrecon.js'));

        $this->assertStringContainsString("{ key: 'inbox', label: 'Folder terpantau' }", $screen);
        $this->assertStringContainsString("api.get('finance/bank-inbox')", $screen);
        $this->assertStringContainsString("api.postRaw('finance/bank-inbox/run')", $screen);
        $this->assertStringContainsString('folder.note', $screen, 'kalimat folder-belum-ada harus dari server');
        $this->assertStringContainsString('last_checked_at', $screen);
        $this->assertStringContainsString('file.status_label', $screen, 'label status dari server');
        $this->assertStringContainsString('account.preset.note', $screen, 'kalimat kesiapan preset per rekening dari server');

        // Sapuan DIBATASI pada berkas paket ini, tak peka huruf besar; komentar
        // yang menyebut frasa terlarang memakai «guillemet» agar tidak menangkap dirinya sendiri.
        $promises = [];
        foreach ([
            public_path('app/js/views/bankrecon.js'),
            base_path('Modules/Finance/Services/BankInboxService.php'),
            base_path('Modules/Finance/Console/Commands/BankInboxCommand.php'),
            base_path('Modules/Finance/Http/Controllers/BankInboxController.php'),
            base_path('Modules/Finance/Support/BankPresets.php'),
            base_path('docs/samples/bank/README.md'),
        ] as $file) {
            $this->assertFileExists($file);
            $code = (string) file_get_contents($file);
            foreach (['otomatis dari bank', 'langsung dari bank', 'terhubung ke bank', 'penjadwal aktif', 'penjadwal berjalan', 'diambil dari bank'] as $needle) {
                if (preg_match('/(?<!belum |tidak |bukan |tanpa |«)'.preg_quote($needle, '/').'(?!»)/iu', $code) === 1) {
                    $promises[] = basename($file).': '.$needle;
                }
            }
        }

        $this->assertSame([], $promises, 'kalimat yang menjanjikan sesuatu yang tidak terjadi');
    }
}
