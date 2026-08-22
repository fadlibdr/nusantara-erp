<?php

namespace Modules\Core\Support;

class Money
{
    /**
     * Money::format(48500000) => "Rp 48.500.000,00"
     */
    public static function format(int|float|string $value, bool $withDecimals = true): string
    {
        return 'Rp '.number_format((float) $value, $withDecimals ? 2 : 0, ',', '.');
    }
}
