<?php

namespace Modules\Procurement\Enums;

/**
 * Jenis dokumen legal/prakualifikasi vendor. Satu daftar untuk dua temuan:
 * legalitas ber-masa-berlaku (#69) dan prakualifikasi PO/SPK (#35) membaca
 * register yang sama — jenisnya yang membedakan, bukan tabelnya.
 */
enum VendorDocumentType: string
{
    case Nib = 'nib';
    case Siup = 'siup';
    case Npwp = 'npwp';
    case Sppkp = 'sppkp';
    case SbuKonstruksi = 'sbu_konstruksi';
    case Skk = 'skk';
    case Principal = 'principal';
    case Akta = 'akta';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Nib => 'NIB',
            self::Siup => 'SIUP',
            self::Npwp => 'NPWP',
            self::Sppkp => 'SPPKP (PKP)',
            self::SbuKonstruksi => 'SBU Konstruksi',
            self::Skk => 'SKK Penanggung Jawab',
            self::Principal => 'Sertifikat Principal',
            self::Akta => 'Akta Perusahaan',
            self::Lainnya => 'Lainnya',
        };
    }
}
