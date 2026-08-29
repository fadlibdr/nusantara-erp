<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Services\ArInvoiceService;
use Modules\Finance\Services\OwnerAdvanceService;
use Modules\Projects\Enums\ZoneCertificateStatus;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Services\MeasurementService;
use Modules\Projects\Services\ZoneCertificateService;
use Tests\ErpTestCase;
use Tests\Feature\Projects\OpnameFixtures;

/**
 * P3 — the owner claim built from a signed opname: kriteria #6, the uang muka
 * recovery, and the denda.
 *
 * KRITERIA #6 is the one that decides money. A claim that bills a zone we have
 * ourselves written down as "Nunggu perbaikan" asks the owner to pay for work
 * our own BAPP says is defective; he pays, the retention clock starts, and the
 * defect surfaces after the retention is gone. The gate runs twice — when the
 * claim is assembled and again when it is approved — because a zone can turn
 * while a draft sits on somebody's desk, and approval is what books the
 * receivable.
 *
 * The arithmetic below is the fixture's: a Rp 1.000.000.000 contract, an opname
 * measuring Rp 260.000.000 (500 m3 galian + 100 m3 beton), and where a DP
 * exists it is Rp 200.000.000 — 20 % of the contract, so a claim of
 * Rp 260.000.000 pays back Rp 52.000.000 of it.
 */
class OwnerClaimTest extends ErpTestCase
{
    use OpnameFixtures;

