<?php

namespace Modules\Finance\Support;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\NumberSequence;

/**
 * Nomor bukti potong — minted once per masa pajak, from a persistent counter.
 *
 * It used to be `++$sequence` over the rows of one export run. That made the
 * number a property of the RUN rather than of the certificate: re-exporting
 * masa 2026-06 after keying in the NPWP the blockers card asked for moved
 * BP-202606-0002 from CV Karya Sipil Sejahtera (Rp 18.550.000) to PT Mekanika
 * Prima (Rp 7.950.000), and pushed the first vendor's slip to -0003. A vendor
 * claiming its PPh credit cites that number; two vendors holding the same one
 * is a pembetulan SPT, not a re-export.
 *
 * WHY A COUNTER PER MASA AND NOT PER YEAR. The number embeds the masa
 * (BP-YYYYMM-NNNN), so the sequence has to reset with it, exactly as the export
 * always presented it. core_number_sequences is keyed (type, year); the masa
 * goes in the type ('BP-202606') so one masa can never borrow another's
 * numbers. DocumentNumberService itself is not reusable here because it counts
 * from now() — a bukti potong belongs to the month of the BILL, which may be
 * keyed weeks later.
 *
 * WHY THE UNIQUE INDEX MATTERS MORE THAN THE LOCK. lockForUpdate() is a no-op
 * on SQLite, so two concurrent approvals could read the same last_number. The
 * unique index on fin_ap_bills.bupot_no is what makes the second one fail to
 * commit rather than quietly issue a duplicate certificate. On MySQL (Fase 0)
 * the lock is real: measured 5 Sep 2026, 80 parallel approvals minted
 * BP-202609-0001..0080 contiguously (docs/bukti-uji/burst-mysql-2026-09-05.json).
 */
class BuktiPotongNumber
{
    /**
     * The next unused number for a masa. Reserves it — the caller is expected
     * to persist it on the bill inside the same transaction, so a rolled-back
     * approval gives the number back.
     */
    public static function allocate(int $year, int $month): string
    {
        return DB::transaction(function () use ($year, $month): string {
            // Created-if-missing and row-locked in one statement, as
            // DocumentNumberService does: the first certificate of a new masa
            // used to be the one moment two approvals could collide creating
            // this row (NumberSequence::lockBucket explains the MySQL race).
            $sequence = NumberSequence::lockBucket(self::sequenceType($year, $month), $year);

            $sequence->last_number++;
            $sequence->save();

            return self::format($year, $month, (int) $sequence->last_number);
        });
    }

    public static function format(int $year, int $month, int $number): string
    {
        return sprintf('BP-%04d%02d-%04d', $year, $month, $number);
    }

    private static function sequenceType(int $year, int $month): string
    {
        return sprintf('BP-%04d%02d', $year, $month);
    }
}
