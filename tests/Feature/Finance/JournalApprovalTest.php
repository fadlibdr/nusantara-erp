<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Journal;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pemisahan tugas pada JV manual — the stage Payment got, applied to the one
 * document that could still move bank money alone.
 *
 * The hole this closes: a user holding fin.create + fin.post (the seeded
 * `finance` role) could key Dr 2-1100 Hutang Usaha / Cr 1-1210 Bank for
 * Rp 111.000.000 and post it in two requests, producing the identical ledger
 * entry PaymentService::post() writes — with zero core_approvals rows, while
 * the whole submit → approve → post stage on payments existed precisely to
 * prevent that entry being one person's work. Posting a JV is therefore
 * fin.approve now, the maker is stamped on fin_journals.created_by, and
 * SegregationOfDuties refuses the maker.
 *
 * autoPost() keeps its unguarded internal path on purpose: a journal an AP
 * bill approval mints is already gated by that approval.
 */
class JournalApprovalTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
    }

    /** @return array<string, mixed> a balanced Dr 2-1100 / Cr 1-1210 body — the disbursement shape */
    private function journalBody(float $amount = 111000000): array
    {
        return [
            'journal_date' => '2026-03-10',
            'description' => 'Pembayaran hutang vendor via JV',
            'lines' => [
                ['account_id' => $this->accountId('2-1100'), 'debit' => $amount, 'credit' => 0],
                ['account_id' => $this->accountId('1-1210'), 'debit' => 0, 'credit' => $amount],
            ],
        ];
    }

    private function userWith(array $permissions, string $name = 'Pengguna Uji'): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => $name,
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    // ------------------------------------------------------- the route gate

    public function test_posting_a_manual_journal_requires_fin_approve_not_fin_post(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.create'], 'Dewi Lestari');
        $poster = $this->userWith(['fin.view', 'fin.post'], 'Petugas Posting');

        $id = $this->actingAs($clerk, 'sanctum')
            ->postJson('/api/finance/journals', $this->journalBody())
            ->assertCreated()
            ->json('data.id');

        // The exact role the stage was built to constrain: fin.post without
        // fin.approve may no longer authorise a hand-keyed entry.
        $this->actingAs($poster, 'sanctum')
            ->postJson("/api/finance/journals/{$id}/post")
            ->assertForbidden();

        $this->assertSame(PostingStatus::Draft, Journal::query()->findOrFail($id)->status);
    }

    public function test_a_second_person_holding_fin_approve_can_post_the_journal(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.create'], 'Dewi Lestari');
        $approver = $this->userWith(['fin.view', 'fin.approve'], 'Ratna Kusumawardani');

        $id = $this->actingAs($clerk, 'sanctum')
            ->postJson('/api/finance/journals', $this->journalBody())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/finance/journals/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $journal = Journal::query()->findOrFail($id);
        // Maker and checker are both on the record, and they differ.
        $this->assertSame($clerk->id, (int) $journal->created_by);
        $this->assertSame($approver->id, (int) $journal->posted_by);
    }

    // ------------------------------------------------------- maker-checker

    public function test_the_clerk_who_keyed_the_journal_cannot_post_it_themselves(): void
    {
        // Even holding BOTH permissions: the refusal is about who keyed it,
        // not about which role they carry.
        $clerk = $this->userWith(['fin.view', 'fin.create', 'fin.approve'], 'Dewi Lestari');

        $id = $this->actingAs($clerk, 'sanctum')
            ->postJson('/api/finance/journals', $this->journalBody())
            ->assertCreated()
            ->json('data.id');

        $response = $this->actingAs($clerk, 'sanctum')
            ->postJson("/api/finance/journals/{$id}/post")
            ->assertStatus(422);

        $this->assertStringContainsString('tidak boleh disetujui oleh pengajunya sendiri', $response->json('message'));
        $this->assertStringContainsString('Dewi Lestari', $response->json('message'));

        $journal = Journal::query()->findOrFail($id);
        $this->assertSame(PostingStatus::Draft, $journal->status);
        $this->assertNull($journal->posted_at);
    }

    public function test_creating_a_journal_by_api_records_the_maker_in_the_approval_trail(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.create'], 'Dewi Lestari');

        $id = $this->actingAs($clerk, 'sanctum')
            ->postJson('/api/finance/journals', $this->journalBody())
            ->assertCreated()
            ->assertJsonPath('data.created_by', $clerk->id)
            ->json('data.id');

        // The same core_approvals row every approvable document writes — the
        // forensic self-join an auditor runs now works for a JV too.
        $trail = Journal::query()->findOrFail($id)->approvals()->get();

        $this->assertCount(1, $trail);
        $this->assertSame('submitted', $trail->first()->action);
        $this->assertSame($clerk->id, (int) $trail->first()->user_id);
    }

    public function test_turning_segregation_off_lets_the_maker_post_their_own_journal(): void
    {
        // The documented escape hatch for a company with no second officer —
        // and the trail still records, permanently, that maker and checker
        // were the same person.
        $this->setSetting('approvals.segregation_of_duties', false);

        $clerk = $this->userWith(['fin.view', 'fin.create', 'fin.approve'], 'Dewi Lestari');

        $id = $this->actingAs($clerk, 'sanctum')
            ->postJson('/api/finance/journals', $this->journalBody())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($clerk, 'sanctum')
            ->postJson("/api/finance/journals/{$id}/post")
            ->assertOk();

        $journal = Journal::query()->findOrFail($id);
        $this->assertSame((int) $journal->created_by, (int) $journal->posted_by);
    }

    // ------------------------------------------------------- the internal path

    public function test_auto_posted_document_journals_still_book_without_a_second_pair_of_eyes(): void
    {
        // An AP bill approval mints and posts its journal inside the approval
        // transaction. That path is gated by the bill's own maker-checker, so
        // the journal guard must stay out of its way — no created_by, no
        // submitted row, no refusal.
        $bill = $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-03-10',
            'description' => 'Tagihan semen',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]);
        $this->approveBill($bill);

        $this->assertSame(DocumentStatus::Approved, $bill->fresh()->status);

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertSame(PostingStatus::Posted, $journal->status);
        $this->assertNull($journal->created_by);
        $this->assertSame(0, $journal->approvals()->count());
    }
}
