<?php

namespace Modules\Core\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Events\DocumentTransitioned;
use Modules\Core\Models\Approval;
use Modules\Core\Support\SegregationOfDuties;

/**
 * Draft -> submitted -> approved/rejected lifecycle for documents.
 * The model needs a `status` column cast to DocumentStatus.
 */
trait Approvable
{
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function submit(?User $by = null): static
    {
        $this->assertStatus([DocumentStatus::Draft, DocumentStatus::Rejected], 'submit');

        $this->forceFill(['status' => DocumentStatus::Submitted])->save();
        $this->recordApproval('submitted', $by);

        return $this;
    }

    /**
     * MAKER-CHECKER. Whoever submitted this document cannot be the one who
     * approves it — otherwise a single finance login raises a fictitious vendor
     * bill, approves it and pays it in one sitting, and the approval trail it
     * leaves behind is indistinguishable from a real one.
     *
     * The guard runs AFTER assertStatus deliberately. A draft must still be
     * refused with "while status is draft", which is both the more fundamental
     * error and the message several suites assert verbatim.
     *
     * reject() is NOT guarded, and that is not an oversight: rejecting your own
     * document returns it to your own desk, moves no money and asserts nothing.
     * Guarding it would strand documents whenever the second approver is away.
     */
    public function approve(User $by, ?string $note = null): static
    {
        $this->assertStatus([DocumentStatus::Submitted], 'approve');
        SegregationOfDuties::assertNotSubmitter($this, $by);

        $this->forceFill(['status' => DocumentStatus::Approved])->save();
        $this->recordApproval('approved', $by, $note);

        return $this;
    }

    public function reject(User $by, ?string $note = null): static
    {
        $this->assertStatus([DocumentStatus::Submitted], 'reject');

        $this->forceFill(['status' => DocumentStatus::Rejected])->save();
        $this->recordApproval('rejected', $by, $note);

        return $this;
    }

    /**
     * Records the transition and announces it. The announcement is an event
     * rather than a direct call so this trait — which twelve document models
     * use — stays ignorant of who wants to hear about it.
     */
    protected function recordApproval(string $action, ?User $by, ?string $note = null): void
    {
        $this->approvals()->create([
            'action' => $action,
            'user_id' => $by?->id,
            'note' => $note,
        ]);

        DocumentTransitioned::dispatch($this, $action, $by, $note);
    }

    protected function assertStatus(array $allowed, string $action): void
    {
        $current = $this->status instanceof DocumentStatus
            ? $this->status
            : DocumentStatus::from((string) $this->status);

        if (! in_array($current, $allowed, true)) {
            throw new LogicException(
                "Cannot {$action} document {$this->code} while status is {$current->value}."
            );
        }
    }
}
