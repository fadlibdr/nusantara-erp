<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Channels\MailChannel;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Mail\ApprovalNotificationMail;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Models\ApBill;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Kotak keluar pengiriman notifikasi (Fase 0 / P-0b, T0b.3).
 *
 * Satu aturan yang dipaku dari semua sisi: pengiriman yang tidak terjadi
 * TERLIHAT — `queued` tanpa pekerja, `skipped` dengan alasannya, `failed`
 * dengan pesan penyedia — dan tidak satu pun jalur menandai `sent` tanpa
 * penyedia menerimanya. Sisi lainnya tetap: antrean yang mati tidak boleh
 * membatalkan pengajuan yang sedang dilaporkan.
 */
class NotificationDeliveryTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWith(string $permission, string $name, ?string $email = null): User
    {
        $role = Role::findOrCreate('role-'.md5($permission.$name), 'web');
        $role->givePermissionTo($permission);

        $user = User::query()->create([
            'name' => $name,
            'email' => $email ?? str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function bill(): ApBill
    {
        return $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'description' => 'Material',
            'dpp' => 50_000_000,
            'bill_date' => '2026-03-10',
            'vendor_invoice_no' => 'INV-'.str()->random(5),
        ]);
    }

    private function emailOn(): void
    {
        app(SettingService::class)->set('notifications.email_enabled', true);
    }

    /** Kanal e-mail yang selalu ditolak penyedia — pesan itulah yang harus tersimpan. */
    private function bindRefusingMailChannel(string $message = 'SMTP 550 5.1.1 mailbox unavailable'): void
    {
        app()->instance(MailChannel::class, new class($message) implements DeliveryChannel
        {
            public function __construct(private readonly string $message) {}

            public function name(): string
            {
                return NotificationDelivery::CHANNEL_EMAIL;
            }

            public function send(NotificationDelivery $delivery, Notification $notification): ?string
            {
                throw new RuntimeException($this->message);
            }
        });
    }

    private function delivery(User $recipient, string $status, array $overrides = []): NotificationDelivery
    {
        $notification = Notification::query()->create([
            'user_id' => $recipient->id,
            'event' => Notification::SUBMITTED,
            'title' => 'Tagihan vendor INV-1 menunggu persetujuan',
            'body' => 'Uji.',
        ]);

        return NotificationDelivery::query()->create($overrides + [
            'notification_id' => $notification->id,
            'channel' => NotificationDelivery::CHANNEL_EMAIL,
            'recipient' => $recipient->email,
            'status' => $status,
            'attempts' => $status === NotificationDelivery::FAILED ? 5 : 0,
            'error' => $status === NotificationDelivery::FAILED ? 'SMTP 550' : null,
        ]);
    }

    // --------------------------------------------------------------- outbox

    public function test_submitting_with_email_on_writes_a_queued_row_and_dispatches_the_job(): void
    {
        Queue::fake();
        $this->emailOn();
        $approver = $this->userWith('fin.approve', 'Direktur Keuangan');

        $this->bill()->submit($this->userWith('fin.create', 'Staf AP'));

        $row = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame(NotificationDelivery::CHANNEL_EMAIL, $row->channel);
        $this->assertSame($approver->email, $row->recipient);
        $this->assertSame(0, $row->attempts);
        $this->assertSame($approver->id, $row->notification->user_id);

        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->deliveryId === $row->id);
    }

    public function test_email_disabled_writes_a_skipped_row_with_the_reason_and_no_job(): void
    {
        Queue::fake();
        $this->userWith('fin.approve', 'Direktur');

        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        $row = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);
        $this->assertSame('E-mail dinonaktifkan di Pengaturan.', $row->error);
        $this->assertNull($row->sent_at);

        Queue::assertNothingPushed();
        // Kanal kebenaran tetap ditulis, sinkron.
        $this->assertSame(1, Notification::query()->where('event', Notification::SUBMITTED)->count());
    }

    public function test_a_recipient_without_an_address_is_skipped_with_the_reason(): void
    {
        Queue::fake();
        $this->emailOn();
        $this->userWith('fin.approve', 'Tanpa Alamat', '');

        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        $row = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);
        $this->assertSame('Penerima tidak punya alamat e-mail.', $row->error);
        Queue::assertNothingPushed();
    }

    /** Antrean mati: pengajuan tetap jadi, barisnya tetap `queued` — terlihat, bukan hilang. */
    public function test_a_dead_queue_leaves_the_queued_row_and_never_rolls_back_the_submission(): void
    {
        $this->emailOn();
        $this->userWith('fin.approve', 'Direktur');
        config(['queue.default' => 'koneksi-yang-tidak-ada']);

        $bill = $this->bill();
        $bill->submit($this->userWith('fin.create', 'Staf'));

        $this->assertSame('submitted', $bill->refresh()->status->value);
        $row = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame(0, $row->attempts);
    }

    // ------------------------------------------------------------------ job

    public function test_the_job_sends_the_mail_and_marks_the_row_sent(): void
    {
        Mail::fake();
        $this->emailOn();
        $approver = $this->userWith('fin.approve', 'Direktur');

        // QUEUE_CONNECTION=sync di phpunit.xml: job berjalan di dalam dispatch.
        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        $row = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::SENT, $row->status);
        $this->assertNotNull($row->sent_at);
        $this->assertSame(1, $row->attempts);
        $this->assertNull($row->error);

        Mail::assertSent(ApprovalNotificationMail::class, fn (ApprovalNotificationMail $mail) => $mail->hasTo($approver->email)
            && str_contains($mail->title, 'menunggu persetujuan'));
    }

    /**
     * Lima percobaan lewat pekerja SUNGGUHAN (queue:work database --once), bukan
     * handle() yang dipanggil tangan: yang diuji adalah kontrak job dengan
     * pekerja — $tries, backoff, failed() — bukan aritmetika kita sendiri.
     */
    public function test_five_refusals_from_the_provider_end_as_failed_with_its_message(): void
    {
        $this->emailOn();
        $this->bindRefusingMailChannel('SMTP 550 5.1.1 mailbox unavailable');
        config(['queue.default' => 'database']);
        $this->userWith('fin.approve', 'Direktur');

        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        $row = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame(1, DB::table('jobs')->count(), 'Job harus mendarat di tabel jobs.');

        foreach (range(1, 5) as $attempt) {
            // Backoff 60/300/900/3600 s menunda job; pekerja uji tidak menunggu.
            DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

            $row->refresh();
            $this->assertSame($attempt, $row->attempts, "Percobaan ke-{$attempt} harus tercatat.");
            $this->assertStringContainsString('SMTP 550', (string) $row->error);

            if ($attempt < 5) {
                $this->assertSame(NotificationDelivery::QUEUED, $row->status);
                $this->assertNotNull($row->next_attempt_at, "Setelah percobaan ke-{$attempt} harus ada jadwal berikutnya.");
                $this->assertSame(1, DB::table('jobs')->count(), 'Job harus dilepas kembali ke antrean, bukan hilang.');
            }
        }

        $this->assertSame(NotificationDelivery::FAILED, $row->status);
        $this->assertSame('SMTP 550 5.1.1 mailbox unavailable', $row->error);
        $this->assertNull($row->next_attempt_at);
        $this->assertNull($row->sent_at);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'Percobaan terakhir harus mendarat di failed_jobs (layar T0b.4).');
    }

    /** Baris yang sudah `sent` tidak dikirim lagi walau job-nya dijalankan dua kali. */
    public function test_a_sent_row_is_not_delivered_twice(): void
    {
        Mail::fake();
        $this->emailOn();
        $row = $this->delivery($this->userWith('fin.approve', 'Direktur'), NotificationDelivery::SENT, ['attempts' => 1, 'sent_at' => now()]);

        (new DeliverNotification($row->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame(1, $row->refresh()->attempts);
    }

    // ---------------------------------------------------------------- retry

    public function test_retry_requeues_a_failed_delivery(): void
    {
        Queue::fake();
        $this->emailOn();
        $admin = $this->adminUser();
        $row = $this->delivery($this->userWith('fin.approve', 'Direktur'), NotificationDelivery::FAILED);

        $this->actingAs($admin, 'sanctum');
        $response = $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertOk();

        $this->assertSame(NotificationDelivery::QUEUED, $response->json('data.status'));
        $this->assertNull($response->json('data.error'));
        // Riwayat percobaan tidak dihapus.
        $this->assertSame(5, $response->json('data.attempts'));
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->deliveryId === $row->id);
    }

    /**
     * Kirim ulang dari layar ini adalah SATU-SATUNYA jalan kembali untuk
     * pengiriman yang gagal (Antrean Gagal menolaknya): setelah lima penolakan
     * penyedia, tombolnya mengantrekan job baru, menghapus catatan failed_jobs
     * yang ditinggalkan pekerja, dan — penyedia sudah pulih — pekerja
     * benar-benar mengirim. Bukan "berhasil" tanpa e-mail.
     */
    public function test_retry_after_five_refusals_forgets_the_failed_job_record_and_really_sends(): void
    {
        $this->emailOn();
        $this->bindRefusingMailChannel();
        config(['queue.default' => 'database']);
        $this->userWith('fin.approve', 'Direktur');

        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        foreach (range(1, 5) as $attempt) {
            DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);
        }

        $row = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::FAILED, $row->status);
        $this->assertSame(1, DB::table('failed_jobs')->count());

        // Admin dibuat SESUDAH pengajuan: ia juga memegang fin.approve, dan
        // baris pengiriman keduanya bukan yang diuji.
        $admin = $this->adminUser();

        // Penyedia pulih.
        $sends = 0;
        app()->instance(MailChannel::class, new class($sends) implements DeliveryChannel
        {
            public function __construct(public int &$sends) {}

            public function name(): string
            {
                return NotificationDelivery::CHANNEL_EMAIL;
            }

            public function send(NotificationDelivery $delivery, Notification $notification): ?string
            {
                $this->sends++;

                return 'msg-id-'.$this->sends;
            }
        });

        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertOk();

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Catatan gagal kerangka kerja ikut dihapus: job baru menggantikannya.');
        $this->assertSame(1, DB::table('jobs')->count());

        DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

        $row->refresh();
        $this->assertSame(1, $sends, 'Pekerja harus benar-benar mengirim.');
        $this->assertSame(NotificationDelivery::SENT, $row->status);
        $this->assertSame('msg-id-1', $row->provider_id);
        $this->assertSame(6, $row->attempts, 'Riwayat percobaan berlanjut, tidak direset.');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Baris `skipped` karena alamat kosong: pesannya menyuruh melengkapi alamat
     * lalu kirim ulang — maka kirim ulang harus membaca alamat yang BARU, bukan
     * `recipient` kosong yang dibekukan saat baris ditulis (dengan alamat beku:
     * 422 selamanya, verifikasi P-0b 5 Sep 2026).
     */
    public function test_retry_re_reads_the_recipient_address_so_a_fixed_address_can_be_delivered(): void
    {
        Queue::fake();
        $this->emailOn();
        $approver = $this->userWith('fin.approve', 'Tanpa Alamat', '');
        $this->bill()->submit($this->userWith('fin.create', 'Staf'));
        $admin = $this->adminUser();

        $row = NotificationDelivery::query()->where('recipient', '')->sole();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);

        $this->actingAs($admin, 'sanctum');
        // Alamat masih kosong: ditolak dengan perintahnya.
        $response = $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertStatus(422);
        $this->assertStringContainsString('lengkapi alamatnya di Sistem › Pengguna', $response->json('message'));
        Queue::assertNothingPushed();

        // Perintahnya dipenuhi.
        $approver->forceFill(['email' => 'direktur@nusantara.test'])->save();

        $response = $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertOk();
        $this->assertSame(NotificationDelivery::QUEUED, $response->json('data.status'));
        $this->assertSame('direktur@nusantara.test', $response->json('data.recipient'));
        $this->assertNull($response->json('data.error'));
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->deliveryId === $row->id);
    }

    public function test_retry_refuses_a_sent_row_and_a_disabled_email_channel(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $approver = $this->userWith('fin.approve', 'Direktur');
        $this->actingAs($admin, 'sanctum');

        $sent = $this->delivery($approver, NotificationDelivery::SENT, ['sent_at' => now(), 'attempts' => 1]);
        $this->postJson("/api/core/notification-deliveries/{$sent->id}/retry")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Pengiriman ini sudah diterima penyedia; tidak ada yang perlu dikirim ulang.']);

        // E-mail masih mati: ditolak dengan kalimatnya, bukan diantrekan untuk gagal lagi.
        $skipped = $this->delivery($approver, NotificationDelivery::SKIPPED, ['error' => NotificationService::SKIP_EMAIL_DISABLED]);
        $response = $this->postJson("/api/core/notification-deliveries/{$skipped->id}/retry")->assertStatus(422);
        $this->assertStringContainsString('masih dinonaktifkan di Pengaturan', $response->json('message'));
        $this->assertSame(NotificationDelivery::SKIPPED, $skipped->refresh()->status);

        Queue::assertNothingPushed();
    }

    // ----------------------------------------------------------------- list

    public function test_the_list_filters_by_status_and_is_gated_to_settings_holders(): void
    {
        $admin = $this->adminUser();
        $approver = $this->userWith('fin.approve', 'Direktur');
        $this->delivery($approver, NotificationDelivery::FAILED);
        $this->delivery($approver, NotificationDelivery::SENT, ['sent_at' => now()]);
        $this->delivery($approver, NotificationDelivery::SKIPPED, ['error' => NotificationService::SKIP_NO_ADDRESS, 'recipient' => '']);

        $this->actingAs($admin, 'sanctum');
        $all = $this->getJson('/api/core/notification-deliveries')->assertOk();
        $this->assertCount(3, $all->json('data'));
        $this->assertSame(3, $all->json('meta.total'));

        $failed = $this->getJson('/api/core/notification-deliveries?status=failed')->assertOk()->json('data');
        $this->assertCount(1, $failed);
        $this->assertSame('SMTP 550', $failed[0]['error']);
        $this->assertSame('Tagihan vendor INV-1 menunggu persetujuan', $failed[0]['title']);
        $this->assertSame('Direktur', $failed[0]['user_name']);

        $this->getJson('/api/core/notification-deliveries?status=terkirim')->assertStatus(422);

        $this->actingAs($this->userWith('fin.view', 'Kasir'), 'sanctum');
        $this->getJson('/api/core/notification-deliveries')->assertForbidden();
    }

    public function test_health_counts_queued_deliveries_older_than_an_hour(): void
    {
        $admin = $this->adminUser();
        $approver = $this->userWith('fin.approve', 'Direktur');
        $this->delivery($approver, NotificationDelivery::QUEUED);
        $old = $this->delivery($approver, NotificationDelivery::QUEUED);
        $old->forceFill(['created_at' => CarbonImmutable::now()->subHours(2)])->save();

        $this->actingAs($admin, 'sanctum');
        $this->assertSame(1, $this->getJson('/api/core/health')->assertOk()->json('data.queued_deliveries_older_than_1h'));
    }

    // ------------------------------------------------------------------ SPA

    public function test_the_spa_carries_the_screen_its_nav_entry_and_the_enums(): void
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $enums = (string) file_get_contents(public_path('app/js/enums.js'));

        $this->assertStringContainsString("  'core/notification-deliveries': {", $schema);
        $this->assertStringContainsString("route: 'r/core/notification-deliveries', perm: 'core.update'", $schema);
        $this->assertStringContainsString("path: '{id}/retry', method: 'POST'", $schema);

        // Blok enum-nya dipotong dulu, lalu tiap nilai dicari DI DALAMNYA —
        // supaya 'sent' milik enum lain tidak meloloskan yang hilang di sini.
        $this->assertSame(1, preg_match('/deliveryStatus: opts\(\[(.*?)\]\),/s', $enums, $statusBlock));
        $this->assertSame(1, preg_match('/deliveryChannel: opts\(\[(.*?)\]\),/s', $enums, $channelBlock));

        foreach (NotificationDelivery::STATUSES as $status) {
            $this->assertStringContainsString("['{$status}', ", $statusBlock[1], "enums.js deliveryStatus tidak memuat '{$status}'.");
        }
        foreach (NotificationDelivery::CHANNELS as $channel) {
            $this->assertStringContainsString("['{$channel}', ", $channelBlock[1], "enums.js deliveryChannel tidak memuat '{$channel}'.");
        }
    }
}
