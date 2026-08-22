<?php

namespace Modules\Inventory\Enums;

/**
 * Stock leaves the source warehouse on send (in_transit) and arrives at the
 * destination on receive, so goods on the road are visible in neither balance.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::InTransit => 'Dalam Perjalanan',
            self::Received => 'Diterima',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
