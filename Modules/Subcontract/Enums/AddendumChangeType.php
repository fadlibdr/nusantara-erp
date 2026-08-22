<?php

namespace Modules\Subcontract\Enums;

/**
 * Jenis addendum SPK.
 *
 * Same two kinds as Crm's ChangeOrderType, present from day one because Crm
 * had to retrofit it (temuan #61): a price escalation recorded as "pekerjaan
 * tambah" reads to an auditor as scope that was never actually added. Both
 * kinds move the SPK value through the same addendum path — what they differ
 * in is MEANING on the audit trail. There is no formula engine behind
 * EskalasiHarga; the amount is computed outside and enters as an ordinary
 * value_change.
 *
 * Deliberately Subcontract's own enum rather than an import of
 * Modules\Crm\Enums\ChangeOrderType: an SPK addendum must not stop existing
 * because the Crm module does.
 */
enum AddendumChangeType: string
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
