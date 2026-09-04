<?php

namespace Tests\Unit\Core;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Core\Models\Approval;
use Modules\Core\Support\SegregationOfDuties;
use Tests\ErpTestCase;
use Tests\Unit\Core\Fixtures\ApprovableDocument;
use Tests\Unit\Core\Fixtures\TestDocumentSchema;

/**
 * Maker-checker, against the throwaway document rather than a module model, so
 * a failure here points at the guard and nothing else.
 *
 * The concrete failure it closes: one finance login raising BIL/2026/III/0001
 * for Rp 232.545.000 to a vendor of its own choosing, approving it, and paying
 * it — with an approval trail afterwards that reads exactly like a real one.
 */
class SegregationOfDutiesTest extends ErpTestCase
{
    use TestDocumentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestDocumentTable();
    }

    private function makeUser(string $name): User
    {
        return User::query()->firstOrCreate(
            ['email' => str($name)->slug().'@test.local'],
            ['name' => $name, 'password' => 'password', 'is_active' => true],
        );
    }

    private function makeDocument(): ApprovableDocument
    {
        return ApprovableDocument::query()->create([
            'code' => 'DOC/2026/VII/0001',
            'title' => 'Dokumen uji',
            'status' => DocumentStatus::Draft,
        ]);
    }

    // ------------------------------------------------------------- the refusal

    public function test_the_submitter_cannot_approve_their_own_document(): void
    {
        $dewi = $this->makeUser('Dewi Lestari');
        $document = $this->makeDocument();
        $document->submit($dewi);

        $this->expectException(SelfApprovalException::class);

        $document->approve($dewi);
    }

    public function test_a_refused_self_approval_leaves_the_document_submitted_with_no_extra_trail(): void
    {
        $dewi = $this->makeUser('Dewi Lestari');
        $document = $this->makeDocument();
        $document->submit($dewi);

        try {
            $document->approve($dewi, 'Saya setujui sendiri.');
            $this->fail('A self-approval must be refused.');
        } catch (SelfApprovalException) {
            // asserted below
        }

        $this->assertSame(DocumentStatus::Submitted, $document->fresh()->status);
        // Only the submission. A refused approval must not leave a footprint
        // that later reads like an approval that happened.
        $this->assertSame(['submitted'], $document->approvals()->orderBy('id')->pluck('action')->all());
        $this->assertSame(1, Approval::query()->count());
    }

    public function test_the_refusal_names_the_submitter_the_document_and_the_way_out(): void
    {
        $dewi = $this->makeUser('Dewi Lestari');
        $document = $this->makeDocument();
        $document->submit($dewi);

        try {
            $document->approve($dewi);
            $this->fail('A self-approval must be refused.');
        } catch (SelfApprovalException $e) {
            $message = $e->getMessage();
        }

        // An operator who hits this has to know WHO to go and find.
        $this->assertStringContainsString('Dewi Lestari', $message);
        $this->assertStringContainsString('DOC/2026/VII/0001', $message);
        $this->assertStringContainsString('tidak boleh disetujui oleh pengajunya sendiri', $message);
        $this->assertStringContainsString('Pengaturan → Proyek & Persetujuan', $message);
    }

    /**
     * The fixture is deliberately absent from ApprovableDocuments, which is the
     * only case where the registry has nothing to say. It must still produce a
     * sentence, not a "Dokumen  diajukan oleh" with a hole in it.
     */
    public function test_a_document_outside_the_registry_still_gets_a_readable_refusal(): void
    {
        $dewi = $this->makeUser('Dewi Lestari');
        $document = $this->makeDocument();
        $document->submit($dewi);

        try {
            $document->approve($dewi);
            $this->fail('A self-approval must be refused.');
        } catch (SelfApprovalException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringStartsWith('Dokumen DOC/2026/VII/0001 diajukan oleh Dewi Lestari;', $message);
        $this->assertStringContainsString('pengguna lain yang berwenang menyetujui', $message);
    }

    // ------------------------------------------------------------- the good path

    public function test_a_second_person_can_approve_it(): void
    {
        $document = $this->makeDocument();
        $document->submit($this->makeUser('Dewi Lestari'));

        $document->approve($this->makeUser('Ratna Kusumawardani'), 'Sesuai kontrak.');

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    /**
     * RetentionService depends on this: the retention-release bill is minted by
     * the engine out of one human act whose route demands scm.post AND
     * fin.approve, so it submits as nobody and the human who released the
     * retention — who provably holds the AP approval right — approves it. With
     * no maker recorded there is nobody to protect against.
     */
    public function test_a_submission_with_no_recorded_actor_is_approvable_by_anyone(): void
    {
        $document = $this->makeDocument();
        $document->submit();

        $document->approve($this->makeUser('Andi Kurniawan'));

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    /**
     * Reject-then-resubmit moves the maker. Bob rejecting Alice's document and
     * resubmitting it makes Bob the person asserting it, so Bob is the one who
     * may no longer approve it — and Alice now can.
     */
    public function test_after_a_resubmission_it_is_the_resubmitter_who_is_refused(): void
    {
        $alice = $this->makeUser('Dewi Lestari');
        $bob = $this->makeUser('Ratna Kusumawardani');

        $document = $this->makeDocument();
        $document->submit($alice);
        $document->reject($bob, 'Rekening tujuan tidak cocok.');
        $document->submit($bob);

        try {
            $document->approve($bob);
            $this->fail('The resubmitter must not be able to approve.');
        } catch (SelfApprovalException $e) {
            $this->assertStringContainsString('Ratna Kusumawardani', $e->getMessage());
        }

        $document->approve($alice);

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    /**
     * Rejecting your own document is deliberately NOT guarded: it moves no
     * money and returns the document to your own desk, and guarding it would
     * strand documents whenever the second approver is away.
     */
    public function test_rejecting_your_own_document_is_allowed(): void
    {
        $dewi = $this->makeUser('Dewi Lestari');
        $document = $this->makeDocument();
        $document->submit($dewi);

        $document->reject($dewi, 'Salah lampiran.');

        $this->assertSame(DocumentStatus::Rejected, $document->fresh()->status);
    }

    // ------------------------------------------------------------- the escape hatch

    public function test_turning_the_setting_off_lets_the_self_approval_through(): void
    {
        $this->setSetting('approvals.segregation_of_duties', false);

        $dewi = $this->makeUser('Dewi Lestari');
        $document = $this->makeDocument();
        $document->submit($dewi);

        $document->approve($dewi, 'Perusahaan hanya punya satu petugas keuangan.');

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
        $this->assertFalse(SegregationOfDuties::isEnforced());
    }

    /**
     * The exemption hides nothing. Both rows stay in core_approvals carrying
     * the same user id, so every self-approval made while the switch was off is
     * findable afterwards with one self-join — which is why the switch needs no
     * separate audit artefact.
     */
    public function test_the_exemption_still_records_that_both_ends_were_the_same_person(): void
    {
        $this->setSetting('approvals.segregation_of_duties', false);

        $dewi = $this->makeUser('Dewi Lestari');
        $document = $this->makeDocument();
        $document->submit($dewi);
        $document->approve($dewi);

        $actors = $document->approvals()->orderBy('id')->pluck('user_id')->all();

        $this->assertSame([$dewi->id, $dewi->id], $actors);
    }

    public function test_the_guard_is_enforced_by_default(): void
    {
        $this->assertTrue(SegregationOfDuties::isEnforced());
    }

    // ------------------------------------------------------------- the query itself

    public function test_the_submitter_is_the_latest_submission_not_the_first(): void
    {
        $alice = $this->makeUser('Dewi Lestari');
        $bob = $this->makeUser('Ratna Kusumawardani');

        $document = $this->makeDocument();
        $document->submit($alice);
        $document->reject($bob);
        $document->submit($bob);

        $this->assertSame($bob->id, SegregationOfDuties::submitterIdOf($document));
    }

    /**
     * A resigned employee still submitted the document. The guard must see them
     * even though the notifier deliberately must not — otherwise deactivating
     * an account is a way of unlocking your own approvals.
     */
    public function test_a_deactivated_submitter_is_still_a_submitter(): void
    {
        $dewi = $this->makeUser('Dewi Lestari');
        $document = $this->makeDocument();
        $document->submit($dewi);

        $dewi->forceFill(['is_active' => false])->save();

        $this->assertSame($dewi->id, SegregationOfDuties::submitterIdOf($document->fresh()));

        $this->expectException(SelfApprovalException::class);
        $document->approve($dewi);
    }

    public function test_a_document_nobody_has_submitted_has_no_submitter(): void
    {
        $this->assertNull(SegregationOfDuties::submitterIdOf($this->makeDocument()));
    }

    /**
     * The owner-column fallback (T3.4) needs a column to read. This fixture
     * table has none, so a document written straight to `submitted` — the
     * seeded PR/2026/III/0002 shape of 4 Sep 2026 — has no maker here and
     * stays approvable by anyone, exactly as before the fallback existed.
     * MakerCheckerOwnerFallbackTest pins the tables that DO carry one.
     */
    public function test_without_an_owner_column_a_document_with_no_submission_has_no_maker(): void
    {
        $document = $this->makeDocument();
        $document->forceFill(['status' => DocumentStatus::Submitted])->save();

        $this->assertNull(SegregationOfDuties::makerIdOf($document));

        $document->approve($this->makeUser('Andi Kurniawan'));

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }
}
