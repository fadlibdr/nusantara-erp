<?php

namespace Modules\HrPayroll\Services;

use InvalidArgumentException;
use Modules\HrPayroll\Enums\PtkpStatus;

/**
 * PPh 21 withholding per PMK 168/2023 (Tarif Efektif Rata-rata, effective 1 Jan 2024).
 *
 * Jan-Nov: monthly withholding = TER rate x gross monthly income, where the rate is
 * looked up in the bracket table of the employee's TER category (A/B/C by PTKP status).
 * December: annual recalculation with the Pasal 17 progressive schedule minus the
 * TER amounts already withheld in Jan-Nov (see annualTrueUp()).
 *
 * Bracket boundaries transcribed from the PMK 168/2023 attachment — verify against
 * the official PMK 168/2023 attachment before production use. Tables are monotonic:
 * each bracket's min equals the previous bracket's max (min exclusive, max inclusive).
 */
class Pph21TerService
{
    /**
     * Pasal 21 ayat (5a) UU PPh: recipients without an NPWP are withheld at 120%.
     * Since PMK 112/PMK.03/2022 the NIK functions as NPWP, so in practice the
     * surcharge only hits employees with neither NPWP nor an activated NIK.
     */
    public const NON_TAX_ID_SURCHARGE = 1.2;

    /** TER category A — PTKP TK/0, TK/1, K/0 (rate in percent). */
    private const CATEGORY_A = [
        ['min' => 0, 'max' => 5400000, 'rate' => 0.0],
        ['min' => 5400000, 'max' => 5650000, 'rate' => 0.25],
        ['min' => 5650000, 'max' => 5950000, 'rate' => 0.5],
        ['min' => 5950000, 'max' => 6300000, 'rate' => 0.75],
        ['min' => 6300000, 'max' => 6750000, 'rate' => 1.0],
        ['min' => 6750000, 'max' => 7500000, 'rate' => 1.25],
        ['min' => 7500000, 'max' => 8550000, 'rate' => 1.5],
        ['min' => 8550000, 'max' => 9650000, 'rate' => 1.75],
        ['min' => 9650000, 'max' => 10050000, 'rate' => 2.0],
        ['min' => 10050000, 'max' => 10350000, 'rate' => 2.25],
        ['min' => 10350000, 'max' => 10700000, 'rate' => 2.5],
        ['min' => 10700000, 'max' => 11050000, 'rate' => 3.0],
        ['min' => 11050000, 'max' => 11600000, 'rate' => 3.5],
        ['min' => 11600000, 'max' => 12500000, 'rate' => 4.0],
        ['min' => 12500000, 'max' => 13750000, 'rate' => 5.0],
        ['min' => 13750000, 'max' => 15100000, 'rate' => 6.0],
        ['min' => 15100000, 'max' => 16950000, 'rate' => 7.0],
        ['min' => 16950000, 'max' => 19750000, 'rate' => 8.0],
        ['min' => 19750000, 'max' => 24150000, 'rate' => 9.0],
        ['min' => 24150000, 'max' => 26450000, 'rate' => 10.0],
        ['min' => 26450000, 'max' => 28000000, 'rate' => 11.0],
        ['min' => 28000000, 'max' => 30050000, 'rate' => 12.0],
        ['min' => 30050000, 'max' => 32400000, 'rate' => 13.0],
        ['min' => 32400000, 'max' => 35400000, 'rate' => 14.0],
        ['min' => 35400000, 'max' => 39100000, 'rate' => 15.0],
        ['min' => 39100000, 'max' => 43850000, 'rate' => 16.0],
        ['min' => 43850000, 'max' => 47800000, 'rate' => 17.0],
        ['min' => 47800000, 'max' => 51400000, 'rate' => 18.0],
        ['min' => 51400000, 'max' => 56300000, 'rate' => 19.0],
        ['min' => 56300000, 'max' => 62200000, 'rate' => 20.0],
        ['min' => 62200000, 'max' => 68600000, 'rate' => 21.0],
        ['min' => 68600000, 'max' => 77500000, 'rate' => 22.0],
        ['min' => 77500000, 'max' => 89000000, 'rate' => 23.0],
        ['min' => 89000000, 'max' => 103000000, 'rate' => 24.0],
        ['min' => 103000000, 'max' => 125000000, 'rate' => 25.0],
        ['min' => 125000000, 'max' => 157000000, 'rate' => 26.0],
        ['min' => 157000000, 'max' => 206000000, 'rate' => 27.0],
        ['min' => 206000000, 'max' => 337000000, 'rate' => 28.0],
        ['min' => 337000000, 'max' => 454000000, 'rate' => 29.0],
        ['min' => 454000000, 'max' => 550000000, 'rate' => 30.0],
        ['min' => 550000000, 'max' => 695000000, 'rate' => 31.0],
        ['min' => 695000000, 'max' => 910000000, 'rate' => 32.0],
        ['min' => 910000000, 'max' => 1400000000, 'rate' => 33.0],
        ['min' => 1400000000, 'max' => null, 'rate' => 34.0],
    ];

