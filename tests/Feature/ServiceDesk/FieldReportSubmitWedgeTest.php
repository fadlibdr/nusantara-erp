<?php

namespace Tests\Feature\ServiceDesk;

use App\Models\User;
use DomainException;
use LogicException;
use Modules\Core\Models\NumberSequence;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Support\DanglingDocuments;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Modules\ServiceDesk\Enums\FieldReportStatus;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\Ticket;
use Modules\ServiceDesk\Services\FieldReportService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * THE PERMANENT PERIOD-CLOSE WEDGE.
 *
 * A parts-bearing field report could be SUBMITTED into a state nothing could
 * ever clear. Submitted + parts is a DanglingDocuments source rendered at BLOCK
 * severity with no override, and PeriodCloseService is sequential — so the month
 * the report is dated in, and every month after it, could never be closed again.
 *
 * Two doors led in, both live on the demo dataset:
 *
 *  (a) warehouse_id is nullable on store and update, and submit() asked for
 *      nothing. acknowledge() -> issueParts() then throws "gudang asalnya belum
 *      diisi", and the remedy it prints — fill in the gudang — is exactly what
 *      update() refuses, because FieldReportStatus::isEditable() is Draft-only.
 *
 *  (b) acknowledge() posts a real inventory issue dated on report_date through
 *      StockService::postIssue, which runs assertStockPeriodOpen AND
 *      assertMovementInOrder. A visit day that falls behind the last
 *      inv_stock_ledger row for that (warehouse, item) is refused for ever:
 *      MAX(trx_date) only ever grows.
 *
 * Both halves of the answer are pinned here. submit() now runs the issue's own
 * preconditions as a DRY RUN — the real IssueService::create + StockService::postIssue,
 * inside a transaction that is always rolled back — so the refusal arrives while
 * the report is still a Draft and every field is still editable. And a Submitted
 * report that nobody has acknowledged can go back to Draft, which is what makes
 * the wedge impossible rather than merely unlikely: a report that passed the dry
 * run can still become unsatisfiable later, when somebody else's movement lands.
 *
 * ACKNOWLEDGED STAYS ONE-WAY. inv_issues.field_report_id is UNIQUE and
 * StockService::cancelIssue explicitly refuses a field-report-raised bon; the
 * negative control at the bottom holds that line.
 */
class FieldReportSubmitWedgeTest extends ErpTestCase
{
    use InventoryFixtures;

    /** Cross-module id: HrPayroll owns hr_employees, there is no FK to satisfy. */
    private const TECHNICIAN_ID = 7;

    private Warehouse $gudang;

    private Item $kamera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->gudang = $this->makeWarehouse('WH-PUSAT');
        $this->kamera = $this->makeItem('CCTV Dome 4MP', [
            'unit' => 'unit',
            'item_type' => ItemType::Sparepart,
        ]);

