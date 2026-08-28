<?php

namespace Modules\Core\Enums;

/**
 * Tiga keputusan yang bisa diambil MK/Owner dari tautan sekali-pakai atau
 * lembar fisik (keputusan pemilik #1). SENGAJA bukan DocumentStatus: sebuah
 * keputusan eksternal adalah BUKTI yang tercatat, bukan siklus hidup dokumen
 * — pada mode record dokumen tidak bergerak sama sekali, dan pada mode
 * transisi pergerakannya tetap milik Approvable lewat adapter service.
 *
 * "Setuju dengan catatan" adalah nilai sendiri, bukan approved + catatan
 * terisi: di lapangan keduanya berbeda makna (yang pertama membebankan
 * kewajiban perbaikan), dan lembar fisik MK memang punya tiga stempel.
 */
enum ExternalDecision: string
{
    case Approved = 'approved';
    case ApprovedWithNotes = 'approved_with_notes';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Setuju',
            self::ApprovedWithNotes => 'Setuju dengan catatan',
            self::Rejected => 'Tolak',
        };
    }

    /** Kedua varian setuju menggerakkan transisi APPROVE pada mode transisi. */
    public function isApproval(): bool
    {
        return $this !== self::Rejected;
    }
}
