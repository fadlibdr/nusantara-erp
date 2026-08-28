<?php

namespace Modules\Crm\Enums;

/**
 * Jenis perubahan kontrak (CCO).
 *
 * TambahKurang and EskalasiHarga both move the contract value through the same
 * CCO path — what they differ in is MEANING on the audit trail. A price
 * escalation (BPS-index clause on a multi-year contract) recorded as
 * "pekerjaan tambah" reads to an auditor as scope that was never actually
 * added; this enum is what stops that disguise. There is no formula engine
 * behind EskalasiHarga — the amount is computed outside and enters as an
 * ordinary value_change.
 *
 * Waktu (P0-B) moves the contract's END DATE and nothing else: value_change
 * is required to be exactly 0, days_change carries the shift (signed — a
 * pengurangan waktu is as real as a perpanjangan), and new_end_date is
 * computed by the service at approval, never input. Time and money never move
 * on the same sheet; a change that does both is two change orders.
 */
enum ChangeOrderType: string
{
    case TambahKurang = 'tambah_kurang';
    case EskalasiHarga = 'eskalasi_harga';
    case Waktu = 'waktu';

    public function label(): string
    {
        return match ($this) {
            self::TambahKurang => 'Tambah-Kurang',
            self::EskalasiHarga => 'Eskalasi Harga',
            self::Waktu => 'Addendum Waktu',
        };
    }
}
