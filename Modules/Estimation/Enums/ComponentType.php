<?php

namespace Modules\Estimation\Enums;

enum ComponentType: string
{
    case Labor = 'labor';
    case Material = 'material';
    case Equipment = 'equipment';

    public function label(): string
    {
        return match ($this) {
            self::Labor => 'Upah',
            self::Material => 'Bahan',
            self::Equipment => 'Alat',
        };
    }

    /**
     * The RAP cost category this component type maps onto when a BOQ item
     * budget is split by its AHSP component mix.
     */
    public function costCategory(): CostCategory
    {
        return match ($this) {
            self::Labor => CostCategory::Labor,
            self::Material => CostCategory::Material,
            self::Equipment => CostCategory::Equipment,
        };
    }
}