    /** TER category B — PTKP TK/2, TK/3, K/1, K/2 (rate in percent). */
    private const CATEGORY_B = [
        ['min' => 0, 'max' => 6200000, 'rate' => 0.0],
        ['min' => 6200000, 'max' => 6500000, 'rate' => 0.25],
        ['min' => 6500000, 'max' => 6850000, 'rate' => 0.5],
        ['min' => 6850000, 'max' => 7300000, 'rate' => 0.75],
        ['min' => 7300000, 'max' => 9200000, 'rate' => 1.0],
        ['min' => 9200000, 'max' => 10750000, 'rate' => 1.5],
        ['min' => 10750000, 'max' => 11250000, 'rate' => 2.0],
        ['min' => 11250000, 'max' => 11600000, 'rate' => 2.5],
        ['min' => 11600000, 'max' => 12600000, 'rate' => 3.0],
        ['min' => 12600000, 'max' => 13600000, 'rate' => 4.0],
        ['min' => 13600000, 'max' => 14950000, 'rate' => 5.0],
        ['min' => 14950000, 'max' => 16400000, 'rate' => 6.0],
        ['min' => 16400000, 'max' => 18450000, 'rate' => 7.0],
        ['min' => 18450000, 'max' => 21850000, 'rate' => 8.0],
        ['min' => 21850000, 'max' => 26000000, 'rate' => 9.0],
        ['min' => 26000000, 'max' => 27700000, 'rate' => 10.0],
        ['min' => 27700000, 'max' => 29350000, 'rate' => 11.0],
        ['min' => 29350000, 'max' => 31450000, 'rate' => 12.0],
        ['min' => 31450000, 'max' => 33950000, 'rate' => 13.0],
        ['min' => 33950000, 'max' => 37100000, 'rate' => 14.0],
        ['min' => 37100000, 'max' => 41100000, 'rate' => 15.0],
        ['min' => 41100000, 'max' => 45800000, 'rate' => 16.0],
        ['min' => 45800000, 'max' => 49500000, 'rate' => 17.0],
        ['min' => 49500000, 'max' => 53800000, 'rate' => 18.0],
        ['min' => 53800000, 'max' => 58500000, 'rate' => 19.0],
        ['min' => 58500000, 'max' => 64000000, 'rate' => 20.0],
        ['min' => 64000000, 'max' => 71000000, 'rate' => 21.0],
        ['min' => 71000000, 'max' => 80000000, 'rate' => 22.0],
        ['min' => 80000000, 'max' => 93000000, 'rate' => 23.0],
        ['min' => 93000000, 'max' => 109000000, 'rate' => 24.0],
        ['min' => 109000000, 'max' => 129000000, 'rate' => 25.0],
        ['min' => 129000000, 'max' => 163000000, 'rate' => 26.0],
        ['min' => 163000000, 'max' => 211000000, 'rate' => 27.0],
        ['min' => 211000000, 'max' => 374000000, 'rate' => 28.0],
        ['min' => 374000000, 'max' => 459000000, 'rate' => 29.0],
        ['min' => 459000000, 'max' => 555000000, 'rate' => 30.0],
        ['min' => 555000000, 'max' => 704000000, 'rate' => 31.0],
        ['min' => 704000000, 'max' => 957000000, 'rate' => 32.0],
        ['min' => 957000000, 'max' => 1405000000, 'rate' => 33.0],
        ['min' => 1405000000, 'max' => null, 'rate' => 34.0],
    ];

