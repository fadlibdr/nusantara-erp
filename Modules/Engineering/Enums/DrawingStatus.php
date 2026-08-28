<?php

namespace Modules\Engineering\Enums;

/**
 * Status baris register gambar (FM-10-01/21) — a MIRROR, never typed by hand:
 * DrawingSubmittalService moves it when a submittal is created or its MK
 * decision is recorded. The four decided values are string-identical to
 * SubmittalDecision on purpose, so the register column and the stamp column
 * can never say two different words for the same fact.
 */
enum DrawingStatus: string
{
    case BelumDiajukan = 'belum_diajukan';
    case Diajukan = 'diajukan';
    case Approved = 'approved';
    case ApprovedAsNoted = 'approved_as_noted';
    case ReviseResubmit = 'revise_resubmit';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::BelumDiajukan => 'Belum diajukan',
            self::Diajukan => 'Diajukan',
            self::Approved => 'Disetujui',
            self::ApprovedAsNoted => 'Disetujui dengan catatan',
            self::ReviseResubmit => 'Revisi & ajukan ulang',
            self::Rejected => 'Ditolak',
        };
    }
}
