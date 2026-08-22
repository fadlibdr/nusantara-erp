<?php

namespace Tests\Unit\Core;

use App\Models\User;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Approval;
use Tests\ErpTestCase;
use Tests\Unit\Core\Fixtures\ApprovableDocument;
use Tests\Unit\Core\Fixtures\TestDocumentSchema;

/**
 * The document lifecycle every approvable ERP document shares:
 * draft -> submitted -> approved | rejected, with rejected able to go back to
 * submitted. Any other move is a business-rule breach (LogicException) and must
 * leave the stored status exactly as it was.
 *
 * Approving is a TWO-PERSON act: the trait refuses an approval by whoever
 * submitted the document, so every happy path below submits as one user and
 * approves as another. The guard's own edge cases — the exemption setting, a
 * submission with no actor, resubmission by the rejector — live in
 * Tests\Unit\Core\SegregationOfDutiesTest.
 */
class ApprovableTest extends ErpTestCase
{
    use TestDocumentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestDocumentTable();
    }

    private function makeUser(string $name = 'Manajer Proyek'): User
    {
        return User::query()->firstOrCreate(
            ['email' => str($name)->slug().'@test.local'],
            ['name' => $name, 'password' => 'password', 'is_active' => true],
        );
    }

    /** The maker: the person who prepares a document and clicks Ajukan. */
    private function submitter(): User
    {
        return $this->makeUser('Site Engineer');
    }

    /** The checker, who may never be the maker. */
    private function approver(): User
    {
        return $this->makeUser('Direktur');
    }

    private function makeDocument(DocumentStatus $status = DocumentStatus::Draft): ApprovableDocument
    {
        return ApprovableDocument::query()->create([
            'code' => 'DOC/2026/VII/0001',
            'title' => 'Dokumen uji',
            'status' => $status,
        ]);
    }

    public function test_a_draft_can_be_submitted_then_approved(): void
    {
        $document = $this->makeDocument();

        $document->submit($this->submitter());
        $this->assertSame(DocumentStatus::Submitted, $document->fresh()->status);

        $document->approve($this->approver(), 'Anggaran tersedia.');
        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_a_submitted_document_can_be_rejected(): void
    {
        $user = $this->makeUser();
        $document = $this->makeDocument();

        $document->submit($user);
        $document->reject($user, 'Harga di atas RAP.');

        $this->assertSame(DocumentStatus::Rejected, $document->fresh()->status);
    }

    public function test_a_rejected_document_can_be_resubmitted(): void
    {
        $user = $this->makeUser();
        $document = $this->makeDocument();

        $document->submit($user);
        $document->reject($user, 'Harga di atas RAP.');
        $document->submit($user);

        $this->assertSame(DocumentStatus::Submitted, $document->fresh()->status);
        // submitted, rejected, submitted again.
        $this->assertSame(3, $document->approvals()->count());
    }

    public function test_a_resubmitted_document_can_then_be_approved(): void
    {
        $submitter = $this->submitter();
        $document = $this->makeDocument();

        $document->submit($submitter);
        $document->reject($this->approver());
        $document->submit($submitter);
        $document->approve($this->approver());

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_approving_a_draft_throws_and_leaves_the_status_untouched(): void
    {
        $user = $this->makeUser();
        $document = $this->makeDocument();

        try {
            $document->approve($user);
            $this->fail('Approving a draft should have thrown a LogicException.');
        } catch (LogicException $e) {
            $this->assertSame(
                'Cannot approve document DOC/2026/VII/0001 while status is draft.',
                $e->getMessage(),
            );
        }

        $this->assertSame(DocumentStatus::Draft, $document->fresh()->status);
        $this->assertDatabaseHas('test_documents', ['id' => $document->id, 'status' => 'draft']);
        $this->assertSame(0, Approval::query()->count());
    }

    public function test_rejecting_an_approved_document_throws_and_leaves_the_status_untouched(): void
    {
        $document = $this->makeDocument();
        $document->submit($this->submitter());
        $document->approve($this->approver());

        try {
            $document->reject($this->approver(), 'Berubah pikiran.');
            $this->fail('Rejecting an approved document should have thrown a LogicException.');
        } catch (LogicException $e) {
            $this->assertSame(
                'Cannot reject document DOC/2026/VII/0001 while status is approved.',
                $e->getMessage(),
            );
        }

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
        // Only the submit and the approve were recorded.
        $this->assertSame(2, $document->approvals()->count());
    }

    public function test_submitting_an_approved_document_throws_and_leaves_the_status_untouched(): void
    {
        $document = $this->makeDocument();
        $document->submit($this->submitter());
        $document->approve($this->approver());

        $this->expectException(LogicException::class);

        try {
            $document->submit($this->submitter());
        } finally {
            $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
            $this->assertSame(2, $document->approvals()->count());
        }
    }

    public function test_approving_an_already_approved_document_throws(): void
    {
        $document = $this->makeDocument();
        $document->submit($this->submitter());
        $document->approve($this->approver());

        $this->expectException(LogicException::class);

        $document->approve($this->approver());
    }

    public function test_a_cancelled_document_cannot_re_enter_the_flow(): void
    {
        $user = $this->makeUser();
        $document = $this->makeDocument(DocumentStatus::Cancelled);

        try {
            $document->submit($user);
            $this->fail('Submitting a cancelled document should have thrown a LogicException.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('while status is cancelled', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Cancelled, $document->fresh()->status);
    }

    public function test_every_transition_records_the_actor_and_the_note(): void
    {
        $submitter = $this->makeUser('Site Engineer');
        $approver = $this->makeUser('Direktur');
        $document = $this->makeDocument();

        $document->submit($submitter);
        $document->approve($approver, 'Sesuai anggaran.');

        $approvals = $document->approvals()->orderBy('id')->get();

        $this->assertSame(2, $approvals->count());

        $this->assertSame('submitted', $approvals[0]->action);
        $this->assertSame($submitter->id, $approvals[0]->user_id);
        $this->assertNull($approvals[0]->note);

        $this->assertSame('approved', $approvals[1]->action);
        $this->assertSame($approver->id, $approvals[1]->user_id);
        $this->assertSame('Sesuai anggaran.', $approvals[1]->note);
    }

    public function test_a_rejection_records_its_reason(): void
    {
        $user = $this->makeUser();
        $document = $this->makeDocument();
        $document->submit($user);

        $document->reject($user, 'Vendor belum terdaftar.');

        $this->assertDatabaseHas('core_approvals', [
            'approvable_type' => ApprovableDocument::class,
            'approvable_id' => $document->id,
            'action' => 'rejected',
            'user_id' => $user->id,
            'note' => 'Vendor belum terdaftar.',
        ]);
    }

    public function test_a_submission_without_an_actor_records_a_null_user(): void
    {
        $document = $this->makeDocument();

        $document->submit();

        $this->assertDatabaseHas('core_approvals', [
            'approvable_id' => $document->id,
            'action' => 'submitted',
            'user_id' => null,
        ]);
    }

    public function test_the_approval_trail_is_scoped_to_its_own_document(): void
    {
        $submitter = $this->submitter();
        $first = $this->makeDocument();
        $second = $this->makeDocument();

        $first->submit($submitter);
        $first->approve($this->approver());
        $second->submit($submitter);

        $this->assertSame(2, $first->approvals()->count());
        $this->assertSame(1, $second->approvals()->count());
        $this->assertSame(3, Approval::query()->count());
    }

    public function test_the_transition_methods_return_the_document_for_chaining(): void
    {
        $document = $this->makeDocument();

        $returned = $document->submit($this->submitter())->approve($this->approver());

        $this->assertSame($document, $returned);
        $this->assertSame(DocumentStatus::Approved, $returned->status);
    }
}
