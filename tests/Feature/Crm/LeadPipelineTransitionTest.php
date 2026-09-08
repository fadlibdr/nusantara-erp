<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Enums\LeadMove;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadStatusChange;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Services\LeadPipelineService;
use Modules\Crm\Services\QuotationService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Transisi pipeline (F-3 / T3.5): maju bebas, mundur beralasan, menang/kalah
 * HANYA lewat penawaran — dan pintunya cuma satu.
 *
 * Sampai paket ini status prospek adalah kolom biasa di formulir: satu PUT
 * dengan {"status":"won"} memenangkan sebuah prospek tanpa penawaran, tanpa
 * nilai, tanpa tanggal keputusan — sementara win-rate per sales dihitung dari
 * kolom itu. Uji-uji di bawah memaku aturannya DAN penutupan pintu keduanya.
 */
class LeadPipelineTransitionTest extends ErpTestCase
{
    private function makeLead(array $attributes = []): Lead
    {
        return Lead::query()->create(array_merge([
            'name' => 'Rudi Hartanto',
            'company_name' => 'PT Bangun Sejahtera',
            'status' => LeadStatus::New,
        ], $attributes));
    }

    private function pipeline(): LeadPipelineService
    {
        return app(LeadPipelineService::class);
    }

    // ------------------------------------------------------------ matriksnya

    /** Matriks lengkap 6 × 6, dibaca dari enum-nya sendiri. */
    public function test_the_transition_matrix_is_what_it_says_it_is(): void
    {
        $expected = [
            // dari      => [ke => putusan]
            'new' => ['new' => 'sama', 'contacted' => 'maju', 'qualified' => 'maju', 'proposal' => 'maju', 'won' => 'lewat_penawaran', 'lost' => 'lewat_penawaran'],
            'contacted' => ['new' => 'mundur', 'contacted' => 'sama', 'qualified' => 'maju', 'proposal' => 'maju', 'won' => 'lewat_penawaran', 'lost' => 'lewat_penawaran'],
            'qualified' => ['new' => 'mundur', 'contacted' => 'mundur', 'qualified' => 'sama', 'proposal' => 'maju', 'won' => 'lewat_penawaran', 'lost' => 'lewat_penawaran'],
            'proposal' => ['new' => 'mundur', 'contacted' => 'mundur', 'qualified' => 'mundur', 'proposal' => 'sama', 'won' => 'lewat_penawaran', 'lost' => 'lewat_penawaran'],
            'won' => ['new' => 'terkunci', 'contacted' => 'terkunci', 'qualified' => 'terkunci', 'proposal' => 'terkunci', 'won' => 'sama', 'lost' => 'lewat_penawaran'],
            'lost' => ['new' => 'terkunci', 'contacted' => 'terkunci', 'qualified' => 'terkunci', 'proposal' => 'terkunci', 'won' => 'lewat_penawaran', 'lost' => 'sama'],
        ];

        foreach ($expected as $from => $row) {
            foreach ($row as $to => $verdict) {
                $this->assertSame(
                    LeadMove::from($verdict),
                    LeadStatus::from($from)->canMoveTo(LeadStatus::from($to)),
                    "transisi {$from} → {$to}",
                );
            }
        }
    }

    // ---------------------------------------------------------------- layanan

    public function test_moving_forward_needs_no_reason(): void
    {
        $lead = $this->makeLead();

        $this->pipeline()->move($lead, LeadStatus::Qualified);

        $this->assertSame(LeadStatus::Qualified, $lead->refresh()->status);
        $this->assertSame('maju', LeadStatusChange::query()->latest('id')->value('direction'));
    }

