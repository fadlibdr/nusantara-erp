<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Crm\Enums\ActivityType;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\ActivityService;
use Tests\ErpTestCase;
use Tests\Unit\Crm\CrmFixtures;

/**
 * Aktivitas CRM (F-3 / T3.1) — register telepon/rapat/email/kunjungan/catatan.
 *
 * Yang dipaku di sini adalah tiga kebohongan yang mungkin terjadi tanpa satu
 * galat pun di layar: "selesai" yang diketik alih-alih dicap, aktivitas yang
 * digantungkan pada dokumen yang tidak ada (pekerjaan yang hilang tanpa jejak),
 * dan "lewat tanggal" yang dihitung dengan tanda yang salah sehingga baris hari
 * ini ikut memerah.
 */
class ActivityTest extends ErpTestCase
{
    use CrmFixtures;

    private function makeLead(array $attributes = []): Lead
    {
        return Lead::query()->create(array_merge([
            'name' => 'Rudi Hartanto',
            'company_name' => 'PT Bangun Sejahtera',
            'status' => LeadStatus::Contacted,
        ], $attributes));
    }

    private function service(): ActivityService
    {
        return app(ActivityService::class);
    }

    public function test_an_activity_is_recorded_against_a_lead(): void
    {
        $lead = $this->makeLead();

        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/activities', [
                'document_type' => 'lead',
                'document_id' => $lead->id,
                'type' => 'call',
                'subject' => 'Telepon konfirmasi kebutuhan',
                'due_at' => '2026-09-15',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.type_label', 'Telepon')
            ->assertJsonPath('data.due_at', '2026-09-15')
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.owner_user_name', 'Belum ditugaskan');

        $this->assertSame(1, Activity::query()->count());
    }

    /** Induk yang tidak ada ditolak dengan kalimat yang menyebut dokumennya. */
    public function test_an_activity_cannot_hang_on_a_document_that_does_not_exist(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/activities', [
                'document_type' => 'lead',
                'document_id' => 9999,
                'type' => 'call',
                'subject' => 'Telepon ke prospek hantu',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.document_id.0', 'Prospek #9999 tidak ditemukan, jadi aktivitas ini tidak bisa digantungkan padanya.');
    }

