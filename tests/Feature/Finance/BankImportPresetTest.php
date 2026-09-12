<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankStatement;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Preset impor per REKENING (P-3c, T3c.1).
 *
 * Preset adalah PILIHAN EKSPLISIT, bukan sniffing: ia disimpan dari pemetaan
 * yang baru saja berhasil dipratinjau, mengingat sel baris judul pada kolom
 * yang dipetakan, dan hanya diterapkan bila permintaan menyebut use_preset.
 * Header yang bergeser ditolak 422 dengan kalimat yang MENYEBUT kolomnya —
 * bukan hasil parse yang keliru-tetapi-seimbang. Preset tidak pernah
 * melonggarkan tie-out/rantai/identitas.
 */
class BankImportPresetTest extends ErpTestCase
{
    use FinanceFixtures;

    private BankAccount $bank;

    private const HEADER = 'Tanggal;Keterangan;Cabang;Debit;Kredit;Saldo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->bank = $this->makeBankAccount('1-1210');
    }

    private function csv(string $header = self::HEADER): string
    {
        return implode("\n", [
            $header,
            '10/03/2026;TRSF E-BANKING CR PT GRAHA;0001;;250.000.000,00;1.250.000.000,00',
            '15/03/2026;BIAYA ADM;0001;50.000.000,00;;1.200.000.000,00',
        ]);
    }

    /** Pemetaan layar: kolom saja (0-based, seperti yang dikirim SPA) + periode/saldo yang diketik operator. */
    private function mapping(array $overrides = []): array
    {
        return array_merge([
            'delimiter' => ';',
            'skip_rows' => 1,
            'date_column' => 0,
            'date_format' => 'dd/mm/yyyy',
            'description_column' => 1,
            'amount_mode' => 'debit_credit',
            'debit_column' => 3,
            'credit_column' => 4,
            'balance_column' => 5,
            'number_format' => 'id',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'opening_balance' => 1_000_000_000,
            'closing_balance' => 1_200_000_000,
        ], $overrides);
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas Bank',
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function savePreset(string $name = 'BCA KlikBCA', array $mappingOverrides = [], ?string $csv = null): array
    {
        $user = $this->userWith(['fin.view', 'fin.create', 'fin.update']);
        $this->actingAs($user, 'sanctum');

        return $this->putJson("/api/finance/bank-accounts/{$this->bank->id}/import-preset", [
            'name' => $name,
            'format' => 'csv',
            'content' => $csv ?? $this->csv(),
            'mapping' => $this->mapping($mappingOverrides),
        ])->assertOk()->json('data');
    }

    // ------------------------------------------------------------- menyimpan

    public function test_a_preset_is_saved_from_a_previewed_mapping_without_period_and_balances_and_remembers_the_header(): void
    {
        $preset = $this->savePreset();

        $this->assertSame('BCA KlikBCA', $preset['name']);
        $this->assertSame('csv', $preset['format']);
        $expected = [
            'delimiter' => ';', 'skip_rows' => 1, 'date_column' => 0, 'date_format' => 'dd/mm/yyyy',
            'description_column' => 1, 'amount_mode' => 'debit_credit', 'debit_column' => 3, 'credit_column' => 4,
            'balance_column' => 5, 'number_format' => 'id',
        ];
        $stored = $preset['mapping'];
        ksort($expected);
        ksort($stored);
        $this->assertSame($expected, $stored);
        $this->assertArrayNotHasKey('period_start', $preset['mapping']);
        $this->assertArrayNotHasKey('opening_balance', $preset['mapping']);
        // Sel baris judul HANYA pada kolom yang dipetakan (Cabang, kolom 3, tidak dipetakan → tidak diingat) —
        // sebagai DAFTAR {index, cell}: peta berkunci angka di-array_values() oleh JsonResource (lihat uji resource di bawah).
        $this->assertSame([
            ['index' => 0, 'cell' => 'Tanggal'], ['index' => 1, 'cell' => 'Keterangan'], ['index' => 3, 'cell' => 'Debit'],
            ['index' => 4, 'cell' => 'Kredit'], ['index' => 5, 'cell' => 'Saldo'],
        ], $preset['expected_header']);
        $this->assertNotNull($preset['saved_at']);
        $this->assertSame(auth()->id(), $preset['saved_by']);

        $stored = BankAccount::query()->findOrFail($this->bank->id);
        $this->assertSame('BCA KlikBCA', $stored->import_preset['name']);

        // Resource memulangkan preset (tidak ada data rahasia di dalamnya).
        $this->getJson("/api/finance/bank-accounts/{$this->bank->id}")
            ->assertOk()
            ->assertJsonPath('data.import_preset.name', 'BCA KlikBCA')
            ->assertJsonPath('data.import_preset.mapping.balance_column', 5);
    }

    /**
     * Ditemukan di Chromium (S39): JsonResource menjalankan array_values() atas array berkunci numerik,
     * jadi expected_header {"0","1","3",…} sampai ke layar sebagai daftar dan kartu preset menulis
     * "Kolom 3 'Debit'" untuk kolom 4. Indeks kolom harus SELAMAT melewati resource DAN daftar rekening.
     */
    public function test_the_header_indexes_survive_the_resource_so_the_screen_can_name_the_right_column(): void
    {
        $this->savePreset();

        $viaShow = $this->getJson("/api/finance/bank-accounts/{$this->bank->id}")->assertOk()->json('data.import_preset.expected_header');
        $viaIndex = collect($this->getJson('/api/finance/bank-accounts?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $this->bank->id)['import_preset']['expected_header'];

        foreach ([$viaShow, $viaIndex] as $header) {
            $this->assertSame(3, $header[2]['index']);
            $this->assertSame('Debit', $header[2]['cell']);
            $this->assertSame([0, 1, 3, 4, 5], array_column($header, 'index'));
        }
    }

    public function test_saving_a_preset_needs_fin_update_not_only_fin_create(): void
    {
        $this->actingAs($this->userWith(['fin.view', 'fin.create']), 'sanctum');

        $this->putJson("/api/finance/bank-accounts/{$this->bank->id}/import-preset", [
            'name' => 'X', 'format' => 'csv', 'content' => $this->csv(), 'mapping' => $this->mapping(),
        ])->assertForbidden();

        $this->deleteJson("/api/finance/bank-accounts/{$this->bank->id}/import-preset")->assertForbidden();
    }

    public function test_mt940_needs_no_preset_and_saving_one_is_refused(): void
    {
        $this->actingAs($this->userWith(['fin.view', 'fin.create', 'fin.update']), 'sanctum');

        $this->putJson("/api/finance/bank-accounts/{$this->bank->id}/import-preset", [
            'name' => 'Mandiri MT940', 'format' => 'mt940', 'content' => ':20:X', 'mapping' => [],
        ])->assertStatus(422);
    }

    /** Pemetaan yang pratinjaunya tidak seimbang tidak layak menjadi preset — ia justru pemetaan yang salah. */
    public function test_a_mapping_whose_preview_does_not_tie_out_cannot_become_a_preset(): void
    {
        $this->actingAs($this->userWith(['fin.view', 'fin.create', 'fin.update']), 'sanctum');

        $response = $this->putJson("/api/finance/bank-accounts/{$this->bank->id}/import-preset", [
            'name' => 'Salah', 'format' => 'csv', 'content' => $this->csv(),
            // debit dan kredit tertukar: saldo berjalan baris pertama tidak cocok.
            'mapping' => $this->mapping(['debit_column' => 4, 'credit_column' => 3]),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('saldo berjalan tidak cocok', (string) $response->json('message'));
        $this->assertNull(BankAccount::query()->findOrFail($this->bank->id)->import_preset);
    }

    public function test_a_preset_without_a_header_row_remembers_no_header_and_says_so(): void
    {
        $preset = $this->savePreset('Tanpa judul', ['skip_rows' => 0], implode("\n", [
            '10/03/2026;TRSF E-BANKING CR PT GRAHA;0001;;250.000.000,00;1.250.000.000,00',
            '15/03/2026;BIAYA ADM;0001;50.000.000,00;;1.200.000.000,00',
        ]));

        $this->assertNull($preset['expected_header']);
        $this->assertSame('Berkas tanpa baris judul: pergeseran kolom tidak bisa dideteksi dari judulnya — periksa pratinjau setiap kali.', $preset['header_note']);
    }

    // ------------------------------------------------------------ menerapkan

    public function test_use_preset_applies_the_saved_mapping_on_preview_and_store(): void
    {
        $this->savePreset();

        $preview = $this->postJson('/api/finance/bank-statements/preview', [
            'bank_account_id' => $this->bank->id,
            'format' => 'csv',
            'content' => $this->csv(),
            'use_preset' => true,
            // Tanpa satu kolom pun — hanya yang operator ketik.
            'mapping' => ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_200_000_000],
        ])->assertOk()->json('data');

        $this->assertTrue($preview['can_import']);
        $this->assertSame(2, $preview['statement']['line_count']);
        $this->assertSame(['used' => true, 'name' => 'BCA KlikBCA'], $preview['preset']);

        $created = $this->postJson('/api/finance/bank-statements', [
            'bank_account_id' => $this->bank->id,
            'format' => 'csv',
            'content' => $this->csv(),
            'use_preset' => true,
            'mapping' => ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_200_000_000],
        ])->assertCreated()->json('data');

        $statement = BankStatement::query()->findOrFail($created['id']);
        $this->assertSame(2, $statement->line_count);
        // Jejak audit: pemetaan yang benar-benar dipakai (dari preset) tersimpan di parse_options.
        $this->assertSame(5, $statement->parse_options['balance_column']);
        $this->assertSame('2026-03-01', $statement->parse_options['period_start']);
    }

    public function test_without_use_preset_the_saved_preset_is_never_applied(): void
    {
        $this->savePreset();

        // Pemetaan dikirim penuh dan berbeda dari preset (kolom Cabang sebagai keterangan): yang dipakai adalah yang dikirim.
        $preview = $this->postJson('/api/finance/bank-statements/preview', [
            'bank_account_id' => $this->bank->id,
            'format' => 'csv',
            'content' => $this->csv(),
            'mapping' => $this->mapping(['description_column' => 2]),
        ])->assertOk()->json('data');

        $this->assertSame(['used' => false, 'name' => null], $preview['preset']);
        $this->assertSame('0001', $preview['statement']['lines'][0]['description']);
    }

    public function test_a_shifted_header_is_refused_naming_the_column_and_the_preset(): void
    {
        $this->savePreset();

        $response = $this->postJson('/api/finance/bank-statements/preview', [
            'bank_account_id' => $this->bank->id,
            'format' => 'csv',
            'content' => $this->csv('Tanggal;Keterangan;Cabang;Mutasi;Kredit;Saldo'),
            'use_preset' => true,
            'mapping' => ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_200_000_000],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            "Kolom 4 pada preset «BCA KlikBCA» diharapkan 'Debit', berkas berisi 'Mutasi'.",
            $response->json('message'),
        );
    }

    public function test_every_shifted_column_is_named_not_only_the_first(): void
    {
        $this->savePreset();

        $response = $this->postJson('/api/finance/bank-statements/preview', [
            'bank_account_id' => $this->bank->id,
            'format' => 'csv',
            'content' => $this->csv('Tgl;Keterangan;Cabang;Debit;Kredit;Saldo Akhir'),
            'use_preset' => true,
            'mapping' => ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_200_000_000],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            "Kolom 1 pada preset «BCA KlikBCA» diharapkan 'Tanggal', berkas berisi 'Tgl'. "
            ."Kolom 6 pada preset «BCA KlikBCA» diharapkan 'Saldo', berkas berisi 'Saldo Akhir'.",
            $response->json('message'),
        );
    }

    /** Header saja, tanpa satu baris mutasi pun — ditolak pada judulnya, sebelum parser sempat berkata "tidak ada baris mutasi". */
    public function test_a_file_whose_header_row_is_short_is_refused_on_the_header_before_parsing(): void
    {
        $this->savePreset();

        $response = $this->postJson('/api/finance/bank-statements/preview', [
            'bank_account_id' => $this->bank->id,
            'format' => 'csv',
            'content' => 'x',
            'use_preset' => true,
            'mapping' => ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 0, 'closing_balance' => 0],
        ]);

        $response->assertStatus(422);
        $this->assertStringStartsWith("Kolom 1 pada preset «BCA KlikBCA» diharapkan 'Tanggal', berkas berisi 'x'. ", (string) $response->json('message'));
        $this->assertStringEndsWith("Kolom 6 pada preset «BCA KlikBCA» diharapkan 'Saldo', berkas berisi ''.", (string) $response->json('message'));
        $this->assertStringNotContainsString('Tidak ada baris mutasi', (string) $response->json('message'));
    }

    public function test_use_preset_on_an_account_without_a_preset_is_refused_with_the_way_out(): void
    {
        $this->actingAs($this->userWith(['fin.view', 'fin.create']), 'sanctum');

        $response = $this->postJson('/api/finance/bank-statements/preview', [
            'bank_account_id' => $this->bank->id,
            'format' => 'csv',
            'content' => $this->csv(),
            'use_preset' => true,
            'mapping' => ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_200_000_000],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            "Rekening {$this->bank->code} belum punya preset impor. Simpan preset dari layar Impor sesudah pratinjau pemetaan Anda berhasil.",
            $response->json('message'),
        );
    }

    /** Preset tidak pernah melonggarkan tie-out: saldo akhir yang diketik salah tetap penghalang. */
    public function test_a_preset_never_loosens_the_tie_out(): void
    {
        $this->savePreset();

        $preview = $this->postJson('/api/finance/bank-statements/preview', [
            'bank_account_id' => $this->bank->id,
            'format' => 'csv',
            'content' => $this->csv(),
            'use_preset' => true,
            'mapping' => ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_300_000_000],
        ])->assertOk()->json('data');

        $this->assertFalse($preview['can_import']);
        $this->assertStringContainsString('tidak seimbang', $preview['blockers'][0]);
    }

    /** …dan tidak melonggarkan identitas: berkas yang sama lewat preset tetap "sudah diimpor". */
    public function test_a_preset_never_loosens_identity(): void
    {
        $this->savePreset();
        $operator = ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_200_000_000];

        $this->postJson('/api/finance/bank-statements', [
            'bank_account_id' => $this->bank->id, 'format' => 'csv', 'content' => $this->csv(), 'use_preset' => true, 'mapping' => $operator,
        ])->assertCreated();

        $second = $this->postJson('/api/finance/bank-statements', [
            'bank_account_id' => $this->bank->id, 'format' => 'csv', 'content' => $this->csv(), 'use_preset' => true, 'mapping' => $operator,
        ]);

        $second->assertStatus(422);
        $this->assertStringContainsString('sudah diimpor sebagai BST/', (string) $second->json('message'));
    }

    /** Dengan preset, kolom tidak wajib — tetapi periode dan saldo tetap diketik operator (Request). */
    public function test_with_use_preset_the_request_still_demands_period_and_balances(): void
    {
        $this->savePreset();

        $this->postJson('/api/finance/bank-statements/preview', [
            'bank_account_id' => $this->bank->id,
            'format' => 'csv',
            'content' => $this->csv(),
            'use_preset' => true,
            'mapping' => ['period_start' => '2026-03-01'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['mapping.period_end', 'mapping.opening_balance', 'mapping.closing_balance'])
            ->assertJsonMissingValidationErrors(['mapping.delimiter', 'mapping.date_column', 'mapping.amount_mode']);
    }

    // -------------------------------------------------------------- menghapus

    public function test_deleting_the_preset_clears_the_column_and_use_preset_is_refused_afterwards(): void
    {
        $this->savePreset();

        $this->deleteJson("/api/finance/bank-accounts/{$this->bank->id}/import-preset")->assertOk();
        $this->assertNull(BankAccount::query()->findOrFail($this->bank->id)->import_preset);

        $this->postJson('/api/finance/bank-statements/preview', [
            'bank_account_id' => $this->bank->id, 'format' => 'csv', 'content' => $this->csv(), 'use_preset' => true,
            'mapping' => ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'opening_balance' => 1_000_000_000, 'closing_balance' => 1_200_000_000],
        ])->assertStatus(422);
    }

    // ---------------------------------------------------------------- registri

    public function test_the_built_in_registry_reaches_the_api_with_its_sentences_and_nothing_selectable(): void
    {
        $this->actingAs($this->userWith(['fin.view']), 'sanctum');

        $data = $this->getJson('/api/finance/bank-statements/presets')->assertOk()->json('data');

        $this->assertSame(['bca', 'mandiri', 'bni', 'bri'], array_column($data['presets'], 'key'));
        $this->assertSame([false, false, false, false], array_column($data['presets'], 'selectable'));
        $this->assertSame('0 dari 4 bank punya berkas ekspor nyata', $data['summary']['label']);
        $this->assertStringStartsWith('BELUM ADA BERKAS EKSPOR NYATA Bank Central Asia', $data['presets'][0]['verification']);
        $this->assertStringContainsString('docs/samples/bank/bca-<kanal>-<YYYY-MM-DD>', $data['presets'][0]['awaiting_file']);
        $this->assertStringContainsString('contoh demo', $data['presets'][0]['demo_note']);

        $this->getJson('/api/finance/bank-statements/presets')->assertOk();
        $this->actingAs($this->userWith(['hr.view']), 'sanctum');
        $this->getJson('/api/finance/bank-statements/presets')->assertForbidden();
    }

    /** Layar Impor membaca preset rekening dan registri dari API — tidak menyusun kalimat verifikasi sendiri. */
    public function test_the_import_screen_reads_presets_from_the_api_and_never_composes_the_registry_sentence(): void
    {
        $screen = (string) file_get_contents(public_path('app/js/views/bankrecon.js'));

        $this->assertStringContainsString('use_preset', $screen, 'bankrecon.js tidak mengirim use_preset');
        $this->assertStringContainsString('import_preset', $screen, 'bankrecon.js tidak membaca import_preset dari resource rekening');
        $this->assertStringContainsString('/import-preset', $screen, 'bankrecon.js tidak memanggil PUT/DELETE import-preset');
        $this->assertStringContainsString('bank-statements/presets', $screen, 'bankrecon.js tidak membaca registri preset bawaan dari API');
        $this->assertStringContainsString('.bank-preset', $screen, 'bankrecon.js tidak menggambar satu blok per entri registri');
        $this->assertStringContainsString('preset.badge_label', $screen, 'bankrecon.js tidak membaca badge_label dari API');
        $this->assertStringContainsString('registry.summary', $screen, 'bankrecon.js tidak membaca summary registri dari API');
        $this->assertDoesNotMatchRegularExpression('/[\'"`][^\'"`\n]*(diverifikasi|ekspor nyata)/iu', $screen,
            'bankrecon.js menyusun sendiri kalimat verifikasi/berkas ekspor nyata — kalimat itu hanya boleh datang dari registri lewat API');
    }
}
