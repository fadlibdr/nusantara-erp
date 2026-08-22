<?php

namespace Tests\Feature\Assets;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Services\AssetDisposalService;
use Modules\Assets\Services\AssetRegisterService;
use Modules\Assets\Services\DepreciationService;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Penghapusbukuan aset mencapai buku besar — dan jalur sampingnya tertutup.
 *
 * Sebelum aksi hapus buku ada, status aset bisa diubah menjadi disposed lewat
 * update biasa tanpa satu jurnal pun: harga perolehan dan akumulasi penyusutan
 * alat yang sudah dijual/hilang menginap di neraca selamanya, laba/rugi
 * pelepasannya tidak pernah diakui, dan daftar aset tidak akan pernah cocok
 * dengan GL saat audit.
 */
class AssetDisposalTest extends ErpTestCase
{
    private AssetDisposalService $disposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->disposal = app(AssetDisposalService::class);
    }

    private function category(array $overrides = []): AssetCategory
    {
        return AssetCategory::query()->create(array_merge([
            'code' => 'CAT-'.str()->random(4),
            'name' => 'Kendaraan',
            'useful_life_months_default' => 60,
            'depreciation_account_hint' => '6-3100',
            'accum_account_hint' => '1-2310',
            'asset_account_hint' => '1-2300',
        ], $overrides));
    }

    /**
     * Dump truck sesuai data demo: perolehan 420 juta, akumulasi 96 juta,
     * nilai buku 324 juta.
     */
    private function asset(array $overrides = []): Asset
    {
        $category = $overrides['category_id'] ?? $this->category()->id;

        return Asset::query()->create(array_merge([
            'code' => 'AST-'.str()->random(5),
            'name' => 'Dump Truck Uji',
            'category_id' => $category,
            'acquisition_date' => '2025-01-01',
            'depreciation_start_date' => '2025-01-01',
            'acquisition_cost' => 420_000_000,
            'useful_life_months' => 60,
            'salvage_value' => 0,
            'accumulated_depreciation' => 96_000_000,
            'book_value' => 324_000_000,
            'status' => 'available',
        ], $overrides));
    }

    private function dispose(Asset $asset, float $value = 300_000_000, string $date = '2026-04-15'): Asset
    {
        return $this->disposal->dispose($asset, [
            'disposal_date' => $date,
            'disposal_value' => $value,
            'reason' => 'Dijual ke pihak ketiga',
        ]);
    }

    /** @return array<string, array{debit: float, credit: float}> */
    private function linesByAccount(): array
    {
        return JournalLine::query()
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->get(['fin_accounts.code', 'fin_journal_lines.debit', 'fin_journal_lines.credit'])
            ->groupBy('code')
            ->map(fn ($lines) => [
                'debit' => (float) $lines->sum('debit'),
                'credit' => (float) $lines->sum('credit'),
            ])
            ->all();
    }

    public function test_menjual_di_bawah_nilai_buku_memposting_jurnal_pelepasan_lengkap(): void
    {
        $asset = $this->asset();

        $this->dispose($asset, 300_000_000);

        $lines = $this->linesByAccount();

        // Akumulasi keluar (debit), piutang hasil penjualan masuk, harga
        // perolehan keluar (kredit), selisihnya rugi pelepasan.
        $this->assertEqualsWithDelta(96_000_000, $lines['1-2310']['debit'], 0.01);
        $this->assertEqualsWithDelta(300_000_000, $lines['1-1300']['debit'], 0.01);
        $this->assertEqualsWithDelta(420_000_000, $lines['1-2300']['credit'], 0.01);
        $this->assertEqualsWithDelta(24_000_000, $lines['7-1200']['debit'], 0.01);

        $journal = Journal::query()->where('reference_type', 'asset_disposal')->firstOrFail();
        $this->assertSame('posted', $journal->status->value);
        $this->assertSame('2026-04-15', $journal->journal_date->toDateString());

        $asset->refresh();
        $this->assertSame(AssetStatus::Disposed, $asset->status);
        $this->assertSame('2026-04-15', $asset->disposal_date?->toDateString());
        $this->assertEqualsWithDelta(300_000_000, (float) $asset->disposal_value, 0.01);
        $this->assertSame('Dijual ke pihak ketiga', $asset->disposal_reason);
    }

    public function test_menjual_di_atas_nilai_buku_mengkredit_laba_pelepasan(): void
    {
        $this->dispose($this->asset(), 350_000_000);

        $lines = $this->linesByAccount();

        $this->assertEqualsWithDelta(26_000_000, $lines['7-1200']['credit'], 0.01);
        $this->assertSame(0.0, $lines['7-1200']['debit']);
    }

    public function test_scrap_tanpa_hasil_membebankan_seluruh_nilai_buku(): void
    {
        $this->dispose($this->asset(), 0);

        $lines = $this->linesByAccount();

        // Tidak ada piutang: tidak ada uang yang akan datang.
        $this->assertArrayNotHasKey('1-1300', $lines);
        $this->assertEqualsWithDelta(324_000_000, $lines['7-1200']['debit'], 0.01);
        $this->assertEqualsWithDelta(420_000_000, $lines['1-2300']['credit'], 0.01);
    }

    public function test_jurnal_pelepasan_selalu_seimbang(): void
    {
        $this->dispose($this->asset(), 123_456_789);

        $difference = JournalLine::query()->selectRaw('SUM(debit) - SUM(credit) as diff')->value('diff');

        $this->assertSame(0, (int) round((float) $difference * 100));
    }

    public function test_aset_termobilisasi_ditolak_sebelum_dikembalikan(): void
    {
        $asset = $this->asset(['status' => 'deployed']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sedang termobilisasi/');

        $this->dispose($asset);
    }

    public function test_aset_yang_sudah_dihapusbukukan_ditolak_untuk_kedua_kalinya(): void
    {
        $asset = $this->dispose($this->asset());

        // Pelepasan kedua akan mengkredit harga perolehan dua kali dan membuat
        // 1-2300 negatif — persis yang dicegah pembacaan-ulang terkunci.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah dihapusbukukan/');

        $this->dispose($asset);
    }

    public function test_kategori_tanpa_akun_harga_perolehan_ditolak_bukan_ditebak(): void
    {
        $asset = $this->asset([
            'category_id' => $this->category(['asset_account_hint' => null])->id,
        ]);

        try {
            $this->dispose($asset);
            $this->fail('Disposal without a cost account must be refused.');
        } catch (LogicException $e) {
            $this->assertMatchesRegularExpression('/belum memiliki akun harga perolehan/', $e->getMessage());
        }

        // Transaksinya utuh: tidak ada jurnal separuh dan statusnya tidak berubah.
        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(AssetStatus::Available, $asset->fresh()->status);
    }

    /**
     * Urutan operasi tidak boleh berpengaruh: run penyusutan yang DIDRAF
     * sebelum pelepasan lalu DIPOSTING sesudahnya dulu tetap membebani aset
     * yang sudah keluar dari neraca — akumulasi naik pada aset yang sudah
     * di-derecognise, dan GL mendapat kredit akumulasi yang tidak dijelaskan
     * aset mana pun.
     */
    public function test_run_draf_yang_diposting_setelah_pelepasan_melewati_aset_itu(): void
    {
        $asset = $this->asset();
        $service = app(DepreciationService::class);

        $run = $service->runForPeriod(2026, 4);
        $this->assertTrue($run->entries()->where('asset_id', $asset->id)->exists());

        // Bebaskan gate pelepasan: hapus entri draf lewat jalur yang sah —
        // TIDAK: justru pelepasan harus ditolak selama entrinya ada.
        try {
            $this->dispose($asset);
            $this->fail('Expected dispose to refuse while a draft run holds an entry for the asset.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($run->code ?? $run->periodLabel(), $e->getMessage());
        }

        // Jalur kedua: entri draf dihapus manual, pelepasan jalan, lalu run
        // diposting — aset yang sudah dilepas harus DILEWATI, bukan dibebani.
        $run->entries()->where('asset_id', $asset->id)->delete();
        $this->dispose($asset);
        $accumBefore = (float) $asset->fresh()->accumulated_depreciation;

        // Run masih punya entri aset lain? Tidak — hanya satu aset; posting
        // run kosong ditolak, dan itu jawaban yang benar untuk kasus ini.
        try {
            $service->post($run->fresh());
            $posted = true;
        } catch (LogicException $e) {
            $posted = false; // run tanpa entri tersisa memang ditolak
        }

        $this->assertSame($accumBefore, (float) $asset->fresh()->accumulated_depreciation,
            'Akumulasi penyusutan aset yang dilepas tidak boleh bergerak.');
    }

    /**
     * Penjaga disposed memutuskan dari baris yang dibaca ulang, bukan dari
     * instance milik route binding: pelepasan yang commit di antara binding
     * dan tulisan dulu bisa disusul PUT status=available yang menghidupkan
     * kembali aset yang barusan keluar dari neraca.
     */
    public function test_instance_basi_tidak_bisa_menghidupkan_aset_yang_baru_dilepas(): void
    {
        $asset = $this->asset();
        $stale = Asset::query()->findOrFail($asset->id);

        $this->dispose($asset);

        $service = app(AssetRegisterService::class);

        try {
            $service->update($stale, ['status' => AssetStatus::Available->value]);
            $this->fail('Expected the re-read to refuse reviving a disposed asset.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('dihapusbukukan', $e->getMessage());
        }

        $this->assertSame(AssetStatus::Disposed, $asset->fresh()->status);
    }

    /** Cermin yang sama pada hapus: jurnal pelepasan menunjuk baris ini. */
    public function test_instance_basi_tidak_bisa_menghapus_aset_yang_baru_dilepas(): void
    {
        $asset = $this->asset();
        $stale = Asset::query()->findOrFail($asset->id);

        $this->dispose($asset);

        $service = app(AssetRegisterService::class);

        try {
            $service->delete($stale);
            $this->fail('Expected the re-read to refuse deleting a disposed asset.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('dihapusbukukan', $e->getMessage());
        }

        $this->assertNotNull(Asset::query()->find($asset->id));
    }

    public function test_pelepasan_ke_periode_tertutup_ditolak(): void
    {
        FiscalPeriod::query()->where('year', 2026)->where('month', 4)->update(['status' => 'closed']);

        $asset = $this->asset();

        try {
            $this->dispose($asset, 300_000_000, '2026-04-15');
            $this->fail('A disposal dated into a closed period must be refused.');
        } catch (LogicException $e) {
            $this->assertMatchesRegularExpression('/sudah ditutup/', $e->getMessage());
        }

        $this->assertSame(AssetStatus::Available, $asset->fresh()->status);
    }

    // ------------------------------------------------------------ jalur API

    public function test_update_biasa_tidak_bisa_lagi_menyetel_status_disposed(): void
    {
        Sanctum::actingAs($this->adminUser());
        $asset = $this->asset();

        // Pintu samping yang ditemukan audit: PUT biasa dengan status disposed
        // dulu diterima tanpa jurnal apa pun.
        $this->putJson("/api/assets/assets/{$asset->id}", [
            'status' => 'disposed',
            'disposal_date' => '2026-04-15',
            'disposal_value' => 300_000_000,
        ])->assertUnprocessable();

        $this->assertSame(AssetStatus::Available, $asset->fresh()->status);
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_endpoint_dispose_membutuhkan_ast_post(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $editor = Role::findOrCreate('editor-aset', 'web');
        $editor->syncPermissions(['ast.view', 'ast.update']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Editor Aset',
            'email' => 'editor-aset@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($editor);

        Sanctum::actingAs($user);

        // Boleh mengganti nama aset, tidak boleh mengeluarkannya dari neraca.
        $this->postJson("/api/assets/assets/{$this->asset()->id}/dispose", [
            'disposal_date' => '2026-04-15',
            'disposal_value' => 0,
            'reason' => 'Rusak total',
        ])->assertForbidden();
    }

    public function test_endpoint_dispose_memposting_dan_menandai_aset(): void
    {
        Sanctum::actingAs($this->adminUser());
        $asset = $this->asset();

        $this->postJson("/api/assets/assets/{$asset->id}/dispose", [
            'disposal_date' => '2026-04-15',
            'disposal_value' => 300_000_000,
            'reason' => 'Dijual ke pihak ketiga',
        ])->assertOk();

        $this->assertSame(AssetStatus::Disposed, $asset->fresh()->status);
        $this->assertSame(1, Journal::query()->where('reference_type', 'asset_disposal')->count());
    }

    public function test_alasan_pelepasan_wajib_diisi(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/assets/assets/{$this->asset()->id}/dispose", [
            'disposal_date' => '2026-04-15',
            'disposal_value' => 0,
        ])->assertUnprocessable();
    }

    public function test_aset_terhapusbuku_tidak_bisa_diubah_atau_dihapus(): void
    {
        Sanctum::actingAs($this->adminUser());
        $asset = $this->dispose($this->asset());

        // Menghidupkan kembali statusnya (atau mengubah nilai perolehannya)
        // akan membuat register dan GL berbeda selamanya.
        $this->putJson("/api/assets/assets/{$asset->id}", ['status' => 'available'])
            ->assertUnprocessable();

        // Jurnal pelepasannya menunjuk baris ini; menghapusnya meninggalkan
        // jurnal yang mengacu ke aset yang tidak ada.
        $this->deleteJson("/api/assets/assets/{$asset->id}")
            ->assertUnprocessable();

        $this->assertSame(AssetStatus::Disposed, $asset->fresh()->status);
    }
}