    /**
     * PROSPEK YANG SUDAH DIHAPUS TIDAK ADA — dan itu berlaku untuk aktivitas
     * baru maupun untuk memindahkan aktivitas lama ke sana.
     *
     * ActivityDocuments::find() sengaja TIDAK memakai withTrashed(); aturannya
     * ditulis di docblock-nya sejak hari pertama. Sampai 8 Sep 2026 tidak ada
     * satu pun uji yang memegangnya: mengganti `find($id)` menjadi
     * `withTrashed()->find($id)` di salinan repo meninggalkan 294 uji
     * tests/Feature/Crm hijau seluruhnya — sebuah aturan yang hanya hidup di
     * komentar, dan pekerjaan yang digantungkan pada prospek terhapus tidak
     * akan pernah muncul di layar mana pun.
     */
    public function test_a_soft_deleted_parent_does_not_exist(): void
    {
        $lead = $this->makeLead();
        $other = $this->makeLead(['name' => 'Prospek kedua']);

        // Satu aktivitas yang sudah ada, dibuat SELAGI induknya masih ada.
        $activity = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $other->id,
            'type' => 'call', 'subject' => 'Telepon yang sudah tercatat',
        ]);

        $lead->delete();
        $this->assertSoftDeleted('crm_leads', ['id' => $lead->id]);

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/api/crm/activities', [
                'document_type' => 'lead',
                'document_id' => $lead->id,
                'type' => 'call',
                'subject' => 'Telepon ke prospek yang sudah dihapus',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.document_id.0',
                "Prospek #{$lead->id} tidak ditemukan, jadi aktivitas ini tidak bisa digantungkan padanya.");

        // Memindahkannya ke sana ditolak dengan kalimat yang sama — dan
        // aktivitasnya tetap menggantung di tempat asalnya.
        $this->actingAs($admin)
            ->putJson("/api/crm/activities/{$activity->id}", ['document_id' => $lead->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.document_id.0',
                "Prospek #{$lead->id} tidak ditemukan, jadi aktivitas ini tidak bisa digantungkan padanya.");

        $this->assertSame($other->id, (int) $activity->refresh()->document_id);
        $this->assertSame(0, Activity::query()->where('document_id', $lead->id)->count());
    }

    public function test_an_unknown_document_type_is_refused(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/activities', [
                'document_type' => 'App\\Models\\User',
                'document_id' => 1,
                'type' => 'call',
                'subject' => 'Jenis dokumen karangan',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_type');
    }

    /** "Selesai" dicap server; mengetiknya ditolak, tidak diabaikan diam-diam. */
    public function test_done_at_cannot_be_typed(): void
    {
        $lead = $this->makeLead();

        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/activities', [
                'document_type' => 'lead',
                'document_id' => $lead->id,
                'type' => 'call',
                'subject' => 'Telepon yang mengaku sudah selesai',
                'done_at' => '2026-01-01 08:00:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.done_at.0', 'Tanggal selesai dicatat server saat aktivitas ditandai selesai, bukan diketik.');
    }

    public function test_marking_done_stamps_the_moment_and_the_person(): void
    {
        $lead = $this->makeLead();
        $activity = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => ActivityType::Call->value, 'subject' => 'Telepon pertama', 'due_at' => '2026-09-10',
        ]);

        $admin = $this->adminUser();
        $this->actingAs($admin)
            ->postJson("/api/crm/activities/{$activity->id}/done")
            ->assertStatus(200)
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.done_by_name', $admin->name);

        $this->assertNotNull($activity->refresh()->done_at);
        $this->assertSame($admin->id, (int) $activity->done_by_id);
    }

    /** Dua orang menekan tombol yang sama: yang kedua ditolak, bukan menimpa. */
    public function test_marking_done_twice_is_refused_naming_when_and_who(): void
    {
        $lead = $this->makeLead();
        $activity = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Telepon kedua',
        ]);

        $admin = $this->adminUser();
        $this->actingAs($admin)->postJson("/api/crm/activities/{$activity->id}/done")->assertStatus(200);

        $response = $this->actingAs($admin)
            ->postJson("/api/crm/activities/{$activity->id}/done")
            ->assertStatus(422);

        $this->assertStringContainsString('sudah ditandai selesai pada', $response->json('errors.done_at.0'));
        $this->assertStringContainsString($admin->name, $response->json('errors.done_at.0'));
    }

    public function test_a_done_activity_can_be_reopened(): void
    {
        $lead = $this->makeLead();
        $activity = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'visit', 'subject' => 'Kunjungan lokasi',
        ]);

        $admin = $this->adminUser();
        $this->actingAs($admin)->postJson("/api/crm/activities/{$activity->id}/done")->assertStatus(200);
        $this->actingAs($admin)->postJson("/api/crm/activities/{$activity->id}/reopen")
            ->assertStatus(200)
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.done_at', null);

        $this->assertNull($activity->refresh()->done_by_id);
    }

    /**
     * Jatuh tempo HARI INI belum terlambat.
     *
     * Batas '<' hari ini, bukan '<=': tanpa itu setiap aktivitas yang justru
     * dikerjakan hari ini tampil merah pada pagi hari yang sama.
     */
    public function test_overdue_starts_the_day_after_the_due_date(): void
    {
        Carbon::setTestNow('2026-09-08 09:00:00');
        $lead = $this->makeLead();

        $today = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Jatuh tempo hari ini', 'due_at' => '2026-09-08',
        ]);
        $yesterday = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Jatuh tempo kemarin', 'due_at' => '2026-09-07',
        ]);
        $undated = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'note', 'subject' => 'Catatan tanpa tanggal',
        ]);

        $this->assertFalse($today->isOverdue());
        $this->assertTrue($yesterday->isOverdue());
        $this->assertFalse($undated->isOverdue(), 'catatan tanpa tanggal tidak pernah terlambat');

        $rows = $this->actingAs($this->adminUser())
            ->getJson('/api/crm/activities?state=overdue')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(['Jatuh tempo kemarin'], array_column($rows, 'subject'));

        Carbon::setTestNow();
    }

    /** Pemilik yang barisnya hilang bukan "Belum ditugaskan" — itu data rusak. */
    public function test_an_owner_whose_user_row_is_gone_is_not_reported_as_unassigned(): void
    {
        $lead = $this->makeLead();
        $sales = User::query()->create([
            'name' => 'Sales Sementara', 'email' => 'sales-temp@test.local',
            'password' => 'password', 'is_active' => true,
        ]);

        $activity = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Telepon milik sales yang kemudian dihapus',
            'owner_user_id' => $sales->id,
        ]);

        $ownerId = $sales->id;
        $sales->forceDelete();

        $this->actingAs($this->adminUser())
            ->getJson("/api/crm/activities/{$activity->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.owner_user_name', "Pengguna #{$ownerId} (tidak ditemukan)");
    }

    public function test_deleting_an_activity_keeps_the_row_for_the_trail(): void
    {
        $lead = $this->makeLead();
        $activity = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'email', 'subject' => 'Kirim proposal awal',
        ]);

        $this->actingAs($this->adminUser())
            ->deleteJson("/api/crm/activities/{$activity->id}")
            ->assertStatus(200);

        $this->assertSame(0, Activity::query()->count());
        $this->assertSame(1, Activity::withTrashed()->count());
    }
}
