<?php

namespace Modules\Estimation\Services;

use Illuminate\Support\Facades\DB;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\AhspComponent;

class AhspService
{
    public function create(array $data): Ahsp
    {
        return DB::transaction(function () use ($data): Ahsp {
            $ahsp = Ahsp::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'unit' => $data['unit'],
                'category' => $data['category'],
                'overhead_pct' => $data['overhead_pct'] ?? 10,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceComponents($ahsp, $data['components'] ?? []);

            return $this->recalcUnitPrice($ahsp);
        });
    }

    public function update(Ahsp $ahsp, array $data): Ahsp
    {
        return DB::transaction(function () use ($ahsp, $data): Ahsp {
            $ahsp->fill(collect($data)
                ->only(['code', 'name', 'unit', 'category', 'overhead_pct', 'notes'])
                // A blank cell must not overwrite a stored value with nothing.
                // est_ahsp.overhead_pct is NOT NULL, so an import file that
                // carries an `overhead_persen` column and leaves it empty for one
                // analysis would otherwise fail on a database constraint — and
                // the tempting fix, defaulting the empty cell to 10, would
                // silently reset a 15% analysis to the house rate. Absent means
                // "leave it alone"; only a written number moves it.
                ->reject(fn ($value, string $key): bool => $value === null && $key !== 'notes')
                ->all())->save();

            // Components are replaced wholesale when the key is present.
            if (array_key_exists('components', $data)) {
                $this->replaceComponents($ahsp, $data['components'] ?? []);
            }

            return $this->recalcUnitPrice($ahsp);
        });
    }

    public function replaceComponents(Ahsp $ahsp, array $components): void
    {
        $ahsp->components()->delete();

        foreach ($components as $component) {
            $ahsp->components()->create([
                'component_type' => $component['component_type'],
                'name' => $component['name'],
                'item_id' => $component['item_id'] ?? null,
                'unit' => $component['unit'],
                'coefficient' => $component['coefficient'],
                'unit_price' => $component['unit_price'],
            ]);
        }
    }

    /**
     * AHSP unit price = sum(coefficient * unit_price) * (1 + overhead_pct / 100).
     * The result is cached on est_ahsp.unit_price.
     */
    public function recalcUnitPrice(Ahsp $ahsp): Ahsp
    {
        $unitPrice = $this->unitPriceOf(
            $ahsp->components()->get()
                ->map(fn (AhspComponent $component): array => [
                    'coefficient' => $component->coefficient,
                    'unit_price' => $component->unit_price,
                ])
                ->all(),
            (float) $ahsp->overhead_pct,
        );

        $ahsp->forceFill(['unit_price' => $unitPrice])->save();

        return $ahsp;
    }

    /**
     * The same arithmetic, on components that are not saved yet.
     *
     * recalcUnitPrice() delegates to it so the formula — round each component to
     * the rupiah pair BEFORE summing, then add overhead — exists once. An import
     * that checked the book's printed unit price against a second, subtly
     * different reading of the same formula would refuse correct files over half
     * a rupiah of rounding, and would be believed.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    public function unitPriceOf(array $components, float $overheadPct): float
    {
        $base = 0.0;

        foreach ($components as $component) {
            $base += round((float) ($component['coefficient'] ?? 0) * (float) ($component['unit_price'] ?? 0), 2);
        }

        return round($base * (1 + $overheadPct / 100), 2);
    }

    /**
     * The analysis's own printed unit price, checked against the components the
     * file actually carries.
     *
     * The per-line `jumlah` checksum catches a MISREAD cell. Nothing catches a
     * MISSING one: an analysis whose "Kawat beton" row never got copied prices
     * every BOQ item that uses it a little low, every remaining line still foots,
     * and no screen anywhere says so. The book's total is the only reading of the
     * sheet that notices, so a difference past rounding refuses the analysis
     * instead of warning about it — a silently cheap AHSP is a bid lost or a job
     * taken at a loss.
     *
     * `harga_analisa` is never stored: recalcUnitPrice() computes est_ahsp.unit_price.
     *
     * @param  array<string, mixed>  $payload  as assembled by the document importer
     * @param  ?Ahsp  $target  the analysis this file would overwrite, null on a create
     * @return array<int, string> at most one reason
     */
    public function statedPriceBlockers(array $payload, ?Ahsp $target = null): array
    {
        $stated = $payload['unit_price'] ?? null;

        if ($stated === null) {
            return [];
        }

        // The rate the WRITE will use, never a flat 10.
        //
        // create() defaults a blank overhead to 10, but update() rejects a null
        // overhead_pct and KEEPS the stored rate — and the template tells
        // the estimator to leave the cell blank for exactly that reason. Checking
        // a re-imported book against 10 when the analysis stays at 15 broke the
        // guard in both directions: a book re-imported at its own correct printed
        // price was refused for a component that was never missing, and a file
        // that HAD lost a component was accepted because 10% of a smaller base
        // happened to land on the printed number. The check has to read the rate
        // the save will read, and the message has to say which one that was —
        // "overhead 15%" against a sheet whose cell is empty is unreadable
        // otherwise.
        [$overhead, $source] = match (true) {
            ($payload['overhead_pct'] ?? null) !== null => [(float) $payload['overhead_pct'], 'dari berkas'],
            $target !== null => [(float) $target->overhead_pct, 'dari analisa tersimpan'],
            default => [10.0, 'bawaan analisa baru'],
        };

        $computed = $this->unitPriceOf($payload['components'] ?? [], $overhead);
        $stated = (float) $stated;

        // Below a rupiah, or below half a percent, the difference is rounding —
        // the same tolerance the line-level checksum uses.
        if (abs($computed - $stated) <= max(1.0, abs($stated) * 0.005)) {
            return [];
        }

        return [sprintf(
            'harga_analisa: berkas menulis %s, tetapi jumlah (koefisien x harga satuan) ditambah overhead %s%% (%s) = %s.'
            .' Periksa apakah ada baris komponen yang tertinggal.',
            number_format($stated, 2, ',', '.'),
            rtrim(rtrim(number_format($overhead, 2, ',', '.'), '0'), ','),
            $source,
            number_format($computed, 2, ',', '.'),
        )];
    }

    /**
     * What re-importing this analysis will NOT do.
     *
     * est_boq_items copies description, unit and unit_price off the analysis at
     * the moment the line is added, and nothing re-reads them — deliberately, so
     * an approved BOQ cannot move under a signature. Re-pricing an analysis
     * therefore changes future BOQ lines only, and an estimator who expects the
     * new price to flow into the RAB he is looking at has to be told it will not.
     *
     * @return array<int, string>
     */
    public function inUseWarnings(Ahsp $ahsp): array
    {
        $count = $ahsp->boqItems()->count();

        if ($count === 0) {
            return [];
        }

        return ["{$count} baris BOQ memakai analisa ini; harga yang sudah tersimpan di BOQ TIDAK ikut berubah"
            .' — perbarui BOQ-nya bila harga baru harus dipakai.'];
    }
}
