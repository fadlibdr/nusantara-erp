<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\TaxObligation;
use Modules\Finance\Services\TaxObligationService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * KALENDER PAJAK (#25) — the register of tax masa obligations.
 *
 * Before this register the statutory deadlines existed only inside
 * CashFlowService's projection: the 10th and the month-end were CHARGED to the
 * cash plan, but nobody was shown a list of which masa is unpaid, which SSP
 * has an NTPN, and which SPT is still unreported. This is that list: one row
 * per (jenis, masa), due dates computed from the SAME TaxDeadlines rules the
 * projection uses, status maintained by hand — recording a setoran means
 * typing the NTPN off the real SSP, there is no e-filing integration to
 * pretend otherwise.
 */
class TaxObligationTest extends ErpTestCase
{
    use FinanceFixtures;

    private function service(): TaxObligationService
    {
        return app(TaxObligationService::class);
    }

    // ------------------------------------------------- generation

    public function test_ensure_year_creates_one_row_per_jenis_per_masa_with_the_statutory_due_dates(): void
    {
        $created = $this->service()->ensureYear(2026);

        // 4 jenis (PPh 21, PPh 23, PPh final 4(2), PPN) x 12 masa.
        $this->assertSame(48, $created);
        $this->assertSame(48, TaxObligation::query()->count());

        // Masa Juli 2026: PPh due 10 Agu, PPN due 31 Agu — the same dates
        // CashFlowService has always charged the projection on.
        $pph21 = TaxObligation::query()
            ->where('tax_type', 'pph21')->where('masa_year', 2026)->where('masa_month', 7)
            ->sole();
        $this->assertSame('2026-08-10', $pph21->due_date->toDateString());
        $this->assertSame('belum', $pph21->status());

        $ppn = TaxObligation::query()
            ->where('tax_type', 'ppn')->where('masa_year', 2026)->where('masa_month', 7)
            ->sole();
        $this->assertSame('2026-08-31', $ppn->due_date->toDateString());

        // Masa Desember crosses the year boundary.
        $des = TaxObligation::query()
            ->where('tax_type', 'pph23')->where('masa_year', 2026)->where('masa_month', 12)
            ->sole();
        $this->assertSame('2027-01-10', $des->due_date->toDateString());
    }

    public function test_ensure_year_is_idempotent_and_never_clobbers_manual_entries(): void
    {
        $this->service()->ensureYear(2026);

        $row = TaxObligation::query()
            ->where('tax_type', 'ppn')->where('masa_year', 2026)->where('masa_month', 6)
            ->sole();

        $this->service()->update($row, [
            'amount' => 1043955000.0,
            'ntpn' => 'A1B2C3D4E5F60708',
            'disetor_date' => '2026-07-30',
        ]);

        $this->assertSame(0, $this->service()->ensureYear(2026));

        $row = $row->fresh();
        $this->assertSame('A1B2C3D4E5F60708', $row->ntpn);
        $this->assertSame(1043955000.0, (float) $row->amount);
        $this->assertSame('disetor', $row->status());
    }

    // ------------------------------------------------- manual status entry

    public function test_recording_a_setoran_requires_the_ntpn(): void
    {
        $this->service()->ensureYear(2026);
        $row = TaxObligation::query()->where('tax_type', 'pph21')->where('masa_month', 7)->sole();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('NTPN');

        $this->service()->update($row, [
            'amount' => 25000000.0,
            'disetor_date' => '2026-08-08',
        ]);
    }

    public function test_a_masa_with_money_on_it_cannot_be_reported_before_it_is_deposited(): void
    {
        $this->service()->ensureYear(2026);
        $row = TaxObligation::query()->where('tax_type', 'pph23')->where('masa_month', 7)->sole();

        try {
            $this->service()->update($row, [
                'amount' => 2312000.0,
                'dilapor_date' => '2026-08-15',
            ]);
            $this->fail('Reported before deposited.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('disetor', $e->getMessage());
        }

        // A NIHIL masa is the legitimate exception: nothing to deposit, the
        // SPT masa is still reported.
        $updated = $this->service()->update($row, [
            'amount' => 0,
            'dilapor_date' => '2026-08-15',
        ]);

        $this->assertSame('dilapor', $updated->status());
        $this->assertNull($updated->disetor_date);
    }

    public function test_the_full_manual_lifecycle_lands_on_dilapor(): void
    {
        $this->service()->ensureYear(2026);
        $row = TaxObligation::query()->where('tax_type', 'ppn')->where('masa_month', 7)->sole();

        $journal = $this->postJournalForLink();

        $updated = $this->service()->update($row, [
            'amount' => 1043955000.0,
            'ntpn' => 'F1E2D3C4B5A60708',
            'disetor_date' => '2026-08-28',
            'journal_id' => $journal->id,
        ]);
        $this->assertSame('disetor', $updated->status());

        $updated = $this->service()->update($updated, ['dilapor_date' => '2026-08-30']);
        $this->assertSame('dilapor', $updated->status());
        $this->assertSame((int) $journal->id, (int) $updated->journal_id);
    }

    // ------------------------------------------------- the API

    public function test_the_register_is_reachable_end_to_end(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('api/finance/tax-obligations/generate', ['year' => 2026])
            ->assertOk()
            ->assertJsonPath('data.created', 48);

        $list = $this->actingAs($admin)
            ->getJson('api/finance/tax-obligations?year=2026&per_page=60')
            ->assertOk();

        $this->assertCount(48, $list->json('data'));

        $rowId = collect($list->json('data'))
            ->firstWhere(fn (array $row): bool => $row['tax_type'] === 'pph21' && $row['masa_month'] === 7)['id'];

        $this->actingAs($admin)
            ->putJson("api/finance/tax-obligations/{$rowId}", [
                'amount' => 25000000,
                'ntpn' => 'A1B2C3D4E5F60708',
                'disetor_date' => '2026-08-08',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'disetor')
            ->assertJsonPath('data.ntpn', 'A1B2C3D4E5F60708');

        // The JV linkage is a picked reference and nothing more: a journal id
        // that does not exist is a field error.
        $this->actingAs($admin)
            ->putJson("api/finance/tax-obligations/{$rowId}", ['journal_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['journal_id']);
    }

    // ------------------------------------------------- fixtures

    private function postJournalForLink(): Journal
    {
        $this->seedLedger(2026);

        // Any posted JV will do — the linkage stores a reference, nothing else.
        return $this->postJournal([
            ['2-1300', 100000.0, 0.0],
            ['1-1210', 0.0, 100000.0],
        ], '2026-08-28', 'Setoran PPN masa Juli 2026');
    }
}
