<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\DocumentImportService;
use Modules\Projects\Models\ProgressMeasurement;
use Tests\ErpTestCase;

/**
 * P8 kriteria #10 / D12 — pemetaan Progress Payment warisan: volume per item
 * BOQ periode berjalan mendarat sebagai opname progres ke pemilik (OPN) DRAFT
 * lewat MeasurementService, sehingga plafon volume, qty_prev, dan larangan
 * item ganda milik service tetap berlaku; belum ada tagihan atau jurnal yang
 * lahir sampai opname disetujui manusia (forward-only).
 *
 * Fixture: tests/fixtures/import-warisan/progress-pay.xlsx — pemetaan kolom
 * di docs/IMPOR-WARISAN.md §4.
 */
class ProgressPayImportTest extends ErpTestCase
{
    use OpnameFixtures;

    private function imports(): DocumentImportService
    {
        return app(DocumentImportService::class);
    }

    private function fixture(): string
    {
        return base64_encode((string) file_get_contents(
            base_path('tests/fixtures/import-warisan/progress-pay.xlsx'),
        ));
    }

    public function test_the_legacy_progress_pay_sheet_lands_as_a_draft_owner_opname(): void
    {
        $this->seedOpnameWorld();
        // Kode BOQ dipatok supaya fixture yang dikomit tidak bergantung pada
        // bulan berjalannya mask {RM}; sequence tetap yang mencetak kode asli.
        $this->boq->forceFill(['code' => 'RAB-GRAHA-K'])->saveQuietly();

        $journals = DB::table('fin_journals')->count();

        $result = $this->imports()->commit('progress-pay', 'progress-pay.xlsx', $this->fixture());

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['created']);

        $opname = ProgressMeasurement::query()->with('items')->sole();
        $this->assertSame(DocumentStatus::Draft, $opname->status);
        $this->assertStringStartsWith('OPN/', $opname->code);
        $this->assertSame('2026-06-01', $opname->period_start->toDateString());
        $this->assertSame('2026-06-30', $opname->period_end->toDateString());
        $this->assertSame('progress-pay.xlsx', $opname->import_source);

        $this->assertCount(2, $opname->items);
        $this->assertSame((int) $this->boqItems['A.1']->id, (int) $opname->items[0]->boq_item_id);
        $this->assertSame(250.0, (float) $opname->items[0]->qty_this);
        // qty_prev diisi service dari riwayat approved (kosong = 0), bukan berkas.
        $this->assertSame(0.0, (float) $opname->items[0]->qty_prev);
        $this->assertSame(100.0, (float) $opname->items[1]->qty_this);

        // Draft: tidak ada jurnal, tidak ada tagihan.
        $this->assertSame($journals, DB::table('fin_journals')->count());
        $this->assertSame(0, DB::table('fin_ar_invoices')->count());
    }

    public function test_an_item_outside_the_named_boq_is_refused_before_anything_lands(): void
    {
        $this->seedOpnameWorld();
        $this->boq->forceFill(['code' => 'RAB-GRAHA-K'])->saveQuietly();

        // Berkas yang menunjuk item Z.9 yang tidak ada di BOQ itu: lookup
        // menolak dengan nama, dokumen tidak mendarat.
        $headers = ['tipe', 'dokumen', 'proyek_kode', 'boq_kode', 'periode_mulai', 'periode_selesai', 'catatan', 'item_boq', 'volume_ini', 'keterangan'];
        $rows = [
            ['opname', 'OPN-X', 'PRJ-2026-001', 'RAB-GRAHA-K', '01/06/2026', '30/06/2026', '', '', '', ''],
            ['item', 'OPN-X', '', '', '', '', '', 'Z.9', '10', ''],
        ];
        $csv = implode(',', $headers)."\n".implode("\n", array_map(fn ($row) => implode(',', $row), $rows))."\n";

        $result = $this->imports()->commit('progress-pay', 'opname.csv', base64_encode($csv));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('"Z.9" tidak ditemukan', implode(' ', array_merge(
            ...array_map(fn (array $row) => $row['errors'], $result['documents'][0]['rows']),
        )));
        $this->assertSame(0, ProgressMeasurement::query()->count());
    }
}
