<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
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

    /**
     * Kanal kebenaran dulu, kotak keluar belakangan, per penerima di balik
     * guard-nya sendiri: satu tulisan core_notification_deliveries yang gagal
     * (tabel belum termigrasi setelah deploy — 'deploy migration race' —,
     * SQLite terkunci, alamat terlalu panjang di MySQL ketat) tidak boleh
     * membuat penyetuju berikutnya tidak diberi tahu sama sekali. Sebelum ini:
     * dua penyetuju, satu baris dalam aplikasi (verifikasi P-0b, 5 Sep 2026).
     */
    public function test_a_failed_outbox_write_for_one_recipient_starves_nobody_of_the_in_app_row(): void
    {
        Queue::fake();
        $this->emailOn();
        $a = $this->userWith('fin.approve', 'Direktur A');
        $b = $this->userWith('fin.approve', 'Direktur B');

        // Tulisan kotak keluar PERTAMA gagal — apa pun sebabnya, jalurnya sama.
        $writes = 0;
        NotificationDelivery::saving(function () use (&$writes): void {
            if ($writes++ === 0) {
                throw new RuntimeException('simulasi: tabel core_notification_deliveries tidak terbaca');
            }
        });

        $bill = $this->bill();
        $bill->submit($this->userWith('fin.create', 'Staf'));

        $this->assertSame('submitted', $bill->refresh()->status->value);
        $inApp = Notification::query()->where('event', Notification::SUBMITTED)->pluck('user_id')->sort()->values()->all();
        $this->assertSame(collect([$a->id, $b->id])->sort()->values()->all(), $inApp, 'Kedua penyetuju harus punya baris dalam aplikasi.');

        // Penerima yang tulisannya gagal tidak punya baris kotak keluar (hanya
        // log); penerima berikutnya tetap diproses.
        $this->assertSame(1, NotificationDelivery::query()->count());
        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_a_failed_outbox_write_does_not_starve_system_alarm_recipients_either(): void
    {
        Queue::fake();
        $this->emailOn();
        $this->adminUser();
        $second = $this->userWith('core.update', 'Admin Kedua');

        $writes = 0;
        NotificationDelivery::saving(function () use (&$writes): void {
            if ($writes++ === 0) {
                throw new RuntimeException('simulasi');
            }
        });

        app(NotificationService::class)->system('core.update', 'Cadangan basi', 'Isi.');

        $this->assertSame(2, Notification::query()->where('event', Notification::SYSTEM)->count());
        $this->assertSame(1, Notification::query()->where('user_id', $second->id)->count());
        $this->assertSame(1, NotificationDelivery::query()->count());
    }

    /**
     * ShouldQueueAfterCommit: dispatch dari dalam transaksi ditunda sampai
     * commit — pekerja lain tidak boleh mengambil job untuk baris pengiriman
     * yang belum ter-commit — dan transaksi yang dibatalkan tidak meninggalkan
     * job untuk baris yang tidak pernah ada.
     */
    public function test_the_job_is_pushed_only_after_the_surrounding_transaction_commits(): void
    {
        $this->emailOn();
        $this->adminUser();
        config(['queue.default' => 'database']);
        $service = app(NotificationService::class);

        $inside = null;
        DB::transaction(function () use ($service, &$inside): void {
            $service->system('core.update', 'Cadangan basi', 'Isi.');
            $inside = DB::table('jobs')->count();
        });

        $this->assertSame(0, $inside, 'Di dalam transaksi job belum boleh ada di tabel.');
        $this->assertSame(1, DB::table('jobs')->count(), 'Setelah commit job-nya ada.');

        try {
            DB::transaction(function () use ($service): void {
                $service->system('core.update', 'Cadangan basi dua', 'Isi.');
                throw new RuntimeException('dibatalkan');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(1, DB::table('jobs')->count(), 'Transaksi yang dibatalkan tidak menyisakan job.');
        $this->assertSame(1, NotificationDelivery::query()->count(), 'Dan tidak menyisakan baris pengiriman.');
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

    /**
     * Pekerja yang dibunuh pcntl pada --timeout (SMTP bisu) tidak pernah sampai
     * ke blok catch: attempts harus sudah tersimpan SEBELUM mengirim, supaya
     * lima kali dibunuh tidak terbaca sebagai attempts=0 (verifikasi P-0b,
     * 5 Sep 2026).
     */
    public function test_attempts_are_persisted_before_the_send_so_a_killed_worker_leaves_a_trace(): void
    {
        $this->emailOn();
        $row = $this->delivery($this->userWith('fin.approve', 'Direktur'), NotificationDelivery::QUEUED);

        $seen = null;
        app()->instance(MailChannel::class, new class($seen) implements DeliveryChannel
        {
            public function __construct(public ?int &$seen) {}

            public function name(): string
            {
                return NotificationDelivery::CHANNEL_EMAIL;
            }

            public function send(NotificationDelivery $delivery, Notification $notification): ?string
            {
                // Dibaca segar dari basis data: yang sudah TERSIMPAN saat pengiriman berjalan.
                $this->seen = NotificationDelivery::query()->whereKey($delivery->id)->value('attempts');

                return null;
            }
        });

        (new DeliverNotification($row->id))->handle();

        $this->assertSame(1, $seen, 'attempts=1 harus sudah tersimpan sebelum penyedia dipanggil.');
        $this->assertSame(NotificationDelivery::SENT, $row->refresh()->status);
    }

    /**
     * Kehabisan waktu dan percobaan habis adalah pengecualian PEKERJA; kalimat
     * Inggrisnya bukan pesan penyedia. Ditulis dalam kalimat kita, membawa pesan
     * penyedia terakhir yang sempat tersimpan.
     */
    public function test_a_worker_timeout_or_exhausted_attempts_end_as_failed_in_our_words_keeping_the_provider_message(): void
    {
        $approver = $this->userWith('fin.approve', 'Direktur');

        $timedOut = $this->delivery($approver, NotificationDelivery::QUEUED, ['attempts' => 5, 'error' => 'SMTP 421 4.7.0 try again later']);
        (new DeliverNotification($timedOut->id))->failed(new TimeoutExceededException(DeliverNotification::class.' has timed out.'));
        $timedOut->refresh();
        $this->assertSame(NotificationDelivery::FAILED, $timedOut->status);
        $this->assertStringContainsString('kehabisan waktu', (string) $timedOut->error);
        $this->assertStringContainsString('SMTP 421 4.7.0 try again later', (string) $timedOut->error);
        $this->assertStringNotContainsString('has timed out', (string) $timedOut->error);

        $exhausted = $this->delivery($approver, NotificationDelivery::QUEUED, ['attempts' => 5]);
        (new DeliverNotification($exhausted->id))->failed(new MaxAttemptsExceededException(DeliverNotification::class.' has been attempted too many times.'));
        $this->assertSame('Percobaan habis sebelum penyedia menjawab.', $exhausted->refresh()->error);

        // Pesan penyedia sungguhan tetap apa adanya.
        $refused = $this->delivery($approver, NotificationDelivery::QUEUED, ['attempts' => 5]);
        (new DeliverNotification($refused->id))->failed(new RuntimeException('SMTP 550 5.1.1 mailbox unavailable'));
        $this->assertSame('SMTP 550 5.1.1 mailbox unavailable', $refused->refresh()->error);
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

    /**
     * Setelah Kirim ulang, attempts baris berlanjut dari 5 (riwayat) sementara
     * pekerja menghitung dari 1: jadwal percobaan berikutnya harus mengikuti
     * hitungan pekerja. Sebelum ini next_attempt_at kosong (BACKOFF[5] tidak
     * ada) padahal job-nya dilepas kembali ke antrean (verifikasi P-0b, 5 Sep
     * 2026).
     */
    public function test_after_a_manual_retry_the_next_attempt_follows_the_workers_count(): void
    {
        $this->emailOn();
        $this->bindRefusingMailChannel('SMTP 421 4.7.0 try again later');
        config(['queue.default' => 'database']);
        $admin = $this->adminUser();
        $row = $this->delivery($this->userWith('fin.approve', 'Direktur'), NotificationDelivery::FAILED);

        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertOk();

        DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

        $row->refresh();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame(6, $row->attempts, 'Riwayat berlanjut.');
        $this->assertSame(1, DB::table('jobs')->count(), 'Job dilepas kembali: masih ada percobaan.');
        $this->assertNotNull($row->next_attempt_at, 'Job masih dijadwalkan pekerja, jadi barisnya harus berkata begitu.');
        $this->assertEqualsWithDelta(60, $row->next_attempt_at->diffInSeconds(now(), true), 5, 'Percobaan pertama pekerja: backoff 60 s.');
        $this->assertStringContainsString('SMTP 421', (string) $row->error);
    }

    /**
     * Antrean yang menolak saat Kirim ulang sampai ke orangnya sebagai 503
     * dengan sebabnya — bukan sebagai baris `queued` yang diam. Barisnya tetap
     * `queued`: jujur, ia memang menunggu.
     */
    public function test_retry_reports_a_refusing_queue_as_503_and_leaves_the_row_queued(): void
    {
        $this->emailOn();
        $admin = $this->adminUser();
        $row = $this->delivery($this->userWith('fin.approve', 'Direktur'), NotificationDelivery::FAILED);
        config(['queue.default' => 'koneksi-yang-tidak-ada']);

        $this->actingAs($admin, 'sanctum');
        $response = $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertStatus(503);

        $this->assertStringStartsWith('Antrean tidak dapat menerima job:', $response->json('message'));
        $this->assertStringContainsString('Baris tetap berstatus antre.', $response->json('message'));
        $this->assertSame(NotificationDelivery::QUEUED, $row->refresh()->status);
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

    /**
     * "Antre lebih dari 1 jam" = tidak disentuh pekerja selama sejam, bukan
     * "dibuat lebih dari sejam lalu": baris yang menunggu backoff dan baris
     * gagal yang baru ditekan Kirim ulang bukan kemacetan (verifikasi P-0b,
     * 5 Sep 2026: keduanya dihitung macet).
     */
    public function test_health_counts_queued_deliveries_untouched_for_an_hour(): void
    {
        $admin = $this->adminUser();
        $approver = $this->userWith('fin.approve', 'Direktur');
        $twoHoursAgo = CarbonImmutable::now()->subHours(2);

        // Baru dibuat: belum macet.
        $this->delivery($approver, NotificationDelivery::QUEUED);
        // Dibuat dua jam lalu, tidak pernah disentuh: MACET.
        $stale = $this->delivery($approver, NotificationDelivery::QUEUED);
        DB::table('core_notification_deliveries')->where('id', $stale->id)
            ->update(['created_at' => $twoHoursAgo, 'updated_at' => $twoHoursAgo]);
        // Percobaan ke-4 gagal 70 menit lalu, menunggu backoff 3600 s (jatuh 30 menit
        // lagi): sedang melakukan yang seharusnya, BUKAN macet.
        $backoff = $this->delivery($approver, NotificationDelivery::QUEUED, ['attempts' => 4, 'error' => 'SMTP 421 try later']);
        DB::table('core_notification_deliveries')->where('id', $backoff->id)->update([
            'created_at' => $twoHoursAgo, 'updated_at' => CarbonImmutable::now()->subMinutes(70),
            'next_attempt_at' => CarbonImmutable::now()->addMinutes(30),
        ]);
        // Jadwal percobaannya sudah lewat dua jam dan pekerja tidak mengambilnya: MACET.
        $missed = $this->delivery($approver, NotificationDelivery::QUEUED, ['attempts' => 2]);
        DB::table('core_notification_deliveries')->where('id', $missed->id)->update([
            'created_at' => CarbonImmutable::now()->subHours(3), 'updated_at' => CarbonImmutable::now()->subHours(3),
            'next_attempt_at' => $twoHoursAgo,
        ]);
        // Gagal kemarin, Kirim ulang baru saja ditekan (updated_at sekarang): belum macet.
        $retried = $this->delivery($approver, NotificationDelivery::FAILED);
        DB::table('core_notification_deliveries')->where('id', $retried->id)
            ->update(['created_at' => CarbonImmutable::now()->subDay(), 'updated_at' => CarbonImmutable::now()->subDay()]);
        $this->emailOn();
        $this->actingAs($admin, 'sanctum');
        Queue::fake();
        $this->postJson("/api/core/notification-deliveries/{$retried->id}/retry")->assertOk();

        $this->assertSame(2, $this->getJson('/api/core/health')->assertOk()->json('data.queued_deliveries_older_than_1h'));
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
