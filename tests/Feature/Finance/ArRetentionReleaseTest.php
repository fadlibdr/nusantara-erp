<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Models\ArRetention;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Services\ArRetentionService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Releasing retention withheld by a customer.
 *
 * It was withheld correctly from day one — 1-1350 Piutang Retensi debited, a row
 * written with released = false — and nothing in the codebase ever set it true.
 * No route, no screen, no service. 1-1350 grew for the life of the installation
 * and could never be cleared, and nobody was told when retention became
 * collectible.
 */
class ArRetentionReleaseTest extends ErpTestCase
{
    use FinanceFixtures;

    private ArRetentionService $retentions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        // Retention is collected after the warranty period — a year later is the
        // normal case, so the period it lands in has to exist.
        $this->openFiscalYear(2027);
        $this->retentions = app(ArRetentionService::class);
    }

    private function withheldRetention(float $dpp = 100_000_000): ArRetention
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, ['retention_pct' => 5.0]);

        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'description' => 'Termin 1',
            'dpp' => $dpp,
            'ppn_rate' => 0.0,
            'invoice_date' => '2026-03-15',
            'retention_withheld' => 5_000_000,
        ]));

        return ArRetention::query()->sole();
    }

    public function test_retention_is_withheld_and_starts_unreleased(): void
    {
        $retention = $this->withheldRetention();

        $this->assertFalse((bool) $retention->released);
        $this->assertEqualsWithDelta(5_000_000, (float) $retention->amount, 0.01);
    }

    /**
     * The point: 1-1350 could only ever be debited. Releasing credits it.
     */
    public function test_releasing_clears_the_retention_receivable(): void
    {
        $retention = $this->withheldRetention();
        $bank = $this->makeBankAccount('1-1210');

        $before = $this->balanceOf('1-1350');

        $this->retentions->release($retention, '2027-03-15', $bank->id);

        $this->assertEqualsWithDelta(0.0, $this->balanceOf('1-1350'), 0.01, '1-1350 must be cleared');
        $this->assertEqualsWithDelta(5_000_000, $before, 0.01);
        $this->assertEqualsWithDelta(5_000_000, $this->balanceOf('1-1210'), 0.01, 'the money must land in the bank');
    }

    public function test_releasing_marks_the_row_and_records_when(): void
    {
        $retention = $this->withheldRetention();
        $bank = $this->makeBankAccount('1-1210');

        $released = $this->retentions->release($retention, '2027-03-15', $bank->id);

        $this->assertTrue((bool) $released->released);
        $this->assertSame('2027-03-15', $released->released_at->toDateString());
    }

    public function test_releasing_twice_is_refused(): void
    {
        $retention = $this->withheldRetention();
        $bank = $this->makeBankAccount('1-1210');

        $this->retentions->release($retention, '2027-03-15', $bank->id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah dicairkan/');

        $this->retentions->release($retention->refresh(), '2027-04-01', $bank->id);
    }

    public function test_the_outstanding_list_reports_what_is_still_held(): void
    {
        $this->withheldRetention();

        $outstanding = $this->retentions->outstanding();

        $this->assertEqualsWithDelta(5_000_000, $outstanding['total_outstanding'], 0.01);
        $this->assertCount(1, $outstanding['rows']);
    }

    /**
     * The date may come from any BAST, but DUE needs the project really handed
     * back: a draft BAST I carrying a past retention_release_due must not light
     * is_due — collecting retention is conditioned on the FINAL handover, and a
     * draft is not evidence of one. On the demo data this flag guards
     * Rp 2.425.000.000 of contract 1's retention.
     */
    public function test_retention_is_not_due_until_a_bast_two_is_approved(): void
    {
        $retention = $this->withheldRetention();

        $project = Project::query()->create([
            'code' => 'PRJ-2026-091',
            'name' => 'Proyek Uji Retensi',
            'type' => 'construction',
            'status' => 'active',
        ]);
        $retention->forceFill(['project_id' => $project->id])->save();

        DB::table('prj_bast')->insert([
            'code' => 'BAST/2026/III/0001',
            'project_id' => $project->id,
            'bast_type' => 'bast1',
            'handover_date' => '2025-03-01',
            'retention_release_due' => '2026-03-01', // sudah lewat
            'status' => 'draft',
        ]);

        $row = collect($this->retentions->outstanding()['rows'])->sole();
        $this->assertSame('2026-03-01', $row['due_date']);
        $this->assertFalse($row['is_due'], 'a past date on a DRAFT BAST must not make retention collectible');

        DB::table('prj_bast')->insert([
            'code' => 'BAST/2026/III/0002',
            'project_id' => $project->id,
            'bast_type' => 'bast2',
            'handover_date' => '2026-02-28',
            'status' => 'approved',
        ]);

        $row = collect($this->retentions->outstanding()['rows'])->sole();
        $this->assertTrue($row['is_due'], 'an approved BAST II plus a past date is exactly what due means');
    }

    public function test_a_released_retention_leaves_the_outstanding_list(): void
    {
        $retention = $this->withheldRetention();
        $bank = $this->makeBankAccount('1-1210');

        $this->retentions->release($retention, '2027-03-15', $bank->id);

        $this->assertSame(0.0, $this->retentions->outstanding()['total_outstanding']);
        $this->assertSame([], $this->retentions->outstanding()['rows']);
    }

    private function balanceOf(string $code): float
    {
        $row = JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', $code)
            ->where('fin_journals.status', 'posted')
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as bal')
            ->first();

        return (float) ($row->bal ?? 0);
    }
}
