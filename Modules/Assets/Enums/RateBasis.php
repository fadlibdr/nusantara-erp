<?php

namespace Modules\Assets\Enums;

/**
 * P5 — basis tarif sewa alat/jasa. Dipakai dua tempat dengan satu sumber:
 * ast_assets.rate_basis (tarif master alat sewa) dan
 * prc_work_order_items.rate_basis (baris PPK) — Procurement membaca enum
 * Assets, arah dependensi yang sama dengan Subcontract yang membaca
 * Procurement\Enums\VendorType.
 *
 * per_jam ditagih dari delta hour-meter register (ast_equipment_logs);
 * per_bulan dan per_hari_8jam ditagih dari kalender.
 */
enum RateBasis: string
{
    case PerBulan = 'per_bulan';
    case PerHari8Jam = 'per_hari_8jam';
    case PerJam = 'per_jam';

    public function label(): string
    {
        return match ($this) {
            self::PerBulan => 'Per bulan',
            self::PerHari8Jam => 'Per hari (8 jam)',
            self::PerJam => 'Per jam',
        };
    }

    /** Satuan kuantitas yang dihitung basis ini pada tagihan periode. */
    public function unit(): string
    {
        return match ($this) {
            self::PerBulan => 'bulan',
            self::PerHari8Jam => 'hari',
            self::PerJam => 'jam',
        };
    }
}
