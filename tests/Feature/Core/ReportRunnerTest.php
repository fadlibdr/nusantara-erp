<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Services\ReportRunner;
use Modules\Core\Support\ReportableResources;
use Modules\Core\Support\ReportDefinition;
use Tests\ErpTestCase;

/**
 * Mesin Laporan Bebas: SATU kueri, angka yang sama di kedua driver, dan sel
 * yang jujur (Fase 1 / P1-F).
 *
 * Tiga sifat diuji di sini karena tiga-tiganya bisa salah TANPA satu galat pun:
 *
 *  - **ONLY_FULL_GROUP_BY.** Menyala di setiap sesi MySQL, tidak di SQLite.
 *    Sebuah ekspresi select bukan-agregat yang tidak masuk GROUP BY membuat
 *    SELURUH suite SQLite hijau dan produksi MySQL 1055. Karena tidak ada MySQL
 *    di host ini, yang dipaku adalah SQL-nya sendiri: `compile()` diekspos,
 *    dan uji menyapu setiap sumber × setiap dimensi × setiap ember × setiap
 *    agregat, lalu menuntut setiap ekspresi bukan-agregat muncul BYTE-IDENTIK
 *    di klausa GROUP BY.
 *  - **Satu kueri.** Diumumkan `meta.queries`, dan sebuah klaim yang tidak
 *    diperiksa bukan klaim — DB::listen yang menghitungnya di sini.
 *  - **Sel kosong bukan 0.** Tiga keadaan berbeda (tidak ada baris / ada baris
 *    dengan agregat NULL / nol sungguhan) yang runtuh menjadi satu bila
 *    siapa pun menulis `?? 0`.
 */
class ReportRunnerTest extends ErpTestCase
{
    private ?ReportRunner $runner = null;

    /** SATU instans per uji: queriesRun() adalah keadaan instans, bukan global. */
    private function runner(): ReportRunner
    {
        return $this->runner ??= app(ReportRunner::class);
    }

