<?php

namespace Modules\Procurement\Enums;

/**
 * Jenis vendor (P4). Menggantikan boolean is_subcontractor yang hanya bisa
 * menyebut dua dari empat hal yang bisa menjadi vendor: pemasok material,
 * subkontraktor, mandor borongan (SP3/upah — P4), dan penyedia sewa alat
 * (PPK — P5).
 *
 * Kolom lama is_subcontractor DIPERTAHANKAN dan tetap benar — model Vendor
 * menyinkronkan keduanya di satu hook — karena 18 pembaca lama (PHP + SPA)
 * masih membacanya. Kode baru membaca vendor_type.
 */
enum VendorType: string
{
    case Supplier = 'supplier';
    case Subcontractor = 'subcontractor';
    case Mandor = 'mandor';
    case Rental = 'rental';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Pemasok',
            self::Subcontractor => 'Subkontraktor',
            self::Mandor => 'Mandor',
            self::Rental => 'Rental / sewa alat',
        };
    }

    /**
     * Vendor yang mengirim pekerjanya ke site — penyempitan K3L/pakta P0-E
     * berlaku untuk mereka. Lihat VendorQualificationService::blockers untuk
     * keputusan mandor yang didokumentasikan.
     */
    public function sendsWorkersToSite(): bool
    {
        return $this === self::Subcontractor || $this === self::Mandor;
    }
}
