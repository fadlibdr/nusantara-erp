<?php

namespace Modules\Procurement\Support;

use Modules\Procurement\Exceptions\BidWeightConfigException;

/**
 * The five aspect weights of the weighted bid tabulation (sistem nilai DAN 4.8).
 *
 * ONE reader, and it validates on the way through: the weights are a percentage
 * split that MUST sum to 100, and a tabulation built on a split that does not is
 * a ranking on an invented scale. assertValidConfig() is called at boot from
 * ProcurementServiceProvider so a misweighted config/erp.php stops the app
 * before it can score a single vendor; weights() is what the tabulation reads,
 * and it refuses the same way rather than trusting that boot already ran (a
 * console command or a test can reach it first).
 */
class BidWeights
{
    /** The aspects, in tabulation column order. harga is computed; the rest are input. */
    public const ASPECTS = ['harga', 'mutu', 'waktu', 'keuangan', 'k3'];

    private const SHIPPED = ['harga' => 50, 'mutu' => 30, 'waktu' => 5, 'keuangan' => 10, 'k3' => 5];

    /**
     * The validated weights as aspect => weight (int/float), summing to 100.
     *
     * @return array<string, float>
     */
    public static function weights(): array
    {
        $weights = self::read();
        self::assertValid($weights);

        return $weights;
    }

    /** Boot guard: throws BidWeightConfigException on a misweighted config. */
    public static function assertValidConfig(): void
    {
        self::assertValid(self::read());
    }

    /**
     * @param  array<string, float>  $weights
     *
     * @throws BidWeightConfigException
     */
    public static function assertValid(array $weights): void
    {
        foreach (self::ASPECTS as $aspect) {
            if (! array_key_exists($aspect, $weights)) {
                throw new BidWeightConfigException(
                    "Bobot penilaian penawaran (procurement.bid_weights) kehilangan aspek \"{$aspect}\"; "
                    .'kelima aspek (harga, mutu, waktu, keuangan, k3) wajib ada.'
                );
            }

            if ((float) $weights[$aspect] < 0) {
                throw new BidWeightConfigException(
                    "Bobot penilaian penawaran untuk aspek \"{$aspect}\" tidak boleh negatif."
                );
            }
        }

        $sum = 0.0;

        foreach (self::ASPECTS as $aspect) {
            $sum += (float) $weights[$aspect];
        }

        // Integer percentages in practice; a hair of float tolerance so a config
        // of 33.33/33.33/33.34 style splits is not refused for rounding.
        if (abs($sum - 100.0) > 0.001) {
            throw new BidWeightConfigException(sprintf(
                'Bobot penilaian penawaran (procurement.bid_weights) harus berjumlah 100, tetapi berjumlah %s. '
                .'Perbaiki config/erp.php sebelum tabulasi berbobot dipakai.',
                rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.'),
            ));
        }
    }

    /** @return array<string, float> */
    private static function read(): array
    {
        $raw = config('erp.procurement.bid_weights', self::SHIPPED);

        if (! is_array($raw)) {
            $raw = self::SHIPPED;
        }

        $weights = [];

        foreach ($raw as $aspect => $weight) {
            $weights[(string) $aspect] = (float) $weight;
        }

        return $weights;
    }
}
