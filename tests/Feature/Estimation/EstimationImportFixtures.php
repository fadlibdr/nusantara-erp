<?php

namespace Tests\Feature\Estimation;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\DocumentImportService;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Services\AhspService;

/**
 * Upload files in the shape the SHIPPED definitions accept, and the records they
 * point at.
 *
 * Every file built here takes its column list from the shipped template's own
 * first line rather than from a list typed into the test. A test that hardcoded
 * the headings would go on passing after a column was renamed in the registry,
 * while the template an operator downloads had stopped matching what the
 * importer reads — and the symptom of that is an import that lands nothing and
 * explains nothing. Here it fails immediately, by name.
 */
trait EstimationImportFixtures
{
    protected function imports(): DocumentImportService
    {
        return app(DocumentImportService::class);
    }

    /**
     * A CSV for $resource whose rows are keyed by column heading.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    protected function file(string $resource, array $rows): string
    {
        return base64_encode($this->csv($resource, $rows));
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    protected function csv(string $resource, array $rows): string
    {
        $headers = $this->templateHeaders($resource);
        $out = implode(',', $headers)."\n";

        foreach ($rows as $row) {
            $unknown = array_diff(array_keys($row), $headers);

            if ($unknown !== []) {
                $this->fail("kolom tidak ada di template {$resource}: ".implode(', ', $unknown));
            }

            $cells = [];

            foreach ($headers as $header) {
                $value = (string) ($row[$header] ?? '');
                // A koefisien is written 1,05 and a subtotal label may carry a
                // comma of its own, so quote exactly as Excel would.
                $cells[] = str_contains($value, ',') ? '"'.$value.'"' : $value;
            }

            $out .= implode(',', $cells)."\n";
        }

        return $out;
    }

    /** @return array<int, string> */
    protected function templateHeaders(string $resource): array
    {
        return str_getcsv((string) strtok($this->imports()->template($resource), "\n"), ',', '"', '\\');
    }

    /**
     * The shipped template with its worked example rows switched on — the '#'
     * that comments a row out sits directly against the tipe word, while every
     * note line is '# ' with a space, so only the examples are uncommented.
     */
    protected function templateWithExampleEnabled(string $resource): string
    {
        return base64_encode(
            (string) preg_replace('/^#(?=\S)/m', '', $this->imports()->template($resource)),
        );
    }

    /**
     * A.4.3.1.3 exactly as the AHSP template's own worked example prints it:
     * 1,02 x 1.150.000 + 0,25 x 145.000 + 0,5 x 45.000 = 1.231.750, plus 10%
     * overhead = Rp 1.354.925 per m3.
     */
    protected function readyMixAnalysis(): Ahsp
    {
        return app(AhspService::class)->create([
            'code' => 'A.4.3.1.3',
            'name' => 'Membuat 1 m3 beton ready mix K-300',
            'unit' => 'm3',
            'category' => 'sipil',
            'overhead_pct' => 10,
            'components' => [
                ['component_type' => 'material', 'name' => 'Ready Mix K-300', 'unit' => 'm3', 'coefficient' => 1.02, 'unit_price' => 1_150_000],
                ['component_type' => 'labor', 'name' => 'Tukang cor', 'unit' => 'OH', 'coefficient' => 0.25, 'unit_price' => 145_000],
                ['component_type' => 'equipment', 'name' => 'Vibrator beton', 'unit' => 'jam', 'coefficient' => 0.5, 'unit_price' => 45_000],
            ],
        ]);
    }

    protected function project(string $code = 'PRJ-2026-001'): int
    {
        return (int) DB::table('prj_projects')->insertGetId([
            'code' => $code,
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A stocked item, for the AHSP components that name one. */
    protected function stockItem(string $code = 'ITM-0007', string $name = 'Ready Mix K-300'): int
    {
        $categoryId = DB::table('inv_item_categories')->where('code', 'MAT')->value('id')
            ?? DB::table('inv_item_categories')->insertGetId([
                'code' => 'MAT', 'name' => 'Material Konstruksi',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        return (int) DB::table('inv_items')->insertGetId([
            'code' => $code,
            'name' => $name,
            'category_id' => $categoryId,
            'unit' => 'm3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
