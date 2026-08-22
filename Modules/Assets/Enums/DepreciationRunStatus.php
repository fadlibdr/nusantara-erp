<?php

namespace Modules\Assets\Enums;

enum DepreciationRunStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Posted => 'Diposting',
        };
    }
}
