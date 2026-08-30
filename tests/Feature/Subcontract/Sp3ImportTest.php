<?php

namespace Tests\Feature\Subcontract;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\DocumentImportService;
use Modules\Projects\Models\Project;
use Modules\Subcontract\Models\LaborContract;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\LaborFixtures;

/**
 * P8 kriteria #10 / D12 — lembar Opname/SP3 warisan: yang mendarat adalah SP3
 * Induk (scm_labor_contracts) berstatus DRAFT lewat LaborContractService —
 * nilai dihitung ulang dari baris, tarif PPh final UMKM di-snapshot service,
 * gate kualifikasi mandor tetap ditagih (override beralasan tercatat). Kolom
 * opname kumulatif lembar warisan SENGAJA tidak diimpor: opname baru disusun
 * di aplikasi atas SP3 yang sudah disetujui (forward-only).
 *
 * Fixture: tests/fixtures/import-warisan/sp3.xlsx — pemetaan kolom di
 * docs/IMPOR-WARISAN.md §3.
 */
class Sp3ImportTest extends ErpTestCase
{
    use LaborFixtures;

    private function imports(): DocumentImportService
    {
        return app(DocumentImportService::class);
    }

    private function fixture(): string
    {
        return base64_encode((string) file_get_contents(
            base_path('tests/fixtures/import-warisan/sp3.xlsx'),
        ));
    }

    public function test_the_legacy_sheet_lands_as_a_draft_sp3_whose_value_the_service_recomputes(): void
    {
        $this->makeMandor(['code' => 'VND-M01', 'name' => 'Mandor Pak Budi']);
        Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
        ]);

        $journals = DB::table('fin_journals')->count();

        $result = $this->imports()->commit('sp3', 'sp3.xlsx', $this->fixture());

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['created']);

        $contract = LaborContract::query()->with('items')->sole();
        $this->assertSame(DocumentStatus::Draft, $contract->status);
        $this->assertStringStartsWith('SP3/', $contract->code);
        $this->assertSame('Upah borongan pembesian tower A', $contract->title);
        $this->assertSame('final_umkm', $contract->pph_scheme->value);
        // Snapshot tarif PP 55/2022 milik service, bukan berkas.
        $this->assertSame(0.5, (float) $contract->pph_rate);
        $this->assertSame('sp3.xlsx', $contract->import_source);

        $this->assertCount(2, $contract->items);
        $this->assertSame('Pembesian kolom', $contract->items[0]->description);
        $this->assertSame(120.0, (float) $contract->items[0]->qty);
        $this->assertSame(1500.0, (float) $contract->items[0]->unit_rate);
        // 120 x 1.500 + 200 x 45.000 = 180.000 + 9.000.000
        $this->assertSame(9_180_000.0, (float) $contract->value);

        // Draft SP3 tidak menyentuh pembukuan.
        $this->assertSame($journals, DB::table('fin_journals')->count());
    }
}