    private ArInvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger();
        $this->seedOpnameWorld();
        $this->invoices = app(ArInvoiceService::class);
    }

    // -------------------------------------------------------------- fixtures

    private function user(string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => str()->random(10).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function approvedOpname(array $lines): ProgressMeasurement
    {
        $service = app(MeasurementService::class);

        $opname = $service->create([
            'project_id' => $this->project->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'items' => $lines,
        ]);

        $opname->submit($this->user('Pengukur'));

        return $service->approve($opname, $this->user('Manajer Proyek'));
    }

    /** The whole opname, Rp 260.000.000, entirely inside one zone. */
    private function opnameInZone(int $locationId): ProgressMeasurement
    {
        return $this->approvedOpname([
            $this->line('A.1', 500, $locationId),
            $this->line('A.2', 100, $locationId),
        ]);
    }

    private function certify(int $locationId, string $status, string $date = '2026-06-25'): void
    {
        app(ZoneCertificateService::class)->create([
            'project_id' => $this->project->id,
            'location_id' => $locationId,
            'status' => $status,
            'certified_at' => $date,
            'certified_by_party' => 'mk',
        ]);
    }

    private function approve(ArInvoice $invoice): ArInvoice
    {
        $invoice->submit($this->user('Admin Kontrak'));

        return $this->invoices->approve($invoice, $this->user('Manajer Keuangan'));
    }

    /** @return array<string, array{debit: float, credit: float}> by account code */
    private function journalOf(ArInvoice $invoice): array
    {
        $rows = DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.reference_type', 'ar_invoice')
            ->where('j.reference_id', $invoice->id)
            ->get(['a.code', 'l.debit', 'l.credit']);

        $byAccount = [];

        foreach ($rows as $row) {
            $byAccount[$row->code] = [
                'debit' => round((float) $row->debit, 2),
                'credit' => round((float) $row->credit, 2),
            ];
        }

        return $byAccount;
    }

    /** An approved DP invoice of Rp 200.000.000 — 20 % of the contract. */
    private function billedAdvance(float $amount = 200_000_000): ArInvoice
    {
        $invoice = $this->invoices->create([
            'customer_id' => $this->contract->customer_id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-03-01',
            'description' => 'Uang muka 20% '.$this->contract->code,
            'dpp' => $amount,
            'is_advance' => true,
        ]);

        return $this->approve($invoice);
    }

    // ------------------------------------------------------------ kriteria #6

    public function test_a_claim_refuses_a_zone_whose_bapp_says_waiting_repair_and_names_it(): void
    {
        $zone = $this->makeZone('Z-A', 'Lantai 3 Zona A');
        $opname = $this->opnameInZone($zone->id);
        $this->certify($zone->id, 'waiting_repair');

        try {
            $this->invoices->create(['measurement_id' => $opname->id]);
            $this->fail('Klaim atas zona "Nunggu perbaikan" seharusnya ditolak.');
        } catch (ValidationException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));

            $this->assertStringContainsString('Z-A', $message);
            $this->assertStringContainsString('Lantai 3 Zona A', $message);
            $this->assertStringContainsString('Nunggu perbaikan', $message);
            $this->assertStringContainsString($opname->code, $message);
        }

        $this->assertSame(0, ArInvoice::query()->where('measurement_id', $opname->id)->count());
    }

    public function test_the_same_claim_goes_through_once_the_zone_is_certified_done(): void
    {
        $zone = $this->makeZone('Z-A', 'Lantai 3 Zona A');
        $opname = $this->opnameInZone($zone->id);
        $this->certify($zone->id, 'waiting_repair', '2026-06-20');
        $this->certify($zone->id, 'done', '2026-06-28'); // the later sheet is the one that counts

        $invoice = $this->invoices->create(['measurement_id' => $opname->id]);

        $this->assertSame('260000000.00', $invoice->dpp);
        $this->assertSame($opname->id, $invoice->measurement_id);
        $this->assertStringContainsString($opname->code, $invoice->description);
    }

    public function test_a_zone_marked_waiting_repair_after_the_draft_blocks_the_approval(): void
    {
        $zone = $this->makeZone('Z-A', 'Lantai 3 Zona A');
        $opname = $this->opnameInZone($zone->id);
        $this->certify($zone->id, 'done', '2026-06-20');

        $invoice = $this->invoices->create(['measurement_id' => $opname->id]);

        $this->certify($zone->id, 'waiting_repair', '2026-07-01');

        $invoice->submit($this->user('Admin Kontrak'));

        $this->expectException(ValidationException::class);
        $this->invoices->approve($invoice, $this->user('Manajer Keuangan'));
    }

    public function test_lines_without_a_zone_are_billable_because_a_bapp_says_nothing_about_them(): void
    {
        $opname = $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);

        $invoice = $this->invoices->create(['measurement_id' => $opname->id]);

        $this->assertSame('260000000.00', $invoice->dpp);
    }

    public function test_the_billing_gate_and_the_bapp_service_agree_on_which_sheet_counts(): void
    {
        $zone = $this->makeZone('Z-A', 'Lantai 3 Zona A');
        $this->certify($zone->id, 'done', '2026-06-20');
        $this->certify($zone->id, 'waiting_repair', '2026-06-28');

        $this->assertSame(
            ZoneCertificateStatus::WaitingRepair,
            app(ZoneCertificateService::class)->statusFor($this->project->id, $zone->id),
        );

        $opname = $this->opnameInZone($zone->id);

        $this->expectException(ValidationException::class);
        $this->invoices->create(['measurement_id' => $opname->id]);
    }

    public function test_an_opname_that_is_not_approved_cannot_be_billed(): void
    {
        $opname = app(MeasurementService::class)->create([
            'project_id' => $this->project->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'items' => [$this->line('A.1', 500)],
        ]);

        $this->expectException(LogicException::class);
        $this->invoices->create(['measurement_id' => $opname->id]);
    }

    // -------------------------------------------------------- uang muka

    public function test_a_claim_recovers_the_billed_advance_proportionally(): void
    {
        $this->billedAdvance(); // Rp 200.000.000 of a Rp 1.000.000.000 contract = 20 %

        $opname = $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);
        $invoice = $this->invoices->create(['measurement_id' => $opname->id]);

        // 260.000.000 x 20 % = 52.000.000
        $this->assertSame('52000000.00', $invoice->advance_recovery_amount);
        // PPN on (260.000.000 - 52.000.000) x 11 % — the DP invoice already
        // charged PPN on the recovered slice.
        $this->assertSame('22880000.00', $invoice->ppn_amount);
        // 260.000.000 + 22.880.000 - 52.000.000
        $this->assertSame('230880000.00', $invoice->total);
    }

    public function test_a_claim_recovers_nothing_when_no_advance_was_billed(): void
    {
        $opname = $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);
        $invoice = $this->invoices->create(['measurement_id' => $opname->id]);

        $this->assertSame('0.00', $invoice->advance_recovery_amount);
        $this->assertSame('28600000.00', $invoice->ppn_amount); // 260.000.000 x 11 %
        $this->assertSame('288600000.00', $invoice->total);
    }

    public function test_an_advance_still_sitting_in_draft_recovers_nothing(): void
    {
        $this->invoices->create([
            'customer_id' => $this->contract->customer_id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-03-01',
            'description' => 'Uang muka 20% (draf)',
            'dpp' => 200_000_000,
            'is_advance' => true,
        ]); // never submitted, never approved

        $opname = $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);
        $invoice = $this->invoices->create(['measurement_id' => $opname->id]);

        $this->assertSame('0.00', $invoice->advance_recovery_amount);
    }

    public function test_a_second_claim_recovers_only_what_is_still_outstanding(): void
    {
        $this->billedAdvance();

        $first = $this->approve($this->invoices->create([
            'measurement_id' => $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)])->id,
        ]));
        $this->assertSame('52000000.00', $first->advance_recovery_amount);

        // A second opname of Rp 40.000.000 (200 m3 galian): 20 % = 8.000.000.
        $second = app(MeasurementService::class)->create([
            'project_id' => $this->project->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'items' => [$this->line('A.1', 200)],
        ]);
        $second->submit($this->user('Pengukur 2'));
        app(MeasurementService::class)->approve($second, $this->user('Manajer Proyek 2'));

        $invoice = $this->invoices->create(['measurement_id' => $second->id]);

        $this->assertSame('8000000.00', $invoice->advance_recovery_amount);
        $this->assertSame(148_000_000.0, app(OwnerAdvanceService::class)
            ->outstanding($this->contract->refresh()));
    }

    public function test_an_advance_invoice_credits_the_customer_advance_account_not_revenue(): void
    {
        $invoice = $this->billedAdvance();

        $journal = $this->journalOf($invoice);

        $this->assertSame(222_000_000.0, $journal['1-1300']['debit']);   // 200jt + 11 % PPN
        $this->assertSame(200_000_000.0, $journal['2-1400']['credit']);  // uang muka, a liability
        $this->assertSame(22_000_000.0, $journal['2-1300']['credit']);   // PPN keluaran
        $this->assertArrayNotHasKey('4-1100', $journal);
    }

    public function test_an_advance_invoice_refuses_to_withhold_retention(): void
    {
        $this->expectException(LogicException::class);

        $this->invoices->create([
            'customer_id' => $this->contract->customer_id,
            'contract_id' => $this->contract->id,
            'description' => 'Uang muka dengan retensi',
            'dpp' => 200_000_000,
            'is_advance' => true,
            'retention_withheld' => 10_000_000,
        ]);
    }

    // ------------------------------------------------------------------ denda

    public function test_a_penalty_without_a_reason_is_refused(): void
    {
        $opname = $this->approvedOpname([$this->line('A.1', 500)]);

        try {
            $this->invoices->create([
                'measurement_id' => $opname->id,
                'penalty_amount' => 5_000_000,
            ]);
            $this->fail('Denda tanpa alasan seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('penalty_reason', $e->errors());
            $this->assertStringContainsString('alasan', implode(' ', $e->errors()['penalty_reason']));
        }
    }

    public function test_a_penalty_with_a_reason_is_deducted_from_the_claim(): void
    {
        $opname = $this->approvedOpname([$this->line('A.1', 500)]); // Rp 100.000.000

        $invoice = $this->invoices->create([
            'measurement_id' => $opname->id,
            'penalty_amount' => 5_000_000,
            'penalty_reason' => 'Keterlambatan 10 hari atas milestone struktur lantai 3.',
        ]);

        $this->assertSame('5000000.00', $invoice->penalty_amount);
        // 100.000.000 + 11.000.000 PPN - 5.000.000
        $this->assertSame('106000000.00', $invoice->total);
    }

    public function test_the_claim_journal_carries_the_recovery_and_the_penalty_and_balances(): void
    {
        $this->billedAdvance();

        $opname = $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);
        $invoice = $this->approve($this->invoices->create([
            'measurement_id' => $opname->id,
            'withhold_retention' => true, // 5 % of 260.000.000 = 13.000.000
            'penalty_amount' => 1_000_000,
            'penalty_reason' => 'Denda keterlambatan sesuai pasal 12 kontrak.',
        ]));

        $journal = $this->journalOf($invoice);

        $this->assertSame(13_000_000.0, $journal['1-1350']['debit']);   // retensi
        $this->assertSame(52_000_000.0, $journal['2-1400']['debit']);   // potongan uang muka
        $this->assertSame(1_000_000.0, $journal['7-2400']['debit']);    // denda
        $this->assertSame(260_000_000.0, $journal['4-1100']['credit']); // pendapatan = pekerjaan terukur

        $debits = array_sum(array_column($journal, 'debit'));
        $credits = array_sum(array_column($journal, 'credit'));
        $this->assertSame(round($debits, 2), round($credits, 2));

        $this->assertSame(DocumentStatus::Approved, $invoice->refresh()->status);
    }
}
