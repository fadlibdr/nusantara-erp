<?php

namespace Modules\Projects\Enums;

/**
 * Why an EVM ratio is what it is — and, when it is null, why there is no ratio.
 *
 * This enum is the answer to "division by zero is not an answer". Every ratio
 * in the EVM payload (SPI, CPI, TCPI) is a nullable float paired with one of
 * these, because the alternative is arithmetic that json_encode() refuses:
 * INF and NAN cannot be encoded, so a single unguarded division turns the worst
 * project in the portfolio into an HTTP 500 — the one project whose report
 * somebody urgently needs.
 *
 * The distinction that matters most is between NoCostRecorded and a genuine
 * zero. EV > 0 with AC = 0 is an unrecorded cost, not infinite efficiency, and
 * printing infinity would tell a project manager they are doing brilliantly.
 * EV = 0 with AC > 0 is money spent for nothing earned — a real, meaningful
 * zero, and suppressing it would hide the worst case the report exists to catch.
 */
enum IndexStatus: string
{
    case Ok = 'ok';
    case NoPlannedValue = 'no_planned_value';
    case NoCostRecorded = 'no_cost_recorded';
    case BudgetExhausted = 'budget_exhausted';
    case CostIncomplete = 'cost_incomplete';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Normal',
            self::NoPlannedValue => 'Belum ada rencana',
            self::NoCostRecorded => 'Belum ada biaya',
            self::BudgetExhausted => 'Anggaran habis',
            self::CostIncomplete => 'Biaya belum lengkap',
        };
    }

    public function note(): ?string
    {
        return match ($this) {
            self::Ok => null,
            self::NoPlannedValue => 'Belum ada nilai rencana pada tanggal ini',
            self::NoCostRecorded => 'Belum ada biaya tercatat',
            self::BudgetExhausted => 'Anggaran sudah habis',
            self::CostIncomplete => 'Biaya aktual belum lengkap',
        };
    }
}
