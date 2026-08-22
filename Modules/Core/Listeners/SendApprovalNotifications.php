<?php

namespace Modules\Core\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Core\Events\DocumentTransitioned;
use Modules\Core\Services\NotificationService;

/**
 * Runs AFTER the surrounding transaction commits.
 *
 * The event fires from inside approval flows that are still writing to the
 * ledger. Without this, an approval that is rolled back a moment later — a
 * closed fiscal period, an unbalanced journal — has already told the submitter
 * it was approved, and email cannot be un-sent. The in-app row would roll back
 * with the transaction; the mail would not, which is the worst of both.
 */
class SendApprovalNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(DocumentTransitioned $event): void
    {
        match ($event->action) {
            'submitted' => $this->notifications->documentSubmitted($event->document, $event->actor),
            'approved', 'rejected' => $this->notifications->documentDecided(
                $event->document,
                $event->action,
                $event->actor,
                $event->note,
            ),
            default => null,
        };
    }
}
