<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\NumberSequence;
use Modules\Core\Support\Erp;

class DocumentNumberService
{
    private const ROMAN_MONTHS = [
        1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII',
    ];

    /**
     * Generate the next document number for a type, e.g. next('PO') => "PO/2026/VII/0001".
     * Formats live in config/erp.php (documents). Sequences reset per type per year.
     */
    public function next(string $type): string
    {
        $format = Erp::string("documents.{$type}", strtoupper($type).'/{Y}/{RM}/{N4}');
        $year = now()->year;
        $month = now()->month;

        return DB::transaction(function () use ($type, $format, $year, $month) {
            $sequence = NumberSequence::query()->firstOrCreate(
                ['type' => $type, 'year' => $year],
                ['last_number' => 0],
            );

            // Re-fetch with a row lock so concurrent requests can't share a number.
            $sequence = NumberSequence::query()
                ->whereKey($sequence->id)
                ->lockForUpdate()
                ->first();

            $sequence->last_number++;
            $sequence->save();

            $n = (int) $sequence->last_number;

            return strtr($format, [
                '{Y}' => (string) $year,
                '{M2}' => str_pad((string) $month, 2, '0', STR_PAD_LEFT),
                '{RM}' => self::ROMAN_MONTHS[$month],
                '{N3}' => str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                '{N4}' => str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                '{N5}' => str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            ]);
        });
    }
}
