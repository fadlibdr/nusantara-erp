<?php

namespace Modules\Assets\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Assets\Enums\AssetOwnership;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Enums\DepreciationRunStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\DepreciationRun;
use Modules\Finance\Services\JournalService;

/**
 * Monthly straight-line depreciation engine.
 *
 * monthly charge = (acquisition_cost - salvage_value) / useful_life_months,
 * capped so accumulated depreciation never exceeds (cost - salvage).
 *
 * A run is drafted per calendar period (one per year+month), reviewed, then
 * posted — posting is what mutates asset accumulated_depreciation/book_value.
 * Finance imports the posted run and books Dr beban penyusutan (e.g. 6-3100)
 * Cr akumulasi penyusutan per category account hints; this module only owns
 * the amounts.
 */
class DepreciationService
{
    /**
     * Build (or rebuild, while still draft) the depreciation run for a period.
     */
    public function runForPeriod(int $year, int $month): DepreciationRun
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Invalid period month {$month}; expected 1-12.");
        }

        $existing = DepreciationRun::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        if ($existing && $existing->isPosted()) {
            throw new LogicException("Depreciation for period {$existing->periodLabel()} is already posted.");
        }

        $latestPosted = DepreciationRun::query()
            ->where('status', DepreciationRunStatus::Posted->value)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first();

        // Periods must move forward: accumulated balances already include every
        // posted month, so drafting an earlier month would double-charge it.
        if ($latestPosted && ($year * 100 + $month) <= ($latestPosted->period_year * 100 + $latestPosted->period_month)) {
            throw new LogicException(
                'Cannot run period '.sprintf('%04d-%02d', $year, $month)
                ." at or before the last posted period {$latestPosted->periodLabel()}."
            );
        }

        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();

        return DB::transaction(function () use ($existing, $year, $month, $periodEnd): DepreciationRun {
            $run = $existing ?? new DepreciationRun([
                'period_year' => $year,
                'period_month' => $month,
                'status' => DepreciationRunStatus::Draft,
            ]);

            if (! $run->exists) {
                $run->save();
            }

            // Rebuilding a draft: wipe previous entries and recompute.
            $run->entries()->delete();

            $assets = Asset::query()
                ->where('status', '!=', AssetStatus::Disposed->value)
                // P5 — HANYA aset milik sendiri yang disusutkan. Alat sewa
                // tidak pernah dikapitalisasi, jadi tidak ada biaya perolehan
                // untuk disusutkan — sekalipun seseorang mengisi kolom
                // depreciation_start_date/useful_life_months pada barisnya,
                // gate ini yang menahannya, bukan kebetulan kolomnya kosong.
                ->where('ownership', AssetOwnership::Owned->value)
                ->whereNotNull('depreciation_start_date')
                ->whereDate('depreciation_start_date', '<=', $periodEnd->toDateString())
                ->where('useful_life_months', '>', 0)
                ->orderBy('code')
                ->get();

            $total = 0.0;

            foreach ($assets as $asset) {
                $amount = $this->chargeFor($asset);

                if ($amount <= 0) {
                    continue; // fully depreciated (or zero base) — nothing to charge
                }

                $run->entries()->create([
                    'asset_id' => $asset->id,
                    'amount' => $amount,
                    'book_value_after' => round((float) $asset->acquisition_cost - (float) $asset->accumulated_depreciation - $amount, 2),
                ]);

                $total += $amount;
            }

            $run->forceFill(['total_amount' => round($total, 2)])->save();

            return $run->load('entries.asset.category');
        });
    }

    /**
     * Post a draft run: apply every entry to its asset. Amounts are re-capped
     * against the asset's *current* accumulated balance so a stale draft can
     * never push accumulated depreciation past (cost - salvage).
     */
    public function post(DepreciationRun $run): DepreciationRun
    {
        if ($run->status !== DepreciationRunStatus::Draft) {
            throw new LogicException("Depreciation run {$run->periodLabel()} is {$run->status->value}; only draft runs can be posted.");
        }

        if ($run->entries()->doesntExist()) {
            throw new LogicException("Depreciation run {$run->periodLabel()} has no entries to post.");
        }

        return DB::transaction(function () use ($run): DepreciationRun {
            $total = 0.0;

            foreach ($run->entries()->with('asset')->get() as $entry) {
                $asset = $entry->asset;

                // Draf dibuat SEBELUM pelepasan, diposting SESUDAHNYA: tanpa
                // baris ini akumulasi naik pada aset yang sudah di-derecognise
                // dan GL menerima kredit akumulasi yang tidak dijelaskan aset
                // mana pun. dispose() menolak selama entri drafnya ada (sabuk
                // pertama); ini sabuk keduanya, untuk entri yang lolos lewat
                // urutan lain.
                if ($asset->status === AssetStatus::Disposed) {
                    $entry->delete();

                    continue;
                }

                $amount = min((float) $entry->amount, $asset->remainingDepreciable());

                if ($amount <= 0) {
                    $entry->delete(); // asset became fully depreciated after drafting

                    continue;
                }

                $accumulated = round((float) $asset->accumulated_depreciation + $amount, 2);
                $bookValue = round((float) $asset->acquisition_cost - $accumulated, 2);

                $asset->forceFill([
                    'accumulated_depreciation' => $accumulated,
                    'book_value' => $bookValue,
                ])->save();

                $entry->forceFill([
                    'amount' => round($amount, 2),
                    'book_value_after' => $bookValue,
                ])->save();

                $total += $amount;
            }

            $run->forceFill([
                'status' => DepreciationRunStatus::Posted,
                'total_amount' => round($total, 2),
                'posted_at' => now(),
            ])->save();

            $this->postJournal($run);

            return $run->load('entries.asset.category');
        });
    }

    /**
     * The depreciation charge, in the general ledger.
     *
     * Until this existed, posting a run updated accumulated_depreciation and
     * book_value on the assets and stopped there — depreciation expense never
     * reached the books, so `ast_categories.depreciation_account_hint` and
     * `accum_account_hint` were validated, displayed, and used to post nothing.
     * The word "hint" was doing a lot of work. They are the real accounts now.
     *
     *   Dr {category depreciation account}   this period's charge
     *       Cr {category accumulated account}
     *
     * Grouped by account pair, so a run over forty assets in four classes is
     * four pairs of lines rather than eighty.
     */
    private function postJournal(DepreciationRun $run): void
    {
        $byPair = [];

        foreach ($run->entries()->with('asset.category')->get() as $entry) {
            $category = $entry->asset?->category;
            $expense = $category?->depreciation_account_hint;
            $accumulated = $category?->accum_account_hint;

            // Refused, not guessed. Crediting the wrong accumulated-depreciation
            // account misstates two asset classes at once and is invisible on
            // the face of the balance sheet, where both sit under 1-2000.
            if (blank($expense) || blank($accumulated)) {
                throw new LogicException(sprintf(
                    'Kategori aset %s belum memiliki akun penyusutan/akumulasi. '
                    .'Lengkapi di Master Data › Kategori Aset sebelum memposting.',
                    $category?->name ?? $entry->asset?->code ?? '?',
                ));
            }

            $key = $expense.'|'.$accumulated;
            $byPair[$key] = ($byPair[$key] ?? 0) + (float) $entry->amount;
        }

        if ($byPair === []) {
            return;
        }

        $lines = [];
        $period = $run->periodLabel();

        foreach ($byPair as $key => $amount) {
            [$expense, $accumulated] = explode('|', $key);

            $lines[] = [
                'account_code' => $expense,
                'debit' => round($amount, 2),
                'description' => "Penyusutan {$period}",
            ];
            $lines[] = [
                'account_code' => $accumulated,
                'credit' => round($amount, 2),
                'description' => "Akumulasi penyusutan {$period}",
            ];
        }

        app(JournalService::class)->autoPost(
            'depreciation_run',
            (int) $run->id,
            $lines,
            $this->postingDate($run),
            "Penyusutan {$run->code} — {$period}",
        );
    }

    /**
     * The last day of the period being depreciated, not the day somebody
     * pressed post. Depreciation belongs to the month it charges.
     */
    private function postingDate(DepreciationRun $run): string
    {
        return \Carbon\Carbon::create($run->period_year, $run->period_month, 1)
            ->endOfMonth()
            ->toDateString();
    }

    /**
     * This period's straight-line charge for one asset, capped at the amount
     * still left to depreciate.
     */
    private function chargeFor(Asset $asset): float
    {
        $monthly = $asset->monthlyDepreciation();
        $remaining = $asset->remainingDepreciable();

        if ($monthly <= 0 || $remaining <= 0) {
            return 0.0;
        }

        return round(min($monthly, $remaining), 2);
    }
}