        // The audit's WH-PUSAT position: 30 units @ Rp 1.850.000.
        $this->receiveStock($this->gudang, $this->kamera, 30, 1850000, '2026-06-01');
    }

    // ------------------------------------------------- 1. the dry run on submit

    public function test_submitting_a_parts_report_with_no_warehouse_is_refused_while_it_is_still_fixable(): void
    {
        $report = $this->draftReport([[$this->kamera, 3]], ['warehouse_id' => null]);

        try {
            $this->reports()->submit($report);
            $this->fail('Expected the missing gudang to refuse the submission.');
        } catch (DomainException $e) {
            $this->assertStringContainsString($report->code, $e->getMessage());
            $this->assertStringContainsString('gudang asalnya belum diisi', $e->getMessage());
        }

        // Still a Draft, so the remedy the message names is actually reachable —
        // which is the whole difference between this and the wedge.
        $fresh = $report->fresh();
        $this->assertSame(FieldReportStatus::Draft, $fresh->status);
        $this->assertTrue($fresh->status->isEditable());

        $this->reports()->update($fresh, ['warehouse_id' => $this->gudang->id]);
        $this->assertSame(FieldReportStatus::Submitted, $this->reports()->submit($fresh->fresh())->status);
    }

    public function test_submitting_is_refused_when_the_visit_month_is_already_closed(): void
    {
        FiscalPeriod::query()->where('year', 2026)->where('month', 6)->update(['status' => 'closed']);

        $report = $this->draftReport([[$this->kamera, 3]]); // visit dated 2026-06-10

        try {
            $this->reports()->submit($report);
            $this->fail('Expected the closed fiscal period to refuse the submission.');
        } catch (DomainException $e) {
            // StockService::assertStockPeriodOpen, verbatim inside the wrapper.
            $this->assertStringContainsString('Periode fiskal 2026-06 sudah ditutup', $e->getMessage());
        }

        $this->assertSame(FieldReportStatus::Draft, $report->fresh()->status);

        // Re-dating into an open month is the remedy, and a Draft can be re-dated.
        $this->reports()->update($report->fresh(), ['report_date' => '2026-07-02']);
        $this->assertSame(FieldReportStatus::Submitted, $this->reports()->submit($report->fresh())->status);
    }

    public function test_submitting_is_refused_when_the_visit_falls_behind_the_last_movement_for_its_parts(): void
    {
        // Somebody else's receipt lands on 20 June; the technician's visit was
        // on the 10th. MAX(trx_date) only ever grows, so this bon could never
        // be posted — not today, not after any close is reopened.
        $this->receiveStock($this->gudang, $this->kamera, 5, 1900000, '2026-06-20');

        $report = $this->draftReport([[$this->kamera, 3]]);

        try {
            $this->reports()->submit($report);
            $this->fail('Expected the chronology guard to refuse the submission.');
        } catch (DomainException $e) {
            // StockService::assertMovementInOrder, verbatim inside the wrapper.
            $this->assertStringContainsString('lebih awal dari mutasi terakhir 2026-06-20', $e->getMessage());
            $this->assertStringContainsString('CCTV Dome 4MP', $e->getMessage());
            $this->assertStringContainsString('Gudang WH-PUSAT', $e->getMessage());
        }

        $this->assertSame(FieldReportStatus::Draft, $report->fresh()->status);
    }

    public function test_the_dry_run_leaves_nothing_behind_at_all(): void
    {
        // A dry run that half-posted would be worse than the wedge. Nothing is
        // written on the refusal, and nothing on the pass either — not even the
        // ISS number, which is drawn inside the same rolled-back transaction.
        $refused = $this->draftReport([[$this->kamera, 3]], ['warehouse_id' => null]);

        try {
            $this->reports()->submit($refused);
        } catch (DomainException) {
            // asserted above
        }

        $ok = $this->draftReport([[$this->kamera, 3]]);
        $this->reports()->submit($ok);

        $this->assertSame(0, Issue::query()->count());
        $this->assertSame(30.0, $this->balanceQty($this->gudang, $this->kamera));
        $this->assertSame(0, StockLedgerEntry::query()->where('direction', 'out')->count());
        $this->assertSame(0, Journal::query()->where('reference_type', 'inventory_issue')->count());
        $this->assertFalse(NumberSequence::query()->where('type', 'ISS')->exists());

        // And the number the real acknowledgement draws is the FIRST one — two
        // dry runs did not burn ISS/2026/…/0001 and ISS/2026/…/0002 on the way.
        $issue = $this->reports()->acknowledge($ok->fresh(), 'Darto Prasetyo')->issue;
        $this->assertStringEndsWith('/0001', (string) $issue->code);
    }

    public function test_a_signature_only_report_still_submits_without_a_gudang(): void
    {
        // A visit that consumed no parts issues nothing and pins nothing, so it
        // must not be asked for a warehouse it does not need.
        $report = $this->draftReport([], ['warehouse_id' => null]);

        $this->assertSame(FieldReportStatus::Submitted, $this->reports()->submit($report)->status);
    }

    // --------------------------------------------------- 2. the way back

    public function test_a_submitted_report_that_nobody_signed_can_return_to_draft(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);

        $returned = $this->reports()->returnToDraft($report);

        $this->assertSame(FieldReportStatus::Draft, $returned->status);
        $this->assertTrue($returned->status->isEditable());
        $this->assertSame(FieldReportStatus::Draft, $report->fresh()->status);

        // Nothing was ever posted for it, so there is nothing to unwind.
        $this->assertSame(0, Issue::query()->count());
        $this->assertSame(30.0, $this->balanceQty($this->gudang, $this->kamera));
    }

    public function test_the_way_back_clears_a_report_that_was_already_wedged(): void
    {
        // The live shape, reproduced end to end: PM/… submitted on a June visit,
        // June closed behind it, the signature refused for ever. Before the way
        // back existed this report blocked June — and therefore July, August and
        // every month after — with no move left that could clear it.
        $report = $this->submittedReport([[$this->kamera, 3]]);

        FiscalPeriod::query()->where('year', 2026)->where('month', 6)->update(['status' => 'closed']);

        try {
            $this->reports()->acknowledge($report, 'Darto Prasetyo');
            $this->fail('Expected the closed period to refuse the acknowledgement.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-06 sudah ditutup', $e->getMessage());
        }

        $this->assertSame(1, DanglingDocuments::total(DanglingDocuments::scan(2026, 6)));

        // The way out: back to Draft, re-dated into the open month, submitted
        // again — and June is clear. Re-read first: the refused acknowledgement
        // rolled the database back but left "acknowledged" on this PHP object,
        // which is why every caller here works from a fresh model, exactly as
        // route-model binding hands the controller one.
        $this->reports()->returnToDraft($report->fresh());
        $this->reports()->update($report->fresh(), ['report_date' => '2026-07-02']);
        $this->reports()->submit($report->fresh());

        $this->assertSame(0, DanglingDocuments::total(DanglingDocuments::scan(2026, 6)));

        $acknowledged = $this->reports()->acknowledge($report->fresh(), 'Darto Prasetyo');
        $this->assertSame(FieldReportStatus::Acknowledged, $acknowledged->status);
        $this->assertSame(27.0, $this->balanceQty($this->gudang, $this->kamera));
    }

    public function test_a_draft_report_has_nowhere_to_return_to(): void
    {
        $report = $this->draftReport([[$this->kamera, 3]]);

        try {
            $this->reports()->returnToDraft($report);
            $this->fail('Expected a LogicException for a report that is already a draft.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('draft', $e->getMessage());
        }

        $this->assertSame(FieldReportStatus::Draft, $report->fresh()->status);
    }

    // ------------------------------------- 3. the negative control: one-way

    public function test_an_acknowledged_report_is_still_refused_a_way_back(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);
        $this->reports()->acknowledge($report, 'Darto Prasetyo');

        try {
            $this->reports()->returnToDraft($report->fresh());
            $this->fail('Expected an acknowledged report to refuse the return to draft.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($report->code, $e->getMessage());
        }

        // The bon is untouched, the stock stayed out, and the report is still
        // locked — inv_issues.field_report_id is UNIQUE and cancelIssue refuses
        // a field-report-raised bon, so a way back here would strand the issue.
        $fresh = $report->fresh();
        $this->assertSame(FieldReportStatus::Acknowledged, $fresh->status);
        $this->assertFalse($fresh->status->isEditable());
        $this->assertSame(1, Issue::query()->where('field_report_id', $report->id)->count());
        $this->assertSame(27.0, $this->balanceQty($this->gudang, $this->kamera));
    }

    /**
     * The negative control above is honest but incomplete: it passes
     * ->fresh(), and route-model binding does not.
     *
     * Once Submitted stopped being one-way, the two transitions out of it can
     * race. A request that resolved a Submitted report a moment before the
     * customer's signature committed still holds an instance that says
     * Submitted — and deciding from it would drop an ACKNOWLEDGED report back
     * to Draft, editable, with a posted bon and a posted journal still pointing
     * at it. That is the state canReturnToDraft() exists to forbid, and it was
     * reachable for as long as the status was read off the caller's model.
     */
    public function test_a_stale_submitted_instance_cannot_undo_a_signature_that_already_committed(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);

        // What the route bound, before the other request ran.
        $stale = FieldReport::query()->findOrFail($report->id);
        $this->assertSame(FieldReportStatus::Submitted, $stale->status);

        $this->reports()->acknowledge($report->fresh(), 'Darto Prasetyo');

        try {
            $this->reports()->returnToDraft($stale);
            $this->fail('Expected the re-read to refuse a return to draft on a signed report.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($report->code, $e->getMessage());
        }

        $fresh = $report->fresh();
        $this->assertSame(FieldReportStatus::Acknowledged, $fresh->status);
        $this->assertFalse($fresh->status->isEditable());
        $this->assertSame(1, Issue::query()->where('field_report_id', $report->id)->count());
        $this->assertSame(27.0, $this->balanceQty($this->gudang, $this->kamera));
    }

    /**
     * The mirror: acknowledging off a stale Submitted instance whose row has
     * since gone BACK to Draft would post the bon and jump Draft ->
     * Acknowledged without ever passing submit()'s dry run — the one gate that
     * proves the parts can still be issued.
     */
    public function test_a_stale_submitted_instance_cannot_be_acknowledged_after_it_returned_to_draft(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);

        $stale = FieldReport::query()->findOrFail($report->id);

        $this->reports()->returnToDraft($report->fresh());

        try {
            $this->reports()->acknowledge($stale, 'Darto Prasetyo');
            $this->fail('Expected the re-read to refuse an acknowledgement of a draft.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($report->code, $e->getMessage());
        }

        // Nothing was posted and nothing left the shelf.
        $this->assertSame(FieldReportStatus::Draft, $report->fresh()->status);
        $this->assertSame(0, Issue::query()->where('field_report_id', $report->id)->count());
        $this->assertSame(30.0, $this->balanceQty($this->gudang, $this->kamera));
    }

    // --------------------------------------------- 3. the SPA can reach it

    public function test_the_transition_is_reachable_over_http_with_svc_update(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);

        $this->actingAs($this->serviceUser(), 'sanctum')
            ->postJson("/api/servicedesk/field-reports/{$report->id}/return-to-draft")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame(FieldReportStatus::Draft, $report->fresh()->status);
    }

    public function test_the_transition_still_asks_for_the_service_desk_right(): void
    {
        // Unlocking a document for editing is an svc.update job and nothing
        // less, so a read-only service-desk login is refused by the route
        // middleware before the controller is reached.
        $report = $this->submittedReport([[$this->kamera, 3]]);

        $this->actingAs($this->readOnlyUser(), 'sanctum')
            ->postJson("/api/servicedesk/field-reports/{$report->id}/return-to-draft")
            ->assertForbidden();

        $this->assertSame(FieldReportStatus::Submitted, $report->fresh()->status);
    }

    public function test_the_endpoint_refuses_an_acknowledged_report_over_http_too(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);
        $this->reports()->acknowledge($report, 'Darto Prasetyo');

        $this->actingAs($this->serviceUser(), 'sanctum')
            ->postJson("/api/servicedesk/field-reports/{$report->id}/return-to-draft")
            ->assertStatus(422);

        $this->assertSame(FieldReportStatus::Acknowledged, $report->fresh()->status);
    }

    public function test_the_submit_endpoint_answers_the_dry_run_refusal_instead_of_a_500(): void
    {
        $report = $this->draftReport([[$this->kamera, 3]], ['warehouse_id' => null]);

        $this->actingAs($this->serviceUser(), 'sanctum')
            ->postJson("/api/servicedesk/field-reports/{$report->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'gudang asalnya belum diisi'));

        $this->assertSame(FieldReportStatus::Draft, $report->fresh()->status);
    }

    // ----------------------------------------------------------------- fixtures

    private function reports(): FieldReportService
    {
        return app(FieldReportService::class);
    }

    /**
     * A service-desk supervisor: svc.update for the transitions plus inv.post,
     * which FieldReportAcknowledgeRequest demands of a parts visit.
     */
    private function serviceUser(): User
    {
        return $this->roleUser('supervisor-servis', [
            'svc.view', 'svc.create', 'svc.update', 'inv.view', 'inv.post',
        ], 'supervisor@test.local');
    }

    /** A service-desk login that may look but not touch. */
    private function readOnlyUser(): User
    {
        return $this->roleUser('pelapor', ['svc.view'], 'pelapor@test.local');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function roleUser(string $name, array $permissions, string $email): User
    {
        $this->seed(PermissionSeeder::class);

        $role = Role::findOrCreate($name, 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Joko Susilo',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<int, array{0: Item, 1: float}>  $parts
     * @param  array<string, mixed>  $attributes
     */
    private function draftReport(array $parts = [], array $attributes = []): FieldReport
    {
        return $this->report($parts, array_merge(['status' => FieldReportStatus::Draft], $attributes));
    }

    /**
     * @param  array<int, array{0: Item, 1: float}>  $parts
     * @param  array<string, mixed>  $attributes
     */
    private function submittedReport(array $parts = [], array $attributes = []): FieldReport
    {
        return $this->report($parts, array_merge(['status' => FieldReportStatus::Submitted], $attributes));
    }

    /**
     * @param  array<int, array{0: Item, 1: float}>  $parts
     * @param  array<string, mixed>  $attributes
     */
    private function report(array $parts, array $attributes): FieldReport
    {
        $ticket = Ticket::create([
            'customer_id' => 1, // crm_customers.id (cross-module, no FK)
            'title' => 'Kamera lobi mati total',
            'priority' => 'high',
            'reported_at' => '2026-06-09 08:00:00',
        ]);

        $report = FieldReport::create(array_merge([
            'ticket_id' => $ticket->id,
            'report_date' => '2026-06-10',
            'technician_employee_id' => self::TECHNICIAN_ID,
            'warehouse_id' => $this->gudang->id,
            'findings' => '3 unit kamera dome lobi mati total.',
            'actions_taken' => 'Penggantian 3 unit CCTV Dome 4MP.',
        ], $attributes));

        foreach ($parts as [$item, $qty]) {
            $report->parts()->create(['item_id' => $item->id, 'qty' => $qty]);
        }

        return $report->refresh();
    }
}
