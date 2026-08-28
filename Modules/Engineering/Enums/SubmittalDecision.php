<?php

namespace Modules\Engineering\Enums;

/**
 * The four MK decision stamps of the FM-10 "Stempel" sheet — exactly four, in
 * the order they appear on the stamp. These are FACTS typed in from the sheet
 * the MK returned, never an internal workflow state; DocumentStatus is
 * deliberately not reused here because a submittal's own lifecycle (waiting /
 * decided / superseded) is derived, not stored.
 */
enum SubmittalDecision: string
{
    case Approved = 'approved';
    case ApprovedAsNoted = 'approved_as_noted';
    case ReviseResubmit = 'revise_resubmit';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Disetujui',
            self::ApprovedAsNoted => 'Disetujui dengan catatan',
            self::ReviseResubmit => 'Revisi & ajukan ulang',
            self::Rejected => 'Ditolak',
        };
    }

    /**
     * The two stamps that authorise building from a DRAWING. "Approved as
     * noted" opens the gate by definition — the FM-10 stamp's own text is
     * "proceed incorporating the notes", instructions to the builder.
     *
     * The IPP gate does NOT use this for material lines: the spec is
     * asymmetric there ("belum approved" — IppService::submit), because a
     * material's notes change what may arrive on site, not how a sheet is
     * read, so only a clean approval releases the material.
     */
    public function permitsWork(): bool
    {
        return $this === self::Approved || $this === self::ApprovedAsNoted;
    }
}