    /**
     * Setiap ekspresi select yang bukan agregat ada di GROUP BY — untuk SETIAP
     * kombinasi yang katalog izinkan.
     *
     * Sapuan ini juga penjaga anti-no-op: bila registri menyusut atau regex
     * berhenti cocok, jumlah kombinasi jatuh dan assertGreaterThan menyala.
     */
    public function test_every_non_aggregate_select_appears_in_group_by(): void
    {
        $combinations = 0;

        foreach (ReportableResources::entries() as $key => $entry) {
            $dimensions = array_keys(array_filter($entry['columns'], static fn (array $c): bool => ($c['dimension'] ?? false) !== false));
            $measures = array_keys(array_filter($entry['columns'], static fn (array $c): bool => ($c['measure'] ?? false) === true));

            $this->assertNotSame([], $dimensions, "Sumber [{$key}] tidak punya satu pun dimensi — tidak ada laporan yang bisa dibuat darinya.");

            foreach ($dimensions as $row) {
                $buckets = $entry['columns'][$row]['buckets'] ?? [null];

                foreach ($buckets as $bucket) {
                    foreach (array_merge([null], $measures) as $measureColumn) {
                        $measure = $measureColumn === null ? ['agg' => 'count'] : ['agg' => 'sum', 'column' => $measureColumn];

                        $definition = ReportDefinition::validate(array_filter([
                            'resource' => $key,
                            'mode' => 'group',
                            'row' => array_filter(['column' => $row, 'bucket' => $bucket]),
                            'measure' => $measure,
                        ]));

                        $compiled = $this->runner()->compile($definition);
                        $combinations++;

                        $groupBy = $this->groupByClause($compiled['sql']);

                        foreach ($compiled['selects'] as $select) {
                            if ($select['aggregate']) {
                                continue;
                            }

                            $this->assertStringContainsString(
                                $this->quoted($select['expr']),
                                $groupBy,
                                sprintf(
                                    'Ekspresi select [%s] pada laporan %s (dimensi %s%s) tidak ada di GROUP BY. '
                                    .'MySQL menjalankan ONLY_FULL_GROUP_BY dan akan menjawab 1055; SQLite tidak, '
                                    .'jadi seluruh suite ini hijau sementara produksi merah.',
                                    $select['expr'], $key, $row, $bucket === null ? '' : " ember {$bucket}",
                                ),
                            );
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(60, $combinations, 'Kombinasi yang disapu terlalu sedikit — sapuan ini kehilangan sasarannya.');
    }

    /** Mode pivot menambah dimensi kedua, dan ia juga harus masuk GROUP BY. */
    public function test_the_pivot_column_dimension_is_also_grouped(): void
    {
        $definition = ReportDefinition::validate([
            'resource' => 'finance/project-costs',
            'mode' => 'pivot',
            'row' => ['column' => 'cost_category'],
            'column' => ['column' => 'cost_date', 'bucket' => 'month'],
            'measure' => ['agg' => 'sum', 'column' => 'amount'],
        ]);

        $compiled = $this->runner()->compile($definition);
        $groupBy = $this->groupByClause($compiled['sql']);

        $this->assertStringContainsString('cost_category', $groupBy);
        $this->assertStringContainsString('substr(', $groupBy);
    }

    /**
     * Ember tanggal memakai `substr`, bukan idiom yang hanya ada di satu driver.
     *
     * `strftime(` adalah SQLite saja DAN dipindai terlarang oleh
     * MysqlPreflightCommand; `MONTH(`/`DATE_FORMAT(` adalah MySQL saja dan
     * membuat seluruh suite SQLite merah. Ember HARIAN pun memotong, karena
     * kolom `date` bisa terbaca '2026-03-25 00:00:00' di SQLite dan
     * '2026-03-25' di MySQL — mengelompokkan nilai mentahnya memberi kunci
     * kelompok yang berbeda per driver.
     */
    public function test_date_buckets_use_the_one_portable_idiom(): void
    {
        $sqlFor = function (string $bucket): string {
            return $this->runner()->compile(ReportDefinition::validate([
                'resource' => 'finance/project-costs',
                'mode' => 'group',
                'row' => ['column' => 'cost_date', 'bucket' => $bucket],
                'measure' => ['agg' => 'count'],
            ]))['sql'];
        };

        $this->assertStringContainsString('substr(', $sqlFor('day'));
        $this->assertStringContainsString(', 1, 10)', $sqlFor('day'));
        $this->assertStringContainsString(', 1, 7)', $sqlFor('month'));
        $this->assertStringContainsString(', 1, 4)', $sqlFor('year'));

        foreach (['day', 'month', 'year'] as $bucket) {
            $sql = $sqlFor($bucket);
            $this->assertStringNotContainsString('strftime', $sql);
            $this->assertStringNotContainsString('DATE_FORMAT', $sql);
            $this->assertStringNotContainsString('MONTH(', $sql);
            $this->assertStringNotContainsString('YEAR(', $sql);
        }
    }

    /** Tabel ber-deleted_at disaring dengan tangan; yang tanpa kolom itu tidak. */
    public function test_soft_deleted_rows_are_excluded_exactly_where_the_column_exists(): void
    {
        $withColumn = $this->runner()->compile(ReportDefinition::validate([
            'resource' => 'projects', 'mode' => 'group',
            'row' => ['column' => 'status'], 'measure' => ['agg' => 'count'],
        ]))['sql'];

        $this->assertStringContainsString('deleted_at', $withColumn,
            'DB::table melewati scope SoftDeletes; tanpa whereNull manual laporan menghitung dokumen yang sudah dibuang.');

        $withoutColumn = $this->runner()->compile(ReportDefinition::validate([
            'resource' => 'finance/project-costs', 'mode' => 'group',
            'row' => ['column' => 'cost_category'], 'measure' => ['agg' => 'count'],
        ]))['sql'];

        $this->assertStringNotContainsString('deleted_at', $withoutColumn,
            'fin_project_costs tidak punya deleted_at; whereNull yang dipaksakan di sana adalah 500.');
    }

    /** Nilai saringan adalah BINDING, tidak pernah teks SQL. */
    public function test_filter_values_travel_as_bindings(): void
    {
        $compiled = $this->runner()->compile(ReportDefinition::validate([
            'resource' => 'finance/project-costs',
            'mode' => 'group',
            'row' => ['column' => 'cost_category'],
            'measure' => ['agg' => 'sum', 'column' => 'amount'],
            'filters' => [
                'date_from' => '2026-01-01',
                'eq' => ['cost_category' => "material'; drop table fin_project_costs; --"],
            ],
        ]));

        $this->assertStringNotContainsString('drop table', strtolower($compiled['sql']));
        $this->assertContains("material'; drop table fin_project_costs; --", $compiled['bindings']);
    }

    /** SATU kueri, diukur — bukan diklaim. */
    public function test_a_report_run_costs_exactly_one_query(): void
    {
        $this->seedCosts();

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void { $queries[] = $query->sql; });

        $this->runner()->run(ReportDefinition::validate([
            'resource' => 'finance/project-costs',
            'mode' => 'pivot',
            'row' => ['column' => 'cost_category'],
            'column' => ['column' => 'cost_date', 'bucket' => 'month'],
            'measure' => ['agg' => 'sum', 'column' => 'amount'],
        ]));

        $this->assertCount(1, $queries, 'Jalankan laporan harus satu kueri: '.implode(' | ', $queries));
        $this->assertSame(1, $this->runner()->queriesRun());
    }

    /**
     * TIGA keadaan sel, dan ketiganya berbeda.
     *
     * Ini syarat paket ini ("sel kosong, bukan 0") diuji pada bentuknya yang
     * paling tajam, dan pada data yang benar-benar ada bentuknya: nilai buku
     * alat SEWA adalah NULL — alat itu tidak ada di neraca kita, dan layarnya
     * menuliskannya '—'. Maka satu kelompok tanpa baris sama sekali, satu
     * kelompok yang barisnya ada tetapi seluruh nilainya NULL, dan satu nol
     * yang benar-benar dijumlahkan harus terbaca sebagai tiga hal berbeda.
     */
    public function test_an_empty_cell_a_null_aggregate_and_a_real_zero_are_three_different_things(): void
    {
        $this->seedAssets();

        $out = $this->runner()->run(ReportDefinition::validate([
            'resource' => 'assets/assets',
            'mode' => 'pivot',
            'row' => ['column' => 'ownership'],
            'column' => ['column' => 'status'],
            'measure' => ['agg' => 'sum', 'column' => 'book_value'],
        ]));

        $byKey = [];
        foreach ($out['rows'] as $row) {
            $byKey[$row['key']] = $row;
        }

        $statuses = $out['column_keys'];
        $active = array_search('active', $statuses, true);
        $maintenance = array_search('maintenance', $statuses, true);

        // owned × active: nol SUNGGUHAN (satu aset ber-nilai buku 0).
        $this->assertSame(0.0, $byKey['owned']['cells'][$active], 'Nol yang benar-benar dijumlahkan tetap 0.');
        $this->assertSame(1, $byKey['owned']['counts'][$active]);

        // rented × active: barisnya ADA, nilai bukunya NULL — alat sewa tidak
        // ada di neraca kita, dan 0 di sini menaruhnya di sana.
        $this->assertNull($byKey['rented']['cells'][$active], 'Agregat NULL bukan nol.');
        $this->assertGreaterThan(0, $byKey['rented']['counts'][$active],
            'Sel ber-agregat NULL harus tetap mengaku punya baris sumber — itulah yang membedakannya dari sel kosong.');

        // rented × maintenance: TIDAK ADA barisnya sama sekali.
        $this->assertNull($byKey['rented']['cells'][$maintenance], 'Pasangan tanpa baris sumber harus kosong, bukan 0.');
        $this->assertSame(0, $byKey['rented']['counts'][$maintenance], 'Sel kosong harus mengaku nol baris sumber.');

        // …dan totalnya pun tidak mengarang: baris yang seluruh selnya kosong
        // bertotal null, bukan 0.
        $this->assertNull($byKey['rented']['total']);
    }

    /** Plafon adalah PENOLAKAN, bukan pemotongan. */
    public function test_too_many_groups_is_refused_rather_than_truncated(): void
    {
        // 201 proyek: satu kelompok per kode proyek melewati plafon 200.
        for ($i = 1; $i <= ReportRunner::MAX_GROUPS + 1; $i++) {
            DB::table('fin_project_costs')->insert([
                'project_id' => $i, 'cost_date' => '2026-01-15', 'cost_category' => 'material',
                'reference_type' => 'manual', 'description' => 'baris '.$i, 'amount' => 1000,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/lebih dari 200 kelompok/');

        $this->runner()->run(ReportDefinition::validate([
            'resource' => 'finance/project-costs',
            'mode' => 'group',
            'row' => ['column' => 'project_id'],
            'measure' => ['agg' => 'sum', 'column' => 'amount'],
        ]));
    }

    /** Total baris hanya berarti untuk sum dan count. */
    public function test_totals_are_refused_for_aggregates_that_cannot_be_added(): void
    {
        $this->seedCosts();

        foreach (['avg', 'min', 'max'] as $agg) {
            $out = $this->runner()->run(ReportDefinition::validate([
                'resource' => 'finance/project-costs', 'mode' => 'group',
                'row' => ['column' => 'cost_category'], 'measure' => ['agg' => $agg, 'column' => 'amount'],
            ]));

            foreach ($out['rows'] as $row) {
                $this->assertNull($row['total'], "Total baris untuk agregat {$agg} adalah kebohongan aritmetika.");
            }
        }

        $sum = $this->runner()->run(ReportDefinition::validate([
            'resource' => 'finance/project-costs', 'mode' => 'group',
            'row' => ['column' => 'cost_category'], 'measure' => ['agg' => 'sum', 'column' => 'amount'],
        ]));
        $this->assertNotNull($sum['rows'][0]['total']);
    }

    /** Definisi yang menyebut kolom yang DITOLAK katalog dijawab dengan alasannya. */
    public function test_a_refused_column_is_refused_with_its_own_reason(): void
    {
        try {
            ReportDefinition::validate([
                'resource' => 'finance/ar-invoices', 'mode' => 'group',
                'row' => ['column' => 'status'], 'measure' => ['agg' => 'sum', 'column' => 'outstanding'],
            ]);
            $this->fail('Kolom outstanding seharusnya ditolak.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('outstanding', $e->getMessage());
            $this->assertStringContainsString('dibatalkan', $e->getMessage(),
                'Penolakan harus menyebut ALASANNYA (invoice dibatalkan sisanya nol), bukan "tidak dikenal".');
        }
    }

    private function groupByClause(string $sql): string
    {
        $at = stripos($sql, 'group by');

        return $at === false ? '' : substr($sql, $at);
    }

    /**
     * Ekspresi seperti yang benar-benar muncul di SQL.
     *
     * Kolom polos lewat groupBy() dan grammar mengutipnya; ember tanggal lewat
     * groupByRaw() dan bertahan apa adanya — DAN ITU YANG PENTING: selectRaw
     * menuliskan string yang sama persis, sehingga keduanya byte-identik dan
     * ONLY_FULL_GROUP_BY terpenuhi.
     */
    private function quoted(string $expression): string
    {
        if (str_contains($expression, '(')) {
            return $expression;
        }

        [$table, $column] = explode('.', $expression);

        return '"'.$table.'"."'.$column.'"';
    }

    /**
     * Empat aset yang membuat ketiga keadaan sel bisa dibedakan: satu owned
     * bernilai buku NOL, satu rented bernilai buku NULL, satu owned dalam
     * perawatan — dan TIDAK ADA rented dalam perawatan, yang membuat pasangan
     * itu benar-benar kosong.
     */
    private function seedAssets(): void
    {
        // ast_assets.category_id ber-FK; satu kategori sudah cukup untuk ketiganya.
        DB::table('ast_categories')->insert([
            'id' => 1, 'code' => 'KAT-UJI', 'name' => 'Kategori uji',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = [
            ['code' => 'AST-0001', 'ownership' => 'owned', 'status' => 'active', 'book_value' => 0],
            ['code' => 'AST-0002', 'ownership' => 'rented', 'status' => 'active', 'book_value' => null],
            ['code' => 'AST-0003', 'ownership' => 'owned', 'status' => 'maintenance', 'book_value' => 1500000],
        ];

        foreach ($rows as $row) {
            DB::table('ast_assets')->insert($row + [
                'name' => 'Aset uji '.$row['code'], 'category_id' => 1, 'useful_life_months' => 60,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function seedCosts(): void
    {
        $rows = [
            ['cost_category' => 'material', 'cost_date' => '2026-01-10', 'amount' => 5000000],
            ['cost_category' => 'material', 'cost_date' => '2026-01-20', 'amount' => 2500000],
            // nol SUNGGUHAN. (amount NOT NULL di tabel ini — keadaan "agregat
            // NULL" diuji pada ast_assets.book_value, yang memang nullable.)
            ['cost_category' => 'overhead', 'cost_date' => '2026-02-05', 'amount' => 0],
        ];

        foreach ($rows as $row) {
            DB::table('fin_project_costs')->insert($row + [
                'project_id' => 1, 'reference_type' => 'manual', 'description' => 'uji',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
