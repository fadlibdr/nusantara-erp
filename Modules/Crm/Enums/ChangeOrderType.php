<?php

namespace Modules\Crm\Enums;

/**
 * Jenis perubahan kontrak (CCO).
 *
 * Both kinds move the contract value through the same CCO path — what they
 * differ in is MEANING on the audit trail. A price escalation (BPS-index
 * clause on a multi-year contract) recorded as "pekerjaan tambah" reads to an
 * auditor as scope that was never actually added; this enum is what stops
 * that disguise. There is no formula engine behind EskalasiHarga — the amount
 * is computed outside and enters as an ordinary value_change.
 */
enum ChangeOrderType: string
{
    case TambahKurang = 'tambah_kurang';
    case EskalasiHarga = 'eskalasi_harga';

    public function label(): string
    {
        return match ($this) {
            self::TambahKurang => 'Tambah-Kurang',
            self::EskalasiHarga => 'Eskalasi Harga',
        };
    }
}
