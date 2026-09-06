<?php

namespace Tests\Feature\Core;

use Modules\Core\Support\ReportableResources;
use Modules\Core\Support\ReportDefinition;
use Tests\ErpTestCase;

/**
 * Laporan Bebas tidak boleh menjadi permukaan injeksi SQL (Fase 1 / P1-F).
 *
 * `POST core/reports/run` menerima nama sumber, nama kolom, dimensi, agregat
 * dan saringan dari peramban — persis bentuk permintaan yang biasanya berakhir
 * sebagai string yang disambung ke dalam kueri. Di codebase ini SATU-SATUNYA
 * tempat lain sebuah string klien memilih SQL adalah parameter `sort`
 * `ApiController::listing()`, dan ia dijaga `in_array(..., true)` terhadap
 * daftar literal.
 *
 * Berkas ini menjaga hal yang sama dengan DUA cara yang berbeda, karena
 * masing-masing sendirian bisa bocor:
 *
 *  - **Di tingkat SUMBER.** Runner tidak boleh memuat satu pun jalan keluar
 *    raw selain dua yang memang dipakainya (selectRaw/groupByRaw dengan string
 *    yang dibangun dari registri). Sebuah `whereRaw` yang ditambahkan besok
 *    lolos setiap uji perilaku dan menjatuhkan uji ini.
 *  - **Di tingkat PERILAKU.** Muatan yang mencoba menyelundupkan identifier
 *    ditolak oleh validator dengan menyebut kuncinya, dan tidak pernah sampai
 *    ke pembangun kueri.
 */
class ReportRunnerSafetyTest extends ErpTestCase
{
    /** Jalan keluar raw yang TIDAK boleh ada di runner. */
    private const FORBIDDEN = [
        'whereRaw', 'orWhereRaw', 'havingRaw', 'orderByRaw', 'fromRaw',
        'DB::select', 'DB::statement', 'DB::unprepared',
    ];

    public function test_the_runner_has_no_raw_escape_hatches_beyond_the_two_it_needs(): void
    {
        $source = $this->runnerSource();

        foreach (self::FORBIDDEN as $needle) {
            $this->assertStringNotContainsString($needle, $source, sprintf(
                'ReportRunner memuat %s. Setiap identifier di kueri ini harus datang dari ReportableResources; '
                .'sebuah jalan keluar raw baru adalah tempat pertama di mana string klien bisa menjadi teks SQL.',
                $needle,
            ));
        }

        // …dan dua yang memang dipakai TETAP ada, supaya uji ini tidak lulus
        // atas berkas yang sudah dipindahkan atau dikosongkan.
        $this->assertStringContainsString('selectRaw', $source);
        $this->assertStringContainsString('groupByRaw', $source);
    }

    /**
     * Setiap literal SQL di runner dibangun dari identifier registri + konstanta.
     *
     * Diperiksa dengan cara yang mekanis: satu-satunya interpolasi ke dalam
     * string SQL adalah `$table`, `$column['select']`, `$entry['date_column']`
     * dan `$filter['column']` — semuanya melewati guardIdentifier(), yang
     * menolak apa pun di luar [a-z_][a-z0-9_]*.
     */
    public function test_every_identifier_passes_the_guard(): void
    {
        $source = $this->runnerSource();

        $this->assertStringContainsString('guardIdentifier', $source);
        $this->assertSame(
            substr_count($source, "\$table.'.'."),
            substr_count($source, "\$table.'.'.self::guardIdentifier("),
            'Ada identifier yang disambung ke SQL tanpa melewati guardIdentifier().',
        );
    }

    /** Muatan yang mencoba menyelundupkan identifier ditolak, bukan dijalankan. */
    public function test_smuggled_identifiers_are_refused_by_name(): void
    {
        $attempts = [
            ['label' => 'kolom karangan', 'definition' => [
                'resource' => 'finance/project-costs', 'mode' => 'group',
                'row' => ['column' => 'amount) from fin_project_costs; drop table users; --'],
                'measure' => ['agg' => 'count'],
            ]],
            ['label' => 'sumber karangan', 'definition' => [
                'resource' => 'users', 'mode' => 'group',
                'row' => ['column' => 'id'], 'measure' => ['agg' => 'count'],
            ]],
            ['label' => 'agregat karangan', 'definition' => [
                'resource' => 'finance/project-costs', 'mode' => 'group',
                'row' => ['column' => 'cost_category'],
                'measure' => ['agg' => 'sum(1),(select password from users limit 1)'],
            ]],
            ['label' => 'saringan karangan', 'definition' => [
                'resource' => 'finance/project-costs', 'mode' => 'group',
                'row' => ['column' => 'cost_category'], 'measure' => ['agg' => 'count'],
                'filters' => ['eq' => ['1=1' => 'x']],
            ]],
            ['label' => 'ember karangan', 'definition' => [
                'resource' => 'finance/project-costs', 'mode' => 'group',
                'row' => ['column' => 'cost_date', 'bucket' => "7) ,(select 1"],
                'measure' => ['agg' => 'count'],
            ]],
        ];

        foreach ($attempts as $attempt) {
            try {
                ReportDefinition::validate($attempt['definition']);
                $this->fail("Muatan [{$attempt['label']}] seharusnya ditolak validator.");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    /**
     * Bagian yang MENERIMA: validator bukan sekadar menolak segalanya. Setiap
     * sumber katalog bisa menghasilkan sedikitnya satu definisi yang sah.
     */
    public function test_the_validator_still_accepts_a_real_report_for_every_resource(): void
    {
        foreach (ReportableResources::entries() as $key => $entry) {
            $dimension = null;

            foreach ($entry['columns'] as $columnKey => $column) {
                if (($column['dimension'] ?? false) !== false) {
                    $dimension = $columnKey;
                    break;
                }
            }

            $this->assertNotNull($dimension, "Sumber [{$key}] tidak punya satu pun dimensi.");

            $definition = ReportDefinition::validate([
                'resource' => $key, 'mode' => 'group',
                'row' => ['column' => $dimension] + (($entry['columns'][$dimension]['dimension'] === 'date') ? ['bucket' => 'month'] : []),
                'measure' => ['agg' => 'count'],
            ]);

            $this->assertSame($key, $definition['resource']);
        }
    }

    /**
     * KODE saja: docblock runner MENYEBUT `orderByRaw` dengan namanya untuk
     * menjelaskan kenapa pengurutan dilakukan di PHP, dan uji yang tidak bisa
     * membedakan prosa dari kode akan merah justru pada komentar yang
     * menjelaskannya. token_get_all() membuang komentar tanpa menebak-nebak.
     */
    private function runnerSource(): string
    {
        $source = (string) file_get_contents(base_path('Modules/Core/Services/ReportRunner.php'));
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
