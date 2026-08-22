<?php

namespace Modules\Crm\Enums;

enum GuaranteeType: string
{
    case BidBond = 'bid_bond';
    case PerformanceBond = 'performance_bond';
    case AdvancePaymentBond = 'advance_payment_bond';
    case MaintenanceBond = 'maintenance_bond';
    case Car = 'car';
    case Tpl = 'tpl';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::BidBond => 'Jaminan Penawaran',
            self::PerformanceBond => 'Jaminan Pelaksanaan',
            self::AdvancePaymentBond => 'Jaminan Uang Muka',
            self::MaintenanceBond => 'Jaminan Pemeliharaan',
            self::Car => 'Asuransi CAR',
            self::Tpl => 'Asuransi TPL',
            self::Lainnya => 'Lainnya',
        };
    }
}
