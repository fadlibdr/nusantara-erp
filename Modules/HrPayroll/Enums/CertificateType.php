<?php

namespace Modules\HrPayroll\Enums;

enum CertificateType: string
{
    case Skk = 'skk';
    case K3 = 'k3';
    case Principal = 'principal';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Skk => 'SKK Konstruksi',
            self::K3 => 'Sertifikat K3/AK3',
            self::Principal => 'Sertifikasi Principal',
            self::Lainnya => 'Lainnya',
        };
    }
}
