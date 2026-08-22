<?php

namespace Modules\HrPayroll\Enums;

/**
 * PP 35/2021 recognises two lawful PKWT shapes: berdasarkan jangka waktu
 * (calendar end date, maximum 5 tahun including perpanjangan — Pasal 8) and
 * berdasarkan selesainya suatu pekerjaan tertentu (no calendar end date; the
 * agreement records an ESTIMATE of completion — Pasal 9). Collapsing both
 * into a single pkwt_end_date column would force HR to either invent a fake
 * date for a completion-based crew or eat a permanent missing-date nag.
 */
enum PkwtBasis: string
{
    case JangkaWaktu = 'jangka_waktu';
    case SelesainyaPekerjaan = 'selesainya_pekerjaan';

    public function label(): string
    {
        return match ($this) {
            self::JangkaWaktu => 'Jangka waktu tertentu',
            self::SelesainyaPekerjaan => 'Selesainya pekerjaan tertentu',
        };
    }
}
