<?php

namespace Modules\Projects\Enums;

/**
 * Tingkat keparahan kecelakaan kerja.
 *
 * Ordered by outcome, from the event that hurt nobody to the one that killed
 * somebody. The order is the point: a near miss is on the same scale as a
 * fatality because it is the same event with better luck, and a register that
 * only records injuries is a register that learns nothing until somebody is hurt.
 *
 * Recordability follows the international convention (ILO / OSHA lost-time
 * definitions), which Indonesian contractor HSE reporting has adopted: anything
 * beyond on-site first aid counts against the frequency rate.
 */
enum IncidentSeverity: string
{
    case NearMiss = 'near_miss';
    case FirstAid = 'first_aid';
    case MedicalTreatment = 'medical_treatment';
    case LostTime = 'lost_time';
    case Fatality = 'fatality';

    public function label(): string
    {
        return match ($this) {
            self::NearMiss => 'Nyaris celaka (near miss)',
            self::FirstAid => 'P3K',
            self::MedicalTreatment => 'Perawatan medis',
            self::LostTime => 'Kehilangan hari kerja',
            self::Fatality => 'Fatal',
        };
    }

    /**
     * Whether the event counts in the frequency rate.
     *
     * A near miss does not — nobody was hurt, so counting it would make a site
     * that reports honestly look worse than one that reports nothing. First aid
     * does not either, by the same convention. Both are still recorded, and are
     * the most useful rows in the register.
     */
    public function isRecordable(): bool
    {
        return match ($this) {
            self::NearMiss, self::FirstAid => false,
            default => true,
        };
    }
}
