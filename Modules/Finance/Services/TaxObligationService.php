<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\TaxMasaType;
use Modules\Finance\Models\TaxObligation;

/**
 * Kalender pajak (#25): mint the masa rows for a year, and record the manual
 * setor/lapor facts on them. The due dates come from Support\TaxDeadlines —
 * the SAME rules CashFlowService charges the cash projection with — so the
 * register can never promise a different date than the projection plans for.
 */
class TaxObligationService
{
    private const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * One row per (jenis, masa) for the year — idempotent, and it never
     * touches an existing row: the register's value is the NTPN and dates the
     * operator typed, and a re-generate that reset them would erase the only
     * record that a masa was ever paid.
     *
     * @return int rows actually created
     */
    public function ensureYear(int $year): int
    {
        return DB::transaction(function () use ($year): int {
            $created = 0;

            foreach (TaxMasaType::cases() as $type) {
                for ($month = 1; $month <= 12; $month++) {
                    $row = TaxObligation::query()->firstOrCreate(
                        [
                            'tax_type' => $type->value,
                            'masa_year' => $year,
                            'masa_month' => $month,
                        ],
                        [
                            'name' => $type->label().' masa '.self::BULAN[$month - 1].' '.$year,
                            'due_date' => $type->dueForMasa($year, $month)->toDateString(),
                        ],
                    );

                    if ($row->wasRecentlyCreated) {
                        $created++;
                    }
                }
            }

            return $created;
        });
    }

    /**
     * Record the manual facts of a masa. Decided on a locked re-read inside
     * the transaction (the stale-instance rule): two clerks working the same
     * masa row must not overwrite each other's NTPN through stale copies.
     *
     * Two refusals keep the register honest:
     *
     *   disetor without NTPN     an SSP without its NTPN is not a setoran, it
     *       is an intention — the NTPN is the state's receipt number and the
     *       only thing an auditor can verify the payment by;
     *   dilapor before disetor   the SPT masa reports a payment that has not
     *       happened. The one legitimate exception is a NIHIL masa (amount 0
     *       or blank): nothing to deposit, the report still due.
     */
    public function update(TaxObligation $obligation, array $data): TaxObligation
    {
        return DB::transaction(function () use ($obligation, $data): TaxObligation {
            /** @var TaxObligation $obligation */
            $obligation = TaxObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            $obligation->fill(Arr::only($data, [
                'amount', 'ntpn', 'disetor_date', 'dilapor_date', 'journal_id', 'notes',
            ]));

            if ($obligation->disetor_date !== null && blank($obligation->ntpn)) {
                throw new LogicException(
                    "Setoran {$obligation->name} harus mencantumkan NTPN dari SSP/BPN-nya; "
                    .'tanpa NTPN pembayarannya tidak dapat diverifikasi.'
                );
            }

            if ($obligation->dilapor_date !== null
                && $obligation->disetor_date === null
                && round((float) ($obligation->amount ?? 0), 2) > 0.0) {
                throw new LogicException(
                    "{$obligation->name} bernilai lebih dari nol dan belum disetor; "
                    .'catat setorannya (dengan NTPN) sebelum mencatat pelaporannya. '
                    .'Masa nihil boleh langsung dilapor.'
                );
            }

            $obligation->save();

            return $obligation->refresh();
        });
    }
}
