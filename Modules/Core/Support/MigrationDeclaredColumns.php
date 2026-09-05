<?php

namespace Modules\Core\Support;

/**
 * What the migrations DECLARED a column to be — the (precision, scale) of
 * every ->decimal() and the name of every ->json() — keyed by table.
 *
 * On SQLite the live schema cannot answer this: Laravel's grammar emits a bare
 * `numeric` for a decimal column and `text` for a json one, so
 * Schema::getColumns() reports no scale and no JSON-ness. The migrations can:
 * `->decimal('amount', 18, 2)` is the declaration MySQL will enforce on the
 * first INSERT after the cut-over. Shared by erp:mysql-preflight (the audit
 * before the move) and erp:migration-verify (the proof after it, which needs
 * a scale for SUM(ROUND(col, s)) when neither side is MySQL).
 *
 * Files are read in name order; the last declaration of a column wins, so a
 * change() restating the type is honoured.
 */
final class MigrationDeclaredColumns
{
    /** Laravel's default when ->decimal() is called without (total, places). */
    public const DEFAULT_PRECISION = 8;

    public const DEFAULT_SCALE = 2;

    /**
     * @return array{decimal: array<string, array<string, array{precision:int, scale:int}>>, json: array<string, array<string, true>>}
     */
    public static function scan(): array
    {
        $decimal = [];
        $json = [];

        $files = array_merge(
            glob(base_path('database/migrations/*.php')) ?: [],
            glob(base_path('Modules/*/Database/Migrations/*.php')) ?: [],
        );
        sort($files);

        $pattern = '/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]'
            .'|->(decimal|unsignedDecimal|json|jsonb)\(\s*[\'"]([a-z0-9_]+)[\'"]\s*(?:,\s*(\d+)\s*(?:,\s*(\d+))?)?\s*\)/';

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $table = null;

            if (! preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $m) {
                if ($m[1] !== '') {
                    $table = $m[1];

                    continue;
                }

                if ($table === null) {
                    continue;
                }

                $column = $m[3];

                if (in_array($m[2], ['json', 'jsonb'], true)) {
                    $json[$table][$column] = true;

                    continue;
                }

                $decimal[$table][$column] = [
                    'precision' => isset($m[4]) && $m[4] !== '' ? (int) $m[4] : self::DEFAULT_PRECISION,
                    'scale' => isset($m[5]) && $m[5] !== '' ? (int) $m[5] : self::DEFAULT_SCALE,
                ];
            }
        }

        return ['decimal' => $decimal, 'json' => $json];
    }
}
