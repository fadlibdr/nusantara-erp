<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\ActivityService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * `GET crm/pipeline/board` (F-3 / T3.6) — muatan papan kanban prospek.
 *
 * Yang dipaku: papan mengaku berapa banyak yang TIDAK digambar, kartu paling
 * mendesak ada di atas, kartu tanpa pemilik berbunyi "Belum ditugaskan", dan
 * kartu tanpa aktivitas DIAM alih-alih memajang "0 aktivitas" — angka nol
 * palsu yang mengajari pembacanya mengabaikan barisnya.
 */
class PipelineBoardTest extends ErpTestCase
{
    private function makeLead(array $attributes = []): Lead
    {
        return Lead::query()->create(array_merge([
            'name' => 'Prospek Uji',
            'status' => LeadStatus::New,
        ], $attributes));
    }

    private function board(?User $actor = null, string $query = ''): array
    {
        return $this->actingAs($actor ?? $this->adminUser())
            ->getJson('/api/crm/pipeline/board'.$query)
            ->assertStatus(200)
            ->json();
    }

    public function test_every_status_gets_a_lane_with_its_true_count(): void
    {
        $this->makeLead(['status' => LeadStatus::New]);
        $this->makeLead(['status' => LeadStatus::Qualified]);
        $this->makeLead(['status' => LeadStatus::Qualified]);

        $lanes = collect($this->board()['meta']['lanes'])->keyBy('status');

        $this->assertSame(
            ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'],
            $lanes->keys()->all(),
            'papan menggambar semua tahap — termasuk yang kosong',
        );
        $this->assertSame(1, $lanes['new']['count']);
        $this->assertSame(2, $lanes['qualified']['count']);
        $this->assertSame(0, $lanes['won']['count']);
        $this->assertSame('Terkualifikasi', $lanes['qualified']['label']);
    }

    /** Kolom yang dipenggal MENGAKU: shown < count, dan papannya bisa bilang. */
    public function test_a_capped_lane_reports_how_many_are_not_drawn(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->makeLead(['status' => LeadStatus::New, 'name' => "Prospek {$i}"]);
        }

        $payload = $this->board(query: '?per_lane=5');
        $lane = collect($payload['meta']['lanes'])->firstWhere('status', 'new');

        $this->assertSame(7, $lane['count']);
        $this->assertSame(5, $lane['shown']);
        $this->assertCount(5, array_filter($payload['data'], fn ($row) => $row['status'] === 'new'));
    }

    /**
     * Yang paling mendesak di atas; yang tanpa tanggal di bawahnya — dan BUKAN
     * dianggap jatuh tempo tahun 1970.
     */
    public function test_the_most_urgent_card_stands_first(): void
    {
        $activities = app(ActivityService::class);

        $this->makeLead(['name' => 'Tanpa rencana']);
        $jauh = $this->makeLead(['name' => 'Rencana bulan depan']);
        $dekat = $this->makeLead(['name' => 'Rencana besok']);

        $activities->create(['document_type' => 'lead', 'document_id' => $jauh->id,
            'type' => 'call', 'subject' => 'Telepon nanti', 'due_at' => '2026-10-20']);
        $activities->create(['document_type' => 'lead', 'document_id' => $dekat->id,
            'type' => 'call', 'subject' => 'Telepon besok', 'due_at' => '2026-09-09']);

        $names = array_column(array_filter($this->board()['data'], fn ($row) => $row['status'] === 'new'), 'name');

        $this->assertSame(['Rencana besok', 'Rencana bulan depan', 'Tanpa rencana'], $names);
    }

    public function test_an_unowned_card_reads_belum_ditugaskan(): void
    {
        $this->makeLead();

        $card = $this->board()['data'][0];

        $this->assertSame('Belum ditugaskan', $card['owner_user_name']);
    }

    /** Kartu tanpa aktivitas tidak memajang "0 aktivitas". */
    public function test_a_card_without_activities_says_nothing_instead_of_zero(): void
    {
        $this->makeLead();

        $card = $this->board()['data'][0];

        $this->assertNull($card['activity_note']);
        $this->assertSame(0, $card['open_activities_count']);
    }

    public function test_a_card_counts_its_overdue_activities(): void
    {
        Carbon::setTestNow('2026-09-08 09:00:00');

        $lead = $this->makeLead();
        $activities = app(ActivityService::class);
        $activities->create(['document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Telat', 'due_at' => '2026-09-01']);
        $activities->create(['document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'visit', 'subject' => 'Telat juga', 'due_at' => '2026-09-05']);
        $activities->create(['document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Hari ini, belum telat', 'due_at' => '2026-09-08']);

        $card = $this->board()['data'][0];

        $this->assertSame(2, $card['overdue_activities_count']);
        $this->assertSame(3, $card['open_activities_count']);
        $this->assertSame('2 aktivitas lewat tanggal', $card['activity_note']);

        Carbon::setTestNow();
    }

    /** Aktivitas yang sudah selesai tidak lagi terhitung di kartu. */
    public function test_a_done_activity_leaves_the_card_quiet(): void
    {
        $lead = $this->makeLead();
        $activities = app(ActivityService::class);
        $activity = $activities->create(['document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Sudah ditelepon', 'due_at' => '2026-09-01']);
        $admin = $this->adminUser();
        $activities->markDone($activity, $admin);

        $card = $this->board($admin)['data'][0];

        $this->assertNull($card['activity_note']);
        $this->assertSame(0, $card['open_activities_count']);
    }

    public function test_the_board_needs_crm_view(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('tanpa-crm', 'web');
        $role->syncPermissions(['fin.view']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Tanpa CRM', 'email' => 'tanpa-crm@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole('tanpa-crm');

        $this->actingAs($user)->getJson('/api/crm/pipeline/board')->assertStatus(403);
    }
}
