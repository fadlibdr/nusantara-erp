<?php

namespace Modules\Projects\Enums;

/**
 * Tingkat keparahan temuan (punch list).
 *
 * The scale exists to answer one question at the handover table: does this item
 * stop the serah terima or does it go on the snagging note? Sisa cat dan sealant
 * are signed off with a note every day in this industry; a lift that does not
 * level or a panel yang bocor is not.
 */
enum DefectSeverity: string
{
    case Critical = 'critical';
    case Major = 'major';
    case Minor = 'minor';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Kritis (menghentikan fungsi)',
            self::Major => 'Mayor',
            self::Minor => 'Minor (snagging)',
        };
    }

    /**
     * Whether an item at this severity blocks BAST II.
     *
     * The rule lives on the enum rather than in the gate — mirroring
     * IncidentSeverity::isRecordable() — so the checklist, the summary counts
     * and the API resource all read the same sentence instead of three copies
     * of `in_array(['critical','major'])` that can drift apart.
     */
    public function blocksHandover(): bool
    {
        return match ($this) {
            self::Critical, self::Major => true,
            self::Minor => false,
        };
    }
}
