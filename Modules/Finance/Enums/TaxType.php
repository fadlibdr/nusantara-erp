<?php

namespace Modules\Finance\Enums;

enum TaxType: string
{
    case Ppn = 'ppn';
    case PphWithholding = 'pph_withholding';

    public function label(): string
    {
        return match ($this) {
            self::Ppn => 'PPN',
            self::PphWithholding => 'PPh (dipotong/dipungut)',
        };
    }
}
