<?php

namespace Modules\Finance\Support;

use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\RevenueRecognitionRun;

/**
 * "Has a posted PSAK 115 run already measured this month, or any month after
 * it?" — one question, asked from two places that must agree.
 *
 * JournalService::reversalDate() asks it to decide whether a cancellation may
 * reverse inside the original period. PeriodCloseService::reopen() asks it to
 * decide whether a closed period may be opened again. The consequence is the
 * same in both cases and is the reason the answer is a refusal rather than a
 * warning: a posted run can never be recalculated (see
 * RevenueRecognitionService::calculate — "Pembatalan pengakuan adalah penyajian
 * kembali — bukan hitung ulang"), so a cost that lands in a measured month does
 * not change THAT month's percentage of completion. It changes the NEXT one,
 * because previousBalances() deltas against the posted baseline while
 * computeLine() reads cost live. One late posting, two wrong income statements,
 * and no way back.
 *
 * Two implementations of that test would eventually disagree, and the month
 * they disagreed about would be the one nobody could explain.
 */
class MeasuredPeriods
{
    /**
     * The earliest POSTED run measuring this period or any later one.
     *
     * @param  int  $key  year * 100 + month
     */
    public static function postedRunAtOrAfter(int $key): ?RevenueRecognitionRun
    {
        return RevenueRecognitionRun::query()
            ->where('status', PostingStatus::Posted->value)
            ->whereRaw('(period_year * 100 + period_month) >= ?', [$key])
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->first();
    }

    public static function isMeasured(int $key): bool
    {
        return self::postedRunAtOrAfter($key) !== null;
    }
}
