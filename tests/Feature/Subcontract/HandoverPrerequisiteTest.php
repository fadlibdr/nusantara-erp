<?php

namespace Tests\Feature\Subcontract;

use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Subcontract\Enums\HandoverType;
use Modules\Subcontract\Models\Handover;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Services\HandoverService;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * P3 — BAST subkon I/II and its two prerequisites.
 *
 * BAST I is not a formality: it starts the masa pemeliharaan, and the retention
 * we hold is the only leverage over defects found inside it. Signing one while
 * an opname is still unaccepted certifies work whose volume is in dispute, and
 * signing one after the retention has already been paid out certifies a
 * warranty period nothing secures. Both are afternoon-fixable — approve the
 * opname, or issue the BAST II this really is — which is exactly why both are
 * hard blocks rather than warnings (BastPrerequisiteService's own line).
 */
class HandoverPrerequisiteTest extends ErpTestCase
{
    use SubcontractFixtures;

    private HandoverService $handovers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handovers = app(HandoverService::class);
    }

    /** An approved SPK of Rp 100.000.000 on one line. */
    private function spk(array $attributes = []): Subcontract
    {
        return $this->makeApprovedSubcontract(
            array_merge(['value' => 100_000_000, 'defect_liability_until' => '2027-02-28'], $attributes),
            [['description' => 'Pekerjaan struktur', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 100_000_000, 'amount' => 100_000_000]],
        );
    }

    private function draftHandover(Subcontract $spk, string $type = 'bast1', string $date = '2026-09-01'): Handover
    {
        return $this->handovers->create([
            'subcontract_id' => $spk->id,
            'handover_type' => $type,
            'handover_date' => $date,
            'received_by' => 'Rina Wijaya',
        ]);
    }

    private function submitted(Subcontract $spk, string $type = 'bast1', string $date = '2026-09-01'): Handover
    {
        $handover = $this->draftHandover($spk, $type, $date);
        $handover->submit($this->actor());

        return $handover->refresh();
    }

    // ------------------------------------------------- opname terakhir approved

    public function test_bast1_is_refused_while_an_opname_is_still_waiting_for_approval(): void
    {
        $spk = $this->spk();
        $this->approvedClaim($spk, [$spk->items()->first()->id => 40]);

        $pending = $this->draftClaim($spk, [$spk->items()->first()->id => 70], [
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
        ]);
        $pending->submit($this->actor()); // submitted, not approved

        $handover = $this->submitted($spk);

        try {
            $this->handovers->approve($handover, $this->approver());
            $this->fail('BAST I atas SPK dengan opname belum disetujui seharusnya ditolak.');
        } catch (ValidationException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));

            $this->assertStringContainsString($pending->code, $message);
            $this->assertStringContainsString($spk->code, $message);
        }

        $this->assertSame(DocumentStatus::Submitted, $handover->refresh()->status);
    }

    public function test_bast1_is_refused_when_no_opname_has_ever_been_approved(): void
    {
        $spk = $this->spk();
        $handover = $this->submitted($spk);

        try {
            $this->handovers->approve($handover, $this->approver());
            $this->fail('BAST I tanpa opname disetujui seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'belum memiliki opname yang disetujui',
                implode(' ', array_merge(...array_values($e->errors()))),
            );
        }
    }

    public function test_a_rejected_opname_does_not_block_the_handover(): void
    {
        $spk = $this->spk();
        $this->approvedClaim($spk, [$spk->items()->first()->id => 100]);

        $dead = $this->draftClaim($spk, [$spk->items()->first()->id => 100], [
            'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        ]);
        $dead->submit($this->actor());
        $dead->reject($this->approver(), 'Salah periode.');

        $handover = $this->handovers->approve($this->submitted($spk), $this->approver());

        $this->assertSame(DocumentStatus::Approved, $handover->status);
    }

    // ------------------------------------------------------- retensi belum rilis

    public function test_bast1_is_refused_when_the_retention_was_already_released(): void
    {
        $spk = $this->spk();
        $this->approvedClaim($spk, [$spk->items()->first()->id => 100]);

        $spk->retentionReleases()->create([
            'release_date' => '2026-08-15',
            'amount' => 5_000_000,
            'notes' => 'Pelepasan retensi tahap 1.',
        ]);

        $handover = $this->submitted($spk);

        try {
            $this->handovers->approve($handover, $this->approver());
            $this->fail('BAST I setelah retensi dilepas seharusnya ditolak.');
        } catch (ValidationException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));

            $this->assertStringContainsString('retensi', mb_strtolower($message));
            $this->assertStringContainsString('15-08-2026', $message);
        }
    }

    public function test_bast1_goes_through_when_the_opname_is_approved_and_the_retention_is_still_held(): void
    {
        $spk = $this->spk();
        $this->approvedClaim($spk, [$spk->items()->first()->id => 100]);

        $handover = $this->handovers->approve($this->submitted($spk), $this->approver());

        $this->assertSame(DocumentStatus::Approved, $handover->status);
        $this->assertStringStartsWith('BSK/', $handover->code);
        // Copied from the SPK, never computed — the SPK carries no period length.
        $this->assertSame('2027-02-28', $handover->retention_release_due?->toDateString());
    }

    public function test_a_handover_on_an_spk_without_a_maintenance_end_date_leaves_the_cell_blank(): void
    {
        $spk = $this->spk(['defect_liability_until' => null]);
        $this->approvedClaim($spk, [$spk->items()->first()->id => 100]);

        $handover = $this->handovers->approve($this->submitted($spk), $this->approver());

        $this->assertNull($handover->retention_release_due);
    }

    // ------------------------------------------------------------------ BAST II

    public function test_bast2_is_refused_before_bast1_is_approved(): void
    {
        $spk = $this->spk();
        $this->approvedClaim($spk, [$spk->items()->first()->id => 100]);

        $handover = $this->submitted($spk, 'bast2', '2027-03-01');

        try {
            $this->handovers->approve($handover, $this->approver());
            $this->fail('BAST II tanpa BAST I seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'belum memiliki BAST I yang disetujui',
                implode(' ', array_merge(...array_values($e->errors()))),
            );
        }
    }

    public function test_bast2_goes_through_after_bast1_and_may_not_predate_it(): void
    {
        $spk = $this->spk();
        $this->approvedClaim($spk, [$spk->items()->first()->id => 100]);
        $this->handovers->approve($this->submitted($spk), $this->approver());

        $early = $this->submitted($spk, 'bast2', '2026-08-01'); // before the BAST I

        try {
            $this->handovers->approve($early, $this->approver());
            $this->fail('BAST II bertanggal sebelum BAST I seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('mendahului', implode(' ', array_merge(...array_values($e->errors()))));
        }

        // The ordinary repair path: reject it back to the desk, fix the date,
        // resubmit. A submitted document is not editable in place.
        $this->handovers->reject($early, $this->approver(), 'Tanggal salah.');
        $this->handovers->update($early->refresh(), ['handover_date' => '2027-03-01']);
        $early->refresh()->submit($this->actor());

        $fixed = $this->handovers->approve($early->refresh(), $this->approver());

        $this->assertSame(HandoverType::Bast2, $fixed->handover_type);
        $this->assertSame(DocumentStatus::Approved, $fixed->status);
    }

    // ----------------------------------------------------------------- the rest

    public function test_one_bast_of_each_type_per_spk(): void
    {
        $spk = $this->spk();
        $this->draftHandover($spk);

        $this->expectException(LogicException::class);
        $this->draftHandover($spk);
    }

    public function test_a_draft_is_refused_with_the_status_message_not_a_prerequisite_message(): void
    {
        $spk = $this->spk();
        $handover = $this->draftHandover($spk); // never submitted

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/while status is draft/');

        $this->handovers->approve($handover, $this->approver());
    }

    public function test_the_checklist_can_be_read_before_anybody_clicks(): void
    {
        $spk = $this->spk();
        $handover = $this->draftHandover($spk);

        $evaluation = $this->handovers->evaluate($handover);

        $this->assertFalse($evaluation['can_approve']);
        $this->assertSame('bast1', $evaluation['handover_type']);
        $this->assertSame(
            ['opname_terakhir_disetujui', 'retensi_belum_dilepas'],
            array_column($evaluation['checks'], 'key'),
        );
    }
}
