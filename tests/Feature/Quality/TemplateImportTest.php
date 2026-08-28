<?php

namespace Tests\Feature\Quality;

use Modules\Core\Services\DocumentImportService;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Models\InspectionTemplate;
use Tests\ErpTestCase;

/**
 * P1-QC — the checklist library imports from one XLSX/CSV through
 * document-import (ImportableDocuments 'inspection-templates'): a template plus
 * its butir, keyed on the operator-owned code (Q1..Q31), so re-uploading the
 * same book UPDATES rather than duplicating.
 */
class TemplateImportTest extends ErpTestCase
{
    private function imports(): DocumentImportService
    {
        return app(DocumentImportService::class);
    }

    /** @return array<int, string> the shipped template's own header line. */
    private function headers(): array
    {
        return str_getcsv((string) strtok($this->imports()->template('inspection-templates'), "\n"), ',', '"', '\\');
    }

    /** @param  array<int, array<string, string>>  $rows */
    private function file(array $rows): string
    {
        $headers = $this->headers();
        $out = implode(',', $headers)."\n";

        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $value = (string) ($row[$header] ?? '');
                $cells[] = str_contains($value, ',') ? '"'.$value.'"' : $value;
            }
            $out .= implode(',', $cells)."\n";
        }

        return base64_encode($out);
    }

    public function test_a_template_and_its_butir_import_as_one_document(): void
    {
        $result = $this->imports()->commit('inspection-templates', 'templates.csv', $this->file([
            ['tipe' => 'template', 'kode' => 'Q7', 'paket' => 'Pengecoran kolom struktur', 'tahap' => 'sebelum'],
            ['tipe' => 'butir', 'kode' => 'Q7', 'butir' => 'Kebersihan bekisting dan tulangan',
                'kriteria' => 'Bebas kotoran dan karat lepas', 'toleransi' => '-'],
            ['tipe' => 'butir', 'kode' => 'Q7', 'butir' => 'Selimut beton',
                'kriteria' => 'Sesuai gambar', 'toleransi' => '± 5 mm'],
            ['tipe' => 'butir', 'kode' => 'Q7', 'butir' => 'Slump beton',
                'kriteria' => '12 cm', 'toleransi' => '± 2 cm'],
        ]));

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['errors']);

        $template = InspectionTemplate::query()->where('code', 'Q7')->with('items')->sole();
        $this->assertSame('Pengecoran kolom struktur', $template->work_package);
        // 'sebelum' resolved to the canonical enum value.
        $this->assertSame(InspectionStage::Before, $template->stage);
        $this->assertSame(3, $template->items->count());
        $this->assertSame('± 5 mm', $template->items[1]->tolerance);
    }

    public function test_re_importing_the_same_code_updates_and_never_duplicates(): void
    {
        $first = $this->file([
            ['tipe' => 'template', 'kode' => 'Q7', 'paket' => 'Pengecoran kolom', 'tahap' => 'sebelum'],
            ['tipe' => 'butir', 'kode' => 'Q7', 'butir' => 'Butir lama', 'kriteria' => 'Kriteria lama'],
        ]);
        $this->imports()->commit('inspection-templates', 'templates.csv', $first);

        $second = $this->file([
            ['tipe' => 'template', 'kode' => 'Q7', 'paket' => 'Pengecoran kolom struktur', 'tahap' => 'saat'],
            ['tipe' => 'butir', 'kode' => 'Q7', 'butir' => 'Butir baru A', 'kriteria' => 'Kriteria A'],
            ['tipe' => 'butir', 'kode' => 'Q7', 'butir' => 'Butir baru B', 'kriteria' => 'Kriteria B'],
        ]);
        $result = $this->imports()->commit('inspection-templates', 'templates.csv', $second);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);

        $this->assertSame(1, InspectionTemplate::query()->where('code', 'Q7')->count());
        $template = InspectionTemplate::query()->where('code', 'Q7')->with('items')->sole();
        $this->assertSame(InspectionStage::During, $template->stage);
        $this->assertSame('Pengecoran kolom struktur', $template->work_package);
        // The items were replaced wholesale, not accumulated.
        $this->assertSame(['Butir baru A', 'Butir baru B'], $template->items->pluck('check_text')->all());
    }

    public function test_one_workbook_carries_many_templates(): void
    {
        $result = $this->imports()->commit('inspection-templates', 'templates.csv', $this->file([
            ['tipe' => 'template', 'kode' => 'Q1', 'paket' => 'Galian tanah', 'tahap' => 'sebelum'],
            ['tipe' => 'butir', 'kode' => 'Q1', 'butir' => 'Elevasi dasar galian', 'kriteria' => 'Sesuai gambar'],
            ['tipe' => 'template', 'kode' => 'Q2', 'paket' => 'Pembesian', 'tahap' => 'saat'],
            ['tipe' => 'butir', 'kode' => 'Q2', 'butir' => 'Jarak sengkang', 'kriteria' => 'Sesuai BBS'],
            ['tipe' => 'butir', 'kode' => 'Q2', 'butir' => 'Panjang penyaluran', 'kriteria' => 'Sesuai gambar'],
        ]));

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, InspectionTemplate::query()->where('code', 'Q1')->first()->items()->count());
        $this->assertSame(2, InspectionTemplate::query()->where('code', 'Q2')->first()->items()->count());
    }
}
