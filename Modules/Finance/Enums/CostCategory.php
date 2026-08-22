<?php

namespace Modules\Finance\Enums;

/**
 * Project cost buckets — value-compatible with Estimation's RAP categories so
 * realisasi (fin_project_costs) lines up against budget (est_cost_budget_items).
 */
enum CostCategory: string
{
    case Material = 'material';
    case Labor = 'labor';
    case Subcon = 'subcon';
    case Equipment = 'equipment';
    case Overhead = 'overhead';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Labor => 'Upah',
            self::Subcon => 'Subkon',
            self::Equipment => 'Alat',
            self::Overhead => 'Overhead',
        };
    }

    /**
     * COA project-cost (HPP) account per category, 5-xxxx block.
     */
    public function cogsAccountCode(): string
    {
        return match ($this) {
            self::Material => '5-1100',
            self::Labor => '5-1200',
            self::Subcon => '5-1300',
            self::Equipment => '5-1400',
            self::Overhead => '5-1500',
        };
    }
}
