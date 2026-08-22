<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Models\ProjectCost;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Kasbon: issue books a RECEIVABLE (never a cost), settlement recognises the
 * receipts — per line, so WBS attribution survives — and returns the change.
 */
class KasbonTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;
    use PettyCashFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
    }

    // ------------------------------------------------------------------ issue

    public function test_issuing_books_the_employee_receivable_and_shrinks_the_drawer(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);
        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1000000]);

        $issued = $this->kasbons()->issue($kasbon, $this->custodianUser());

        $this->assertSame(KasbonStatus::Issued, $issued->status);

        // Dr 1-1370 / Cr fund — an asset swap, deliberately zero cost: nothing
        // has been bought yet, so the PSAK 115 cost base must not move.
        $lines = $this->linesByAccount($this->singleJournalFor('kasbon', (int) $kasbon->id));
        $this->assertSame(1000000.0, $lines['1-1370']['debit']);
        $this->assertSame(1000000.0, $lines[$fund->coaAccount->code]['credit']);

        $this->assertSame(4000000.0, $this->funds()->balance($fund));
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_issuing_by_anyone_but_the_custodian_is_refused(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);
        $kasbon = $this->makeKasbon($fund, $this->makeEmployee());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Hanya pemegang kas kecil/');

        $this->kasbons()->issue($kasbon, $this->financeUser());
    }

    public function test_a_second_outstanding_kasbon_for_the_same_employee_is_refused_but_another_employee_works(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);
        $employee = $this->makeEmployee();

        $this->kasbons()->issue($this->makeKasbon($fund, $employee, ['amount' => 800000]), $this->custodianUser());

        $second = $this->makeKasbon($fund, $employee, ['amount' => 500000]);

        try {
            $this->kasbons()->issue($second, $this->custodianUser());
            $this->fail('Expected the one-outstanding rule to refuse.');
        } catch (LogicException $e) {
            // The refusal names the advance that has to be accounted for first.
            $this->assertStringContainsString('belum dipertanggungjawabkan', $e->getMessage());
            $this->assertStringContainsString('800000', $e->getMessage());
        }

        $other = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 500000]);
        $this->assertSame(KasbonStatus::Issued, $this->kasbons()->issue($other, $this->custodianUser())->status);
    }

    public function test_a_kasbon_over_the_ceiling_or_over_the_drawer_balance_is_refused(): void
    {
        $fund = $this->makeFund(['max_kasbon_amount' => 2000000]);
        $this->fundDrawer($fund, 1500000);

        try {
            $this->kasbons()->issue(
                $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 2000001]),
                $this->custodianUser(),
            );
            $this->fail('Expected the kasbon ceiling to refuse.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('batas per kasbon', $e->getMessage());
        }

        // Under the ceiling but over the Rp 1.500.000 actually in the drawer.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/melebihi saldo laci/');

        $this->kasbons()->issue(
            $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1600000]),
            $this->custodianUser(),
        );
    }

    /**
     * Temuan #6, kasbon half: issue shares the voucher-post rule — the
     * drawer balance is read AS OF the advance date the journal will carry,
     * so a back-dated pencairan cannot spend June's funding in May.
     */
    public function test_a_kasbon_back_dated_before_the_drawer_was_funded_is_refused(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $backDated = $this->makeKasbon($fund, $this->makeEmployee(), [
            'advance_date' => '2026-05-20', 'amount' => 1000000,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/melebihi saldo laci KK-01 per 2026-05-20/');

        $this->kasbons()->issue($backDated, $this->custodianUser());
    }

    // ------------------------------------------------------------- settlement

    public function test_settlement_returns_the_change_correctly_and_clears_the_receivable(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);
        $project = $this->makeProject();

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1000000]);
        $this->kasbons()->issue($kasbon, $this->custodianUser());
        $this->assertSame(4000000.0, $this->funds()->balance($fund));

        // Spent 700.000 + 100.000 = 800.000 of the 1.000.000 advance; the
        // change is 1.000.000 − 800.000 = 200.000, back into the drawer.
        $settled = $this->kasbons()->settle($kasbon, [
            [
                'category' => 'material',
                'description' => 'Pasir 2 rit',
                'amount' => 700000,
                'project_id' => $project->id,
                'wbs_task_id' => 3,
            ],
            [
                'category' => 'bbm_tol',
                'description' => 'Solar angkutan',
                'amount' => 100000,
                'project_id' => $project->id,
                'wbs_task_id' => 5,
            ],
        ], '2026-06-12', $this->custodianUser());

        $this->assertSame(KasbonStatus::Settled, $settled->status);
        $this->assertSame(200000.0, (float) $settled->cash_returned);

        // Dr 5-1100 700k + Dr 5-1500 100k + Dr fund 200k = Cr 1-1370 1.000k.
        $lines = $this->linesByAccount($this->singleJournalFor('kasbon_settlement', (int) $kasbon->id));
        $this->assertSame(700000.0, $lines['5-1100']['debit']);
        $this->assertSame($project->id, $lines['5-1100']['project_id']);
        $this->assertSame(100000.0, $lines['5-1500']['debit']);
        $this->assertSame(200000.0, $lines[$fund->coaAccount->code]['debit']);
        $this->assertSame(1000000.0, $lines['1-1370']['credit']);

        // Drawer: 4.000.000 + 200.000 change = 4.200.000, which is also
        // float 5.000.000 − 800.000 actually spent.
        $this->assertSame(4200000.0, $this->funds()->balance($fund));

        // Costs are per LINE (reference kasbon_line), each carrying its own
        // WBS pin — same-category lines on different tasks stay two rows.
        $costs = ProjectCost::query()->where('reference_type', 'kasbon_line')->orderBy('reference_id')->get();
        $this->assertCount(2, $costs);
        $this->assertSame([3, 5], $costs->pluck('wbs_task_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['material', 'overhead'], $costs->pluck('cost_category')->map(fn ($c) => $c->value)->all());
        $this->assertSame('2026-06-12', $costs[0]->cost_date->toDateString());
    }

    public function test_an_overspent_settlement_pays_the_shortfall_out_of_the_drawer(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1000000]);
        $this->kasbons()->issue($kasbon, $this->custodianUser());

        // Receipts total 1.200.000 against a 1.000.000 advance: the drawer
        // hands the mandor the missing 200.000 at settlement.
        $settled = $this->kasbons()->settle($kasbon, [
            ['category' => 'upah', 'description' => 'Upah harian 6 tukang', 'amount' => 1200000],
        ], '2026-06-15', $this->custodianUser());

        $this->assertSame(-200000.0, (float) $settled->cash_returned);

        // Dr 6-1100 1.200k = Cr 1-1370 1.000k + Cr fund 200k.
        $lines = $this->linesByAccount($this->singleJournalFor('kasbon_settlement', (int) $kasbon->id));
        $this->assertSame(1200000.0, $lines['6-1100']['debit']);
        $this->assertSame(1000000.0, $lines['1-1370']['credit']);
        $this->assertSame(200000.0, $lines[$fund->coaAccount->code]['credit']);

        // 5.000.000 − 1.000.000 advance − 200.000 shortfall = 3.800.000.
        $this->assertSame(3800000.0, $this->funds()->balance($fund));
    }

    public function test_a_zero_line_settlement_returns_the_whole_advance(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1000000]);
        $this->kasbons()->issue($kasbon, $this->custodianUser());

        // The trip was cancelled; the advance comes back untouched. This IS
        // the cancellation path — a settlement with zero lines.
        $settled = $this->kasbons()->settle($kasbon, [], '2026-06-08', $this->custodianUser());

        $this->assertSame(1000000.0, (float) $settled->cash_returned);
        $this->assertCount(0, $settled->lines);

        $lines = $this->linesByAccount($this->singleJournalFor('kasbon_settlement', (int) $kasbon->id));
        $this->assertSame(1000000.0, $lines[$fund->coaAccount->code]['debit']);
        $this->assertSame(1000000.0, $lines['1-1370']['credit']);

        $this->assertSame(5000000.0, $this->funds()->balance($fund));
    }

    public function test_settling_by_anyone_but_the_custodian_is_refused(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee());
        $this->kasbons()->issue($kasbon, $this->custodianUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Hanya pemegang kas kecil/');

        $this->kasbons()->settle($kasbon, [], '2026-06-08', $this->financeUser());
    }
}
