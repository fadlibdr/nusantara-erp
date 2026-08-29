<?php

namespace Modules\Core\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Events\DocumentTransitioned;
use Modules\Core\Models\Approval;
use Modules\Core\Support\ApprovalLevels;
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

        // A document that opts into the n-level ladder (P2) needs several
        // distinct approvers and flips to Approved only at the last of them —
        // everything else keeps the single-approval lifecycle unchanged.
        if ($this->requiredApprovalLevels() > 1) {
            return $this->approveLevelled($by, $note);
        }

        $this->forceFill(['status' => DocumentStatus::Approved])->save();
        $this->recordApproval('approved', $by, $note);

        return $this;
    }

    /**
     * The n-level path. Each distinct approver records one 'approved' row; the
     * document stays Submitted until the required number of DISTINCT approvers
     * is reached, and only the completing approval announces the transition —
     * an intermediate level is a real approval in the audit trail but is not
     * yet an approved document, so it must not notify the submitter that it is.
     *
     * ApprovalLevels enforces the two extra rules on top of maker-checker: a
     * person cannot supply two of the distinct approvals, and levels 2+ demand
     * the module's director permission.
     */
    protected function approveLevelled(User $by, ?string $note): static
    {
        $prior = ApprovalLevels::distinctApprovals($this);
        ApprovalLevels::assertMayApproveNext($this, $by, $prior);

        $isFinal = ($prior + 1) >= $this->requiredApprovalLevels();

        if ($isFinal) {
            $this->forceFill(['status' => DocumentStatus::Approved])->save();
            $this->recordApproval('approved', $by, $note); // row + DocumentTransitioned

            return $this;
        }

        // Intermediate level: record the approval (it counts toward the ladder)
        // but stay Submitted and stay quiet.
        $this->approvals()->create([
            'action' => 'approved',
            'user_id' => $by->id,
            'note' => $note,
        ]);

        return $this;
    }

    /**
     * The ladder key a document opts into, or null for the single-approval
     * default every existing Approvable keeps. A model returning a key must
     * also override approvalAmount() so the ladder has an amount to resolve.
     */
    public function approvalLadderKey(): ?string
    {
        return null;
    }

    /** The signed amount an amount-tiered ladder is resolved against. */
    public function approvalAmount(): float
    {
        return 0.0;
    }

    /** How many distinct approvers this document needs (1 unless a ladder says more). */
    public function requiredApprovalLevels(): int
    {
        $key = $this->approvalLadderKey();

        if ($key === null) {
            return 1;
        }

        return ApprovalLevels::forAmount($key, $this->approvalAmount());
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