    public function test_moving_backward_without_a_reason_is_refused_asking_for_one(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Proposal]);

        try {
            $this->pipeline()->move($lead, LeadStatus::Contacted);
            $this->fail('mundur tanpa alasan seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
            $this->assertStringContainsString('alasannya', $e->errors()['reason'][0]);
        }

        $this->assertSame(LeadStatus::Proposal, $lead->refresh()->status, 'statusnya tidak boleh bergeser');
        $this->assertSame(0, LeadStatusChange::query()->count());
    }

    /** Alasan satu kata bukan alasan. */
    public function test_a_too_short_reason_is_refused(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Qualified]);

        $this->expectException(ValidationException::class);
        $this->pipeline()->move($lead, LeadStatus::New, 'ups');
    }

    public function test_a_backward_move_stores_its_reason_in_the_history(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Proposal]);
        $actor = $this->adminUser();

        $this->pipeline()->move($lead, LeadStatus::Qualified, 'Anggaran pelanggan ditunda ke tahun depan', $actor);

        $change = LeadStatusChange::query()->latest('id')->firstOrFail();
        $this->assertSame('mundur', $change->direction);
        $this->assertSame(LeadStatus::Proposal, $change->from_status);
        $this->assertSame(LeadStatus::Qualified, $change->to_status);
        $this->assertSame('Anggaran pelanggan ditunda ke tahun depan', $change->reason);
        $this->assertSame($actor->id, (int) $change->user_id);
    }

    // ------------------------------------------------------- menang/kalah

    /** Diseret ke Menang: ditolak, dan kalimatnya menyebut penawarannya. */
    public function test_moving_to_won_is_refused_naming_the_quotation_route(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Proposal]);
        $customer = Customer::query()->create(['name' => 'PT Uji', 'status' => 'active']);
        $quotation = Quotation::query()->create([
            'customer_id' => $customer->id, 'lead_id' => $lead->id,
            'title' => 'Penawaran uji', 'scope_type' => 'system_integration',
        ]);

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/crm/leads/{$lead->id}/pipeline", ['status' => 'won'])
            ->assertStatus(422);

        $message = $response->json('errors.status.0');
        $this->assertStringContainsString('Tandai Menang', $message);
        $this->assertStringContainsString($quotation->code, $message);
        $this->assertSame(LeadStatus::Proposal, $lead->refresh()->status);
    }

    /** Tanpa penawaran, kalimatnya mengakui itu alih-alih menyebut kode hantu. */
    public function test_without_a_quotation_the_refusal_says_so(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Qualified]);

        $message = $this->actingAs($this->adminUser())
            ->postJson("/api/crm/leads/{$lead->id}/pipeline", ['status' => 'lost'])
            ->assertStatus(422)
            ->json('errors.status.0');

        $this->assertStringContainsString('belum punya penawaran', $message);
        $this->assertStringContainsString('Tandai Kalah', $message);
    }

    /** Prospek yang sudah menang tidak bisa dikembalikan ke tahap mana pun. */
    public function test_a_closed_lead_cannot_be_dragged_back(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Won]);

        $message = $this->actingAs($this->adminUser())
            ->postJson("/api/crm/leads/{$lead->id}/pipeline", ['status' => 'proposal'])
            ->assertStatus(422)
            ->json('errors.status.0');

        $this->assertStringContainsString('mengikuti penawarannya', $message);
        $this->assertSame(LeadStatus::Won, $lead->refresh()->status);
    }

    /** Jalan yang SAH menuju Menang: keputusan penawaran, dan ia tercatat. */
    public function test_the_quotation_decision_moves_the_lead_and_is_recorded(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Proposal]);
        $customer = Customer::query()->create(['name' => 'PT Uji', 'status' => 'active']);
        $quotation = Quotation::query()->create([
            'customer_id' => $customer->id, 'lead_id' => $lead->id,
            'title' => 'Penawaran uji', 'scope_type' => 'system_integration',
        ]);
        $quotation->forceFill(['status' => DocumentStatus::Approved, 'dpp' => 750_000_000])->save();

        app(QuotationService::class)->markWon($quotation->refresh());

        $this->assertSame(LeadStatus::Won, $lead->refresh()->status);

        $change = LeadStatusChange::query()->latest('id')->firstOrFail();
        $this->assertSame('penawaran', $change->direction);
        $this->assertSame('quotation', $change->source);
        $this->assertSame($quotation->code, $change->document_code);
        $this->assertNull($change->user_id, 'keputusan datang dari dokumennya, bukan dari sunting seseorang');
    }

    // --------------------------------------------------------- pintu keduanya

    /** PUT prospek TIDAK boleh menggeser tahap — pintu kedua ditutup. */
    public function test_the_lead_form_cannot_move_the_stage(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::New]);

        $message = $this->actingAs($this->adminUser())
            ->putJson("/api/crm/leads/{$lead->id}", ['status' => 'won'])
            ->assertStatus(422)
            ->json('errors.status.0');

        $this->assertStringContainsString('Ubah Tahap', $message);
        $this->assertSame(LeadStatus::New, $lead->refresh()->status);
    }

    /** …dan sebuah prospek tidak bisa DILAHIRKAN sebagai Menang. */
    public function test_a_lead_cannot_be_created_already_won(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/leads', ['name' => 'Prospek instan', 'status' => 'won'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_lead_can_be_created_in_an_open_stage(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/leads', ['name' => 'Undangan tender', 'status' => 'qualified'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'qualified');
    }

    // ------------------------------------------------------------- riwayatnya

    /** Riwayat tahap tampil pada layar dokumen, dengan alasan dan pelakunya. */
    public function test_the_history_rides_the_document_screen(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Qualified]);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson("/api/crm/leads/{$lead->id}/pipeline", ['status' => 'contacted', 'reason' => 'Kontak PIC berganti, kualifikasi diulang'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'contacted');

        $history = $this->actingAs($admin)
            ->getJson("/api/crm/leads/{$lead->id}")
            ->assertStatus(200)
            ->json('data.status_history');

        $this->assertCount(1, $history);
        $this->assertSame('Mundur', $history[0]['direction_label']);
        $this->assertSame('Kontak PIC berganti, kualifikasi diulang', $history[0]['reason']);
        $this->assertSame($admin->name, $history[0]['user_name']);
    }

    /** Perpindahan tahap butuh crm.update — bukan sekadar bisa melihat. */
    public function test_moving_the_stage_needs_the_update_permission(): void
    {
        $lead = $this->makeLead();
        $viewer = $this->userWithPermissions(['crm.view']);

        $this->actingAs($viewer)
            ->postJson("/api/crm/leads/{$lead->id}/pipeline", ['status' => 'contacted'])
            ->assertStatus(403);
    }

    private function userWithPermissions(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('penonton-crm', 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Penonton CRM', 'email' => 'penonton@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole('penonton-crm');

        return $user;
    }
}