    /** TER category C — PTKP K/3 (rate in percent). */
    private const CATEGORY_C = [
        ['min' => 0, 'max' => 6600000, 'rate' => 0.0],
        ['min' => 6600000, 'max' => 6950000, 'rate' => 0.25],
        ['min' => 6950000, 'max' => 7350000, 'rate' => 0.5],
        ['min' => 7350000, 'max' => 7800000, 'rate' => 0.75],
        ['min' => 7800000, 'max' => 8850000, 'rate' => 1.0],
        ['min' => 8850000, 'max' => 9800000, 'rate' => 1.25],
        ['min' => 9800000, 'max' => 10950000, 'rate' => 1.5],
        ['min' => 10950000, 'max' => 11200000, 'rate' => 1.75],
        ['min' => 11200000, 'max' => 12050000, 'rate' => 2.0],
        ['min' => 12050000, 'max' => 12950000, 'rate' => 3.0],
        ['min' => 12950000, 'max' => 14150000, 'rate' => 4.0],
        ['min' => 14150000, 'max' => 15550000, 'rate' => 5.0],
        ['min' => 15550000, 'max' => 17050000, 'rate' => 6.0],
        ['min' => 17050000, 'max' => 19500000, 'rate' => 7.0],
        ['min' => 19500000, 'max' => 22700000, 'rate' => 8.0],
        ['min' => 22700000, 'max' => 26600000, 'rate' => 9.0],
        ['min' => 26600000, 'max' => 28100000, 'rate' => 10.0],
        ['min' => 28100000, 'max' => 30100000, 'rate' => 11.0],
        ['min' => 30100000, 'max' => 32600000, 'rate' => 12.0],
        ['min' => 32600000, 'max' => 35400000, 'rate' => 13.0],
        ['min' => 35400000, 'max' => 38900000, 'rate' => 14.0],
        ['min' => 38900000, 'max' => 43000000, 'rate' => 15.0],
        ['min' => 43000000, 'max' => 47400000, 'rate' => 16.0],
        ['min' => 47400000, 'max' => 51200000, 'rate' => 17.0],
        ['min' => 51200000, 'max' => 55800000, 'rate' => 18.0],
        ['min' => 55800000, 'max' => 60400000, 'rate' => 19.0],
        ['min' => 60400000, 'max' => 66700000, 'rate' => 20.0],
        ['min' => 66700000, 'max' => 74500000, 'rate' => 21.0],
        ['min' => 74500000, 'max' => 83200000, 'rate' => 22.0],
        ['min' => 83200000, 'max' => 95600000, 'rate' => 23.0],
        ['min' => 95600000, 'max' => 110000000, 'rate' => 24.0],
        ['min' => 110000000, 'max' => 134000000, 'rate' => 25.0],
        ['min' => 134000000, 'max' => 169000000, 'rate' => 26.0],
        ['min' => 169000000, 'max' => 221000000, 'rate' => 27.0],
        ['min' => 221000000, 'max' => 390000000, 'rate' => 28.0],
        ['min' => 390000000, 'max' => 463000000, 'rate' => 29.0],
        ['min' => 463000000, 'max' => 561000000, 'rate' => 30.0],
        ['min' => 561000000, 'max' => 709000000, 'rate' => 31.0],
        ['min' => 709000000, 'max' => 965000000, 'rate' => 32.0],
        ['min' => 965000000, 'max' => 1419000000, 'rate' => 33.0],
        ['min' => 1419000000, 'max' => null, 'rate' => 34.0],
    ];

