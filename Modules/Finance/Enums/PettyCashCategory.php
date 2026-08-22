<?php

namespace Modules\Finance\Enums;

/**
 * What a petty-cash rupiah was spent on — the vocabulary of the bon itself
 * ("beli semen 2 sak", "bensin + tol survei", "nasi kotak rapat"), kept
 * deliberately shorter than the full COA.
 *
 * Each category knows THREE mappings, and the split is the whole point:
 *
 *  - cogsAccountCode()  the 5-xxxx HPP leaf a PROJECT-attributed bon debits;
 *  - opexAccountCode()  the 6-xxxx leaf an office bon debits;
 *  - costCategory()     the RAP bucket the project cost row lands in, so a
 *    Rp 150.000 fuel bon against PRJ-2026-001 moves the PSAK 115 cost-to-cost
 *    percentage the day it is posted, not at month-end.
 */
enum PettyCashCategory: string
{
    case Material = 'material';
    case Upah = 'upah';
    case BbmTol = 'bbm_tol';
    case Konsumsi = 'konsumsi';
    case AlatBantu = 'alat_bantu';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Upah => 'Upah Harian',
            self::BbmTol => 'BBM & Tol',
            self::Konsumsi => 'Konsumsi',
            self::AlatBantu => 'Alat Bantu',
            self::Lainnya => 'Lain-lain',
        };
    }

    /**
     * HPP (5-xxxx) leaf for a project-attributed bon. BBM, konsumsi and
     * lain-lain are site overhead — they are project cost, just not one of the
     * direct buckets.
     */
    public function cogsAccountCode(): string
    {
        return match ($this) {
            self::Material => '5-1100',
            self::Upah => '5-1200',
            self::AlatBantu => '5-1400',
            self::BbmTol, self::Konsumsi, self::Lainnya => '5-1500',
        };
    }

    /**
     * Opex (6-xxxx) leaf for an office bon. BBM/tol without a project is
     * ordinary travel; everything else falls to umum & administrasi except
     * daily wages, which belong with the salary expense they top up.
     */
    public function opexAccountCode(): string
    {
        return match ($this) {
            self::Upah => '6-1100',
            self::BbmTol => '6-4300',
            self::Material, self::Konsumsi, self::AlatBantu, self::Lainnya => '6-4100',
        };
    }

    /**
     * RAP bucket for fin_project_costs — value-compatible with Estimation's
     * categories, same as CostCategory itself.
     */
    public function costCategory(): CostCategory
    {
        return match ($this) {
            self::Material => CostCategory::Material,
            self::Upah => CostCategory::Labor,
            self::AlatBantu => CostCategory::Equipment,
            self::BbmTol, self::Konsumsi, self::Lainnya => CostCategory::Overhead,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
