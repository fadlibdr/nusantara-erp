<?php

namespace Modules\ServiceDesk\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case PendingCustomer = 'pending_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Assigned => 'Ditugaskan',
            self::InProgress => 'Dikerjakan',
            self::PendingCustomer => 'Menunggu Pelanggan',
            self::Resolved => 'Terselesaikan',
            self::Closed => 'Ditutup',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Assigned, self::InProgress, self::Resolved, self::Cancelled],
            self::Assigned => [self::Assigned, self::InProgress, self::PendingCustomer, self::Resolved, self::Cancelled],
            self::InProgress => [self::Assigned, self::PendingCustomer, self::Resolved, self::Cancelled],
            self::PendingCustomer => [self::InProgress, self::Resolved, self::Cancelled],
            self::Resolved => [self::InProgress, self::Closed], // reopen or close
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
