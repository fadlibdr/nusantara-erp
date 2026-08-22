<?php

namespace Modules\Crm\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Proposal = 'proposal';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Baru',
            self::Contacted => 'Sudah Dihubungi',
            self::Qualified => 'Terkualifikasi',
            self::Proposal => 'Penawaran Dikirim',
            self::Won => 'Menang',
            self::Lost => 'Kalah',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Won, self::Lost], true);
    }
}
