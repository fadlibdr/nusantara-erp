<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Channels\MailChannel;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Support\AlwaysFailingJob;

/**
 * Sistem › Antrean Gagal (Fase 0 / P-0b, T0b.4): tabel failed_jobs dari layar.
 *
 * Barisnya dibuat oleh pekerja SUNGGUHAN (queue:work database --once) atas job
 * yang memang gagal — bukan INSERT tangan — supaya bentuk baris yang dibaca
 * layar adalah bentuk yang ditulis kerangka kerja. Kirim ulang memindahkannya
 * kembali ke tabel jobs; Hapus hanya membuang catatannya.
 */
class QueueFailedJobsTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    private function userWith(string $permission): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('role-'.md5($permission), 'web');
        $role->givePermissionTo($permission);

        $user = User::query()->create([
            'name' => 'Pemegang '.$permission,
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** Antrekan satu job yang gagal dan biarkan pekerja menjatuhkannya ke failed_jobs. */
    private function failOne(string $reason = 'SMTP 550 mailbox unavailable'): string
    {
        AlwaysFailingJob::dispatch($reason);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'Job dengan tries=1 harus mendarat di failed_jobs setelah satu kali.');

        return (string) DB::table('failed_jobs')->value('uuid');
    }

    /**
     * Satu job DeliverNotification yang gagal lima kali lewat pekerja sungguhan:
     * barisnya `failed`, catatannya di failed_jobs — bahan uji penolakan di bawah.
     */
    private function failOneDelivery(): int
    {
        app(SettingService::class)->set('notifications.email_enabled', true);
        app()->instance(MailChannel::class, new class implements DeliveryChannel
        {
            public function name(): string
            {
                return NotificationDelivery::CHANNEL_EMAIL;
            }

            public function send(NotificationDelivery $delivery, Notification $notification): ?string
            {
                throw new RuntimeException('SMTP 550 5.1.1 mailbox unavailable');
            }
        });

        app(NotificationService::class)->system('core.update', 'Uji pengiriman', 'Isi.');

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::QUEUED, $delivery->status);

        foreach (range(1, 5) as $attempt) {
            DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);
        }

        $this->assertSame(NotificationDelivery::FAILED, $delivery->refresh()->status);
        $this->assertSame(1, DB::table('failed_jobs')->count());

        return $delivery->id;
    }

    public function test_a_failed_job_appears_in_the_list_with_its_first_exception_line(): void
    {
        $admin = $this->adminUser();
        $uuid = $this->failOne('SMTP 550 mailbox unavailable');

        $this->actingAs($admin, 'sanctum');
        $rows = $this->getJson('/api/core/queue/failed')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($uuid, $rows[0]['uuid']);
        $this->assertSame($uuid, $rows[0]['code']);
        $this->assertSame('default', $rows[0]['queue']);
        $this->assertSame(AlwaysFailingJob::class, $rows[0]['job']);
        $this->assertStringStartsWith('RuntimeException: SMTP 550 mailbox unavailable', $rows[0]['exception_excerpt']);
        $this->assertStringNotContainsString("\n", $rows[0]['exception_excerpt']);
        $this->assertArrayNotHasKey('exception', $rows[0], 'Daftar tidak membawa jejak tumpukan.');
        $this->assertNotNull($rows[0]['failed_at']);

        $detail = $this->getJson('/api/core/queue/failed/'.$rows[0]['id'])->assertOk()->json('data');
        $this->assertStringContainsString('#0 ', $detail['exception'], 'Detail membawa jejak tumpukannya.');
    }

    public function test_retry_moves_the_job_back_to_the_queue(): void
    {
        $admin = $this->adminUser();
        $this->failOne();
        $id = (int) DB::table('failed_jobs')->value('id');

        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/core/queue/failed/{$id}/retry")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Job dikembalikan ke antrean; pekerja akan mencobanya lagi.']);

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Catatan gagal dihapus setelah kembali ke antrean.');
        $this->assertSame(1, DB::table('jobs')->count(), 'Job harus kembali ke tabel jobs.');
        $this->assertSame(0, (int) DB::table('jobs')->value('attempts'), 'queue:retry mereset attempts.');

        // Dan pekerja benar-benar mencobanya lagi (job ini memang selalu gagal).
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    /**
     * Job pengiriman notifikasi (T0b.3) TIDAK boleh dikembalikan dari sini:
     * barisnya sudah `failed`, handle() melewati baris yang bukan `queued`, jadi
     * queue:retry "berhasil" tanpa mengirim apa pun sambil menghapus catatan
     * gagalnya (verifikasi P-0b, 5 Sep 2026). Ditolak 422 dengan penunjuk ke
     * layar yang benar; catatan gagalnya tetap ada; tidak ada job yang antre.
     */
    public function test_retry_of_a_delivery_job_is_refused_and_points_at_the_delivery_screen(): void
    {
        $admin = $this->adminUser();
        $deliveryId = $this->failOneDelivery();
        $id = (int) DB::table('failed_jobs')->value('id');

        $this->actingAs($admin, 'sanctum');
        $response = $this->postJson("/api/core/queue/failed/{$id}/retry")->assertStatus(422);
        $this->assertStringContainsString("pengiriman notifikasi #{$deliveryId}", $response->json('message'));
        $this->assertStringContainsString('Sistem › Pengiriman Notifikasi', $response->json('message'));

        $this->assertSame(1, DB::table('failed_jobs')->count(), 'Catatan gagal tidak boleh hilang bila tidak ada yang dikirim ulang.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Tidak ada job yang boleh kembali ke antrean.');
        $this->assertSame(NotificationDelivery::FAILED, NotificationDelivery::query()->sole()->status);

        // Daftarnya menyebut baris pengirimannya, dan SPA menyembunyikan tombolnya.
        $row = $this->getJson('/api/core/queue/failed')->assertOk()->json('data.0');
        $this->assertSame(DeliverNotification::class, $row['job']);
        $this->assertSame($deliveryId, $row['delivery_id']);
        $this->assertSame("Pengiriman #{$deliveryId} — kirim ulang dari Pengiriman Notifikasi", $row['retry_hint']);

        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $this->assertStringContainsString('when: (row) => row.delivery_id == null,', $schema);
        $this->assertStringContainsString("sub: 'retry_hint'", $schema);
    }

    /** Job lain (bukan pengiriman) tidak membawa delivery_id dan tetap bisa dikirim ulang. */
    public function test_a_non_delivery_job_carries_no_delivery_id(): void
    {
        $admin = $this->adminUser();
        $this->failOne();

        $this->actingAs($admin, 'sanctum');
        $row = $this->getJson('/api/core/queue/failed')->assertOk()->json('data.0');
        $this->assertNull($row['delivery_id']);
        $this->assertNull($row['retry_hint']);
    }

    public function test_delete_removes_only_the_record(): void
    {
        $admin = $this->adminUser();
        $this->failOne();
        $id = (int) DB::table('failed_jobs')->value('id');

        $this->actingAs($admin, 'sanctum');
        $this->deleteJson("/api/core/queue/failed/{$id}")->assertOk();

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count(), 'Hapus tidak menjalankan ulang job.');
        $this->getJson("/api/core/queue/failed/{$id}")->assertNotFound();
    }

    public function test_the_screen_is_gated_to_settings_holders(): void
    {
        $this->failOne();
        $id = (int) DB::table('failed_jobs')->value('id');

        $this->getJson('/api/core/queue/failed')->assertUnauthorized();

        $this->actingAs($this->userWith('fin.view'), 'sanctum');
        $this->getJson('/api/core/queue/failed')->assertForbidden();
        $this->postJson("/api/core/queue/failed/{$id}/retry")->assertForbidden();
        $this->deleteJson("/api/core/queue/failed/{$id}")->assertForbidden();

        // core.update boleh membaca dan mengirim ulang, tetapi menghapus butuh core.delete.
        $this->actingAs($this->userWith('core.update'), 'sanctum');
        $this->getJson('/api/core/queue/failed')->assertOk();
        $this->deleteJson("/api/core/queue/failed/{$id}")->assertForbidden();
    }

    public function test_health_counts_it_and_the_spa_carries_the_screen(): void
    {
        $admin = $this->adminUser();
        $this->failOne();

        $this->actingAs($admin, 'sanctum');
        $this->assertSame(1, $this->getJson('/api/core/health')->assertOk()->json('data.failed_jobs_count'));

        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $this->assertStringContainsString("  'core/queue/failed': {", $schema);
        $this->assertStringContainsString("route: 'r/core/queue/failed', perm: 'core.update'", $schema);
        $this->assertStringContainsString("api: 'core/queue/failed'", $schema);
    }
}
