<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Core\Models\RateHistoryEntry;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Services\QuotationService;
use Spatie\Permission\Models\Role;
use Tests\ErpTestCase;

/**
 * P8 — core_rate_history (D5). The table is RIWAYAT, nothing more: it records
 * that a tax rate changed, who changed it and when. The snapshot each document
 * takes at creation stays the single source of truth — these tests prove a
 * mid-life rate change lands in the history AND moves not one figure on any
 * existing document.
 */
class RateHistoryTest extends ErpTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminUser();
        $this->actingAs($this->admin, 'sanctum');
        Carbon::setTestNow('2026-07-15 09:00:00');
    }

    /** One line of 10 × Rp 1.000.000 = Rp 10.000.000 DPP. */
    private function createQuotation(string $title = 'Penawaran ELV'): Quotation
    {
        return app(QuotationService::class)->create([
            'customer_id' => Customer::query()->create(['name' => 'PT Bangun Sejahtera'])->id,
            'title' => $title,
            'scope_type' => 'system_integration',
            'items' => [
                ['description' => 'Instalasi CCTV', 'qty' => 10, 'unit' => 'titik', 'unit_price' => 1_000_000],
            ],
        ]);
    }

    // ---------------------------------------------------------------- D5: riwayat, bukan sumber angka

    public function test_a_rate_change_is_recorded_and_moves_no_existing_document_figure(): void
    {
        $before = $this->createQuotation('Sebelum kenaikan');

        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 12]])->assertOk();

        // The change is HISTORY — old and new effective rate, and who did it.
        $this->assertDatabaseHas('core_rate_history', [
            'rate_key' => 'tax.ppn_rate',
            'changed_by' => $this->admin->id,
        ]);
        $entry = RateHistoryEntry::query()->where('rate_key', 'tax.ppn_rate')->sole();
        $this->assertSame(11.0, (float) $entry->old_rate);
        $this->assertSame(12.0, (float) $entry->new_rate);

        // The existing document's snapshot is untouched, figure for figure.
        $before->refresh();
        $this->assertSame(11.0, (float) $before->ppn_rate);
        $this->assertSame(1_100_000.0, (float) $before->ppn_amount);
        $this->assertSame(11_100_000.0, (float) $before->total);

        // Only a NEW document sees the new rate — via its own snapshot.
        $after = $this->createQuotation('Sesudah kenaikan');
        $this->assertSame(12.0, (float) $after->ppn_rate);
    }

    public function test_resetting_a_rate_records_the_return_to_the_shipped_default(): void
    {
        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 12]])->assertOk();
        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => null]])->assertOk();

        $entries = RateHistoryEntry::query()->where('rate_key', 'tax.ppn_rate')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame([11.0, 12.0], [(float) $entries[0]->old_rate, (float) $entries[1]->old_rate]);
        $this->assertSame([12.0, 11.0], [(float) $entries[0]->new_rate, (float) $entries[1]->new_rate]);
    }

    public function test_a_write_that_changes_nothing_records_nothing(): void
    {
        // 11 is already the effective rate: no change happened, no history row.
        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 11]])->assertOk();

        $this->assertDatabaseCount('core_rate_history', 0);
    }

    public function test_only_ppn_and_pph_final_rates_are_tracked(): void
    {
        // Roadmap assumption: hanya PPN & PPh final. A numbering mask or an
        // approval threshold is not a tax rate and leaves no trace here.
        $this->putJson('/api/core/settings', ['settings' => [
            'documents.PO' => 'PO-{Y}-{N5}',
            'tax.pph_final_construction.pelaksanaan_bersertifikat' => 2.75,
            'tax.pph_final_umkm_rate' => 0.55,
        ]])->assertOk();

        $this->assertDatabaseCount('core_rate_history', 2);
        $this->assertDatabaseHas('core_rate_history', ['rate_key' => 'tax.pph_final_construction.pelaksanaan_bersertifikat']);
        $this->assertDatabaseHas('core_rate_history', ['rate_key' => 'tax.pph_final_umkm_rate']);
        $this->assertDatabaseMissing('core_rate_history', ['rate_key' => 'documents.PO']);
    }

    // ---------------------------------------------------------------- endpoint baca

    public function test_the_history_endpoint_lists_changes_newest_first(): void
    {
        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 12]])->assertOk();
        $this->putJson('/api/core/settings', ['settings' => ['tax.pph_final_umkm_rate' => 0.55]])->assertOk();

        $response = $this->getJson('/api/core/rate-history')->assertOk();
        $rows = $response->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame('tax.pph_final_umkm_rate', $rows[0]['rate_key']);
        $this->assertSame('tax.ppn_rate', $rows[1]['rate_key']);
        $this->assertSame('Test Admin', $rows[1]['changed_by_name']);

        // Filtered by key.
        $filtered = $this->getJson('/api/core/rate-history?key=tax.ppn_rate')->assertOk();
        $this->assertCount(1, $filtered->json('data'));
    }

    public function test_the_history_endpoint_requires_core_view(): void
    {
        $role = Role::findOrCreate('hanya-crm', 'web');
        $role->givePermissionTo('crm.view');
        $outsider = User::query()->create([
            'name' => 'Staf CRM',
            'email' => 'staf-crm@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $outsider->assignRole($role);

        $this->actingAs($outsider, 'sanctum');

        $this->getJson('/api/core/rate-history')->assertForbidden();
    }
}
