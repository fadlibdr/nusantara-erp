<?php

namespace Modules\Finance\Enums;

enum PaymentDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Penerimaan',
            self::Out => 'Pengeluaran',
        };
    }

    /**
     * Document number type used by fin_payments per direction.
     */
    public function documentType(): string
    {
        return match ($this) {
            self::In => 'RCV',
            self::Out => 'PAY',
        };
    }
}