    /** Pasal 17 ayat (1) huruf a UU PPh (annual progressive schedule, UU HPP layers). */
    private const PASAL_17 = [
        ['limit' => 60000000, 'rate' => 5.0],
        ['limit' => 250000000, 'rate' => 15.0],
        ['limit' => 500000000, 'rate' => 25.0],
        ['limit' => 5000000000, 'rate' => 30.0],
        ['limit' => null, 'rate' => 35.0],
    ];

    /** Biaya jabatan: 5% of gross, capped at Rp 500.000/month = Rp 6.000.000/year. */
    private const BIAYA_JABATAN_RATE = 0.05;

    private const BIAYA_JABATAN_ANNUAL_CAP = 6000000.0;

    /**
     * Monthly TER withholding.
     *
     * @return array{category: string, rate: float, amount: float}
     */
    public function monthlyTax(PtkpStatus $ptkpStatus, float $monthlyGross, bool $hasTaxId = true): array
    {
        $category = $ptkpStatus->terCategory();
        $rate = $this->rateFor($category, $monthlyGross);
        $amount = round($monthlyGross * $rate / 100, 2);

        if (! $hasTaxId) {
            $amount = round($amount * self::NON_TAX_ID_SURCHARGE, 2);
        }

        return ['category' => $category, 'rate' => $rate, 'amount' => $amount];
    }

    /**
     * TER rate (percent) for a category and monthly gross income.
     */
    public function rateFor(string $category, float $monthlyGross): float
    {
        $table = match (strtoupper($category)) {
            'A' => self::CATEGORY_A,
            'B' => self::CATEGORY_B,
            'C' => self::CATEGORY_C,
            default => throw new InvalidArgumentException("Unknown TER category [{$category}]."),
        };

        foreach ($table as $bracket) {
            $inBracket = $monthlyGross > $bracket['min']
                && ($bracket['max'] === null || $monthlyGross <= $bracket['max']);

            if ($inBracket) {
                return (float) $bracket['rate'];
            }
        }

        return 0.0; // gross <= 0
    }

    /**
     * December annual recalculation (PMK 168/2023 Pasal 15):
     * annual PPh 21 via Pasal 17 on net taxable income, minus TER withheld Jan-Nov.
     *
     * Net taxable = annual gross - biaya jabatan (5%, cap 6jt/yr)
     *             - employee pension contributions (JHT + JP employee share) - PTKP,
     * rounded DOWN to full thousands (PKP dibulatkan ribuan penuh ke bawah).
     *
     * december_tax may be negative: over-withheld TER is refunded to the employee
     * through the December payroll.
     *
     * @return array{taxable: float, annual_tax: float, december_tax: float}
     */
    public function annualTrueUp(
        PtkpStatus $ptkpStatus,
        float $annualGross,
        float $annualEmployeePensionContributions,
        float $withheldJanToNov,
        bool $hasTaxId = true,
    ): array {
        $biayaJabatan = min($annualGross * self::BIAYA_JABATAN_RATE, self::BIAYA_JABATAN_ANNUAL_CAP);

        $taxable = max(
            0.0,
            $annualGross - $biayaJabatan - $annualEmployeePensionContributions - $ptkpStatus->annualPtkp(),
        );
        $taxable = floor($taxable / 1000) * 1000;

        $annualTax = $this->pasal17($taxable);

        if (! $hasTaxId) {
            $annualTax = round($annualTax * self::NON_TAX_ID_SURCHARGE, 2);
        }

        return [
            'taxable' => $taxable,
            'annual_tax' => $annualTax,
            'december_tax' => round($annualTax - $withheldJanToNov, 2),
        ];
    }

    /**
     * Pasal 17 progressive tax on annual taxable income.
     */
    public function pasal17(float $taxable): float
    {
        $tax = 0.0;
        $previousLimit = 0.0;

        foreach (self::PASAL_17 as $layer) {
            if ($taxable <= $previousLimit) {
                break;
            }

            $upper = $layer['limit'] === null ? $taxable : min($taxable, (float) $layer['limit']);
            $tax += ($upper - $previousLimit) * $layer['rate'] / 100;
            $previousLimit = (float) ($layer['limit'] ?? $taxable);
        }

        return round($tax, 2);
    }
}
