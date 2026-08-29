<?php

namespace Modules\Assets\Services;

use Illuminate\Support\Carbon;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Enums\RateBasis;
use Modules\Assets\Models\Asset;

/**
 * P5 — Evaluasi Sewa vs Beli, BACA SAJA (deviasi 3.5 "sewa-vs-beli ⬜").
 *
 * Satu baris per aset hidup: sisi SEWA (jam register x tarif, atau kalender x
 * tarif untuk basis bulanan/harian) berdampingan dengan sisi BELI (harga
 * perolehan, penyusutan). Pembacanya yang membandingkan antar baris; layar
 * ini tidak menulis apa pun dan tidak menyimpan kesimpulan apa pun — sebuah
 * perbandingan, bukan sebuah keputusan.
 *
 * KEJUJURAN, karena perbandingan yang salah lebih buruk daripada tidak ada:
 *
 *   - alat sewa tanpa jam tercatat pada register menampilkan NULL (bergaris),
 *     bukan 0 — "belum ada data" dan "tidak pernah dipakai" adalah dua
 *     kalimat yang berbeda;
 *   - aset beli tanpa harga perolehan (baris warisan/impor — skema P5
 *     mengizinkan NULL) berkata "tidak dapat dibandingkan", bukan
 *     membandingkan dengan Rp 0;
 *   - alat sewa basis kalender tanpa rental_start tidak bisa menghitung
 *     berapa lama ia sudah disewa, dan berkata begitu.
 *
 * Jam dihitung per mobilisasi — pembacaan terakhir minus pembacaan pertama
 * (meter monoton per mobilisasi, dijaga EquipmentLogService) — lalu
 * dijumlahkan lintas mobilisasi hidup aset itu. Mobilisasi dengan kurang
 * dari dua pembacaan tidak menyumbang delta yang terukur.
 */
class RentVsOwnService
{
    public function compare(): array
    {
        $assets = Asset::query()
            ->with(['category', 'vendor', 'deployments.equipmentLogs' => function ($query): void {
                $query->whereNotNull('hour_meter')->orderBy('log_date')->orderBy('id');
            }])
            ->where('status', '!=', AssetStatus::Disposed->value)
            ->orderBy('code')
            ->get();

        $rows = [];

        foreach ($assets as $asset) {
            $hours = $this->hoursLogged($asset);

            $row = [
                'asset_id' => $asset->id,
                'asset_code' => $asset->code,
                'asset_name' => $asset->name,
                'category' => $asset->category?->name,
                'ownership' => $asset->ownership?->value,
                'hours_logged' => $hours,
                'rate_basis' => $asset->rate_basis?->value,
                'rental_rate' => $asset->rental_rate !== null ? (float) $asset->rental_rate : null,
                'rental_cost' => null,
                'vendor_name' => $asset->vendor?->name,
                'acquisition_cost' => $asset->acquisition_cost !== null ? (float) $asset->acquisition_cost : null,
                'accumulated_depreciation' => null,
                'monthly_depreciation' => null,
                'cost_per_hour' => null,
                'comparable' => false,
                'note' => null,
            ];

            if ($asset->isRented()) {
                [$row['rental_cost'], $row['note']] = $this->rentalCost($asset, $hours);
                $row['comparable'] = $row['rental_cost'] !== null;

                if ($hours !== null && $hours > 0 && $row['rental_cost'] !== null) {
                    $row['cost_per_hour'] = round($row['rental_cost'] / $hours, 2);
                }
            } else {
                if ($asset->acquisition_cost === null) {
                    // Baris warisan tanpa harga perolehan: tidak ada sisi
                    // beli untuk dibandingkan, dan Rp 0 bukan jawabannya.
                    $row['note'] = 'Tidak dapat dibandingkan — harga perolehan tidak tercatat.';
                } else {
                    $row['accumulated_depreciation'] = (float) $asset->accumulated_depreciation;
                    $row['monthly_depreciation'] = $asset->monthlyDepreciation();
                    $row['comparable'] = true;

                    if ($hours !== null && $hours > 0) {
                        $row['cost_per_hour'] = round((float) $asset->accumulated_depreciation / $hours, 2);
                    } else {
                        $row['note'] = 'Belum ada jam tercatat pada register — biaya per jam belum dapat dihitung.';
                    }
                }
            }

            $rows[] = $row;
        }

        return ['rows' => $rows];
    }

    /**
     * Total delta hour-meter aset ini: Σ per mobilisasi hidup (pembacaan
     * terakhir - pertama). NULL bila tidak ada satu pun delta yang terukur —
     * bukan 0.0, karena "tidak ada data" bukan "nol jam".
     */
    private function hoursLogged(Asset $asset): ?float
    {
        $total = null;

        foreach ($asset->deployments as $deployment) {
            $readings = $deployment->equipmentLogs;

            if ($readings->count() < 2) {
                continue;
            }

            $delta = round((float) $readings->last()->hour_meter - (float) $readings->first()->hour_meter, 3);
            $total = round(($total ?? 0.0) + $delta, 3);
        }

        return $total;
    }

    /**
     * Biaya sewa berjalan menurut basisnya, atau [null, alasan] bila belum
     * bisa dihitung dengan jujur.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function rentalCost(Asset $asset, ?float $hours): array
    {
        $rate = $asset->rental_rate !== null ? (float) $asset->rental_rate : null;

        if ($rate === null || $asset->rate_basis === null) {
            return [null, 'Tarif/basis sewa belum diisi pada master aset.'];
        }

        if ($asset->rate_basis === RateBasis::PerJam) {
            if ($hours === null) {
                return [null, 'Belum ada jam tercatat pada register — biaya sewa belum dapat dihitung.'];
            }

            return [round($hours * $rate, 2), null];
        }

        // Basis kalender: butuh tahu sejak kapan alat ini disewa.
        if ($asset->rental_start === null) {
            return [null, 'Periode sewa (rental_start) belum diisi — biaya sewa berjalan belum dapat dihitung.'];
        }

        $start = $asset->rental_start->copy()->startOfDay();
        $end = ($asset->rental_end ?? Carbon::today())->copy()->startOfDay();

        if ($end->lt($start)) {
            $end = $start->copy();
        }

        if ($asset->rate_basis === RateBasis::PerHari8Jam) {
            $days = (int) $start->diffInDays($end) + 1; // inklusif dua ujung, aturan hari yang sama dengan utilisasi

            return [round($days * $rate, 2), null];
        }

        // per_bulan: bulan berjalan dihitung per bulan DIMULAI (sewa bulanan
        // tidak dipotong prorata oleh vendor rental pada umumnya) — 1 hari
        // sampai 1 bulan = 1 bulan, 1 bulan 1 hari = 2 bulan.
        $months = (int) $start->diffInMonths($end) + 1;

        return [round($months * $rate, 2), null];
    }
}
