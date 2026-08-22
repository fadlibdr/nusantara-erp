<?php

namespace Modules\Projects\Enums;

/**
 * Where one punch-list item is in its repair.
 *
 * Deliberately NOT DocumentStatus, for the same reason IncidentStatus is not: a
 * defect is not approved into existence — somebody found it. What it needs is a
 * repair and a customer who accepts the repair, which is a different lifecycle
 * with a different meaning for every state.
 *
 * `waived` is the terminal side-exit and it carries real contractual weight: the
 * customer looked at the item and accepted it as is (dispensasi). Without that
 * exit the BAST II block on open critical/major items would be unsatisfiable on
 * a job where the customer simply does not want a wall re-painted, and people
 * would delete rows to get past it — which is worse than having no register.
 */
enum DefectStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case ReadyForReview = 'ready_for_review';
    case Closed = 'closed';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::InProgress => 'Perbaikan berjalan',
            self::ReadyForReview => 'Menunggu verifikasi',
            self::Closed => 'Selesai (terverifikasi)',
            self::Waived => 'Dispensasi pelanggan',
        };
    }

    /**
     * Still on the punch list.
     *
     * `ready_for_review` counts as OPEN, and that is the load-bearing decision
     * here: BAST II *is* the customer's acceptance, so an item that merely
     * claims to be fixed has not been accepted by anybody yet. Treating it as
     * closed would reproduce the exact hole the register was built to close.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Closed, self::Waived => true,
            default => false,
        };
    }
}
