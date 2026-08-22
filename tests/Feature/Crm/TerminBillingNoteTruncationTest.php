<?php

namespace Tests\Feature\Crm;

use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Catatan konfirmasi milestone vs kolom description(500).
 *
 * createFromTermin memotong teks dasar dengan mb_substr(base, 0,
 * 500 − panjang catatan). Begitu catatannya sendiri melewati 500 karakter —
 * satu termin yang dirilis banyak milestone bernama panjang sudah cukup —
 * panjangnya menjadi NEGATIF: mb_substr lalu memotong base DARI BELAKANG
 * (dan mengosongkannya sama sekali), sementara catatan panjang itu tetap
 * ditempel utuh sehingga description tetap melewati 500. Di SQLite ini lolos
 * diam-diam; di MySQL strict penyimpanannya gagal — dua perilaku berbeda
 * untuk satu dokumen.
 *
 * Aturan pada kasus patologis itu: CATATAN yang dipangkas, base tidak pernah
 * dikosongkan — base adalah identitas dokumen ("Penagihan termin X kontrak
 * Y"), dan catatan yang terpotong masih terbaca sebagai override berikut
 * awal daftar milestone-nya.
 */
class TerminBillingNoteTruncationTest extends ErpTestCase
{
    use FinanceFixtures;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->contract = $this->makeContract($this->makeCustomer());
    }

    // -------------------------------------------------------------- fixtures

    private function termin(): ContractTermin
    {
        return $this->makeTermin($this->contract, 3, 'Progress 80%', 30, 3_000_000_000);
    }

    private function unachievedMilestone(ContractTermin $termin, string $name): Milestone
    {
        $project = Project::query()->firstOrCreate(
            ['contract_id' => $this->contract->id],
            ['name' => 'Proyek '.$this->contract->code, 'type' => 'construction', 'status' => 'active'],
        );

        return Milestone::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'due_date' => '2026-09-30',
            'achieved_date' => null,
            'termin_id' => $termin->id,
        ]);
    }

    // ------------------------------------------------------------- the guard

    public function test_an_overlong_confirmation_note_yields_instead_of_emptying_the_base(): void
    {
        $termin = $this->termin();

        // Enam milestone bernama 90 karakter — daftar nama di catatan
        // konfirmasi sendirian sudah melewati 500 karakter.
        foreach (range(1, 6) as $i) {
            $this->unachievedMilestone($termin, "Milestone {$i} ".str_repeat('progres fisik ', 6).'selesai');
        }

        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-08-01',
            'confirm_unachieved_milestone' => true,
        ]);

        // Muat dalam 500 dan tetap bernama: base utuh di depan, catatan
        // (terpotong) menyusul — bukan catatan telanjang tanpa dokumen.
        $this->assertLessThanOrEqual(500, mb_strlen($invoice->description));
        $this->assertStringStartsWith('Penagihan Progress 80%', $invoice->description);
        $this->assertStringContainsString('Konfirmasi: milestone', $invoice->description);
    }

    /** Jalur normal tidak bergeser: catatan pendek tetap utuh di ekor. */
    public function test_a_short_note_still_rides_whole_on_the_description(): void
    {
        $termin = $this->termin();
        $this->unachievedMilestone($termin, 'Progres fisik 80%');

        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-08-01',
            'confirm_unachieved_milestone' => true,
        ]);

        $this->assertStringStartsWith('Penagihan Progress 80%', $invoice->description);
        $this->assertStringEndsWith('belum tercapai — tetap ditagih.]', $invoice->description);
    }
}
