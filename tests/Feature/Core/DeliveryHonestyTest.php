<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Channels\MailChannel;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Exceptions\DeliveryRejectedException;
use Modules\Core\Exceptions\DeliverySkippedException;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\DeliveryGate;
use Modules\Core\Support\MailTransport;
use Modules\Finance\Models\ApBill;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Iam\Support\PasswordHelp;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Support\UsesCapturingMailer;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * `sent` ADALAH KLAIM, `skipped` ADALAH KEJUJURAN (P-3a, langkah 1).
 *
 * Diukur 11 Sep 2026 sebelum paket ini: dengan MAIL_MAILER=log — keadaan .env
 * pengembangan DAN produksi erp1 — pengajuan tagihan menghasilkan baris
 * `sent` dengan provider_id "…@example.co.id": Message-ID buatan Symfony di
 * mesin ini sendiri, tanpa satu server surel pun di baliknya. Uji di berkas
 * ini memaku tiga hal:
 *
 *  1. Mailer log/array/null → `skipped` "MAIL_MAILER=… — belum ada server
 *     surel", di TIGA permukaan yang sama: kotak keluar saat menulis, job saat
 *     berjalan (keadaan bisa berubah di antara keduanya), dan Kirim ulang.
 *  2. Tidak ada `sent` tanpa pengenal penyedia: kanal yang memulangkan
 *     null/kosong dicatat sebagai percobaan gagal, bukan terkirim.
 *  3. Dua hasil baru job: DeliverySkippedException → `skipped` tanpa ulang;
 *     DeliveryRejectedException → `failed` seketika tanpa lima kali ulang.
 *
 * Dan satu jaring: halaman masuk (PasswordHelp) membaca predikat yang sama,
 * supaya "tautan reset sampai?" dan "e-mail terkirim?" tidak pernah berselisih.
 */
class DeliveryHonestyTest extends ErpTestCase
{
    use FinanceFixtures;
    use UsesCapturingMailer;

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

    private function queuedRowFor(User $recipient): NotificationDelivery
    {
        $notification = Notification::query()->create([
            'user_id' => $recipient->id,
            'event' => Notification::SUBMITTED,
            'title' => 'Tagihan vendor INV-1 menunggu persetujuan',
            'body' => 'Uji.',
        ]);

        return NotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => NotificationDelivery::CHANNEL_EMAIL,
            'recipient' => $recipient->email,
            'status' => NotificationDelivery::QUEUED,
            'attempts' => 0,
        ]);
    }

    private function bindChannelReturning(mixed $result): void
    {
        app()->instance(MailChannel::class, new class($result) implements DeliveryChannel
        {
            public function __construct(private readonly mixed $result) {}

            public function name(): string
            {
                return NotificationDelivery::CHANNEL_EMAIL;
            }

            public function send(NotificationDelivery $delivery, Notification $notification): ?string
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    // ------------------------------------------------ 1. mailer log = skipped

    /** Skenario yang diukur: keadaan produksi hari ini. Dulu `sent`. */
    public function test_with_mail_mailer_log_the_row_is_skipped_not_sent(): void
    {
        Mail::fake();
        Queue::fake();
        config(['mail.default' => 'log']);
        app(SettingService::class)->set('notifications.email_enabled', true);
        $this->userWith('fin.approve', 'Direktur');

        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);
        $this->assertStringStartsWith('MAIL_MAILER=log — belum ada server surel', (string) $row->error);
        $this->assertNull($row->provider_id);
        $this->assertNull($row->sent_at);
        $this->assertSame(0, $row->attempts);

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        // Kanal kebenaran tidak tersentuh oleh keputusan ini.
        $this->assertSame(1, Notification::query()->where('event', Notification::SUBMITTED)->count());
    }

    public function test_the_array_and_null_transports_are_skipped_with_their_own_words(): void
    {
        Queue::fake();
        app(SettingService::class)->set('notifications.email_enabled', true);
        $approver = $this->userWith('fin.approve', 'Direktur');

        config(['mail.default' => 'array']);
        $this->assertSame(
            'MAIL_MAILER=array — belum ada server surel; pesan hanya disimpan di memori proses, tidak keluar dari mesin. '
            .'Arahkan MAIL_* di .env ke server sungguhan (DEPLOYMENT.md §11).',
            DeliveryGate::reasonToSkip(NotificationDelivery::CHANNEL_EMAIL, $approver),
        );

        config(['mail.default' => 'null', 'mail.mailers.null' => ['transport' => 'null']]);
        $this->assertStringContainsString('pesan dibuang', (string) DeliveryGate::reasonToSkip(NotificationDelivery::CHANNEL_EMAIL, $approver));

        // Nama mailer boleh apa saja; yang menentukan adalah TRANSPORT-nya.
        config(['mail.default' => 'smtp-tapi-log', 'mail.mailers.smtp-tapi-log' => ['transport' => 'log']]);
        $this->assertFalse(MailTransport::leavesTheMachine());
        $this->assertStringStartsWith('MAIL_MAILER=smtp-tapi-log — belum ada server surel', (string) MailTransport::skipReason());

        // Mailer kosong bukan server surel juga.
        config(['mail.default' => '']);
        $this->assertFalse(MailTransport::leavesTheMachine());
    }

    /**
     * Permukaan kedua: baris ditulis `queued` saat mailer sungguhan, lalu
     * MAIL_MAILER diganti ke log sebelum pekerja sampai. Job harus menulis
     * `skipped` — tanpa memanggil Mail::, tanpa menghitung percobaan.
     */
    public function test_the_job_re_checks_the_mailer_and_skips_instead_of_sending_into_the_log(): void
    {
        $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur'));

        config(['mail.default' => 'log']);
        Mail::fake();

        (new DeliverNotification($row->id))->handle();

        $row->refresh();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);
        $this->assertStringStartsWith('MAIL_MAILER=log — belum ada server surel', (string) $row->error);
        $this->assertSame(0, $row->attempts, 'Melewati bukan mencoba.');
        $this->assertNull($row->sent_at);
        Mail::assertNothingSent();
    }

    /** Permukaan ketiga (kanal sendiri, bila gerbang di job dilewati apa pun sebabnya). */
    public function test_the_mail_channel_itself_refuses_a_log_mailer_before_touching_mail(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();
        $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur'));

        try {
            app(MailChannel::class)->send($row, $row->notification);
            $this->fail('MailChannel harus melempar DeliverySkippedException pada mailer log.');
        } catch (DeliverySkippedException $e) {
            $this->assertStringStartsWith('MAIL_MAILER=log — belum ada server surel', $e->getMessage());
        }

        Mail::assertNothingSent();
    }

    /** Permukaan keempat: Kirim ulang menolak dengan kalimat + petunjuknya. */
    public function test_retry_refuses_while_the_mailer_is_still_log(): void
    {
        Queue::fake();
        config(['mail.default' => 'log']);
        app(SettingService::class)->set('notifications.email_enabled', true);
        $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur'));
        $row->forceFill(['status' => NotificationDelivery::SKIPPED, 'error' => MailTransport::skipReason()])->save();

        $this->actingAs($this->adminUser(), 'sanctum');
        $response = $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertStatus(422);

        $this->assertStringStartsWith('MAIL_MAILER masih log — belum ada server surel.', $response->json('message'));
        $this->assertStringContainsString('DEPLOYMENT.md §11', $response->json('message'));
        $this->assertSame(NotificationDelivery::SKIPPED, $row->refresh()->status);
        Queue::assertNothingPushed();
    }

    /** Halaman masuk dan kotak keluar membaca SATU predikat. */
    public function test_password_help_and_the_outbox_agree_on_whether_mail_leaves_the_machine(): void
    {
        config(['mail.default' => 'log']);
        $this->assertFalse(PasswordHelp::resetByEmail());
        $this->assertFalse(MailTransport::leavesTheMachine());

        $this->useCapturingMailer();
        $this->assertTrue(PasswordHelp::resetByEmail());
        $this->assertTrue(MailTransport::leavesTheMachine());
    }

    // --------------------------------------- 2. sent menuntut pengenal penyedia

    public function test_a_channel_that_returns_no_provider_id_never_yields_sent(): void
    {
        $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);

        foreach ([null, '', '   '] as $empty) {
            $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur '.md5((string) $empty)));
            $this->bindChannelReturning($empty);

            try {
                (new DeliverNotification($row->id))->handle();
                $this->fail('Pengenal kosong harus dicatat sebagai percobaan gagal (dilempar ulang ke pekerja).');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('tidak memulangkan pengenal', $e->getMessage());
            }

            $row->refresh();
            $this->assertNotSame(NotificationDelivery::SENT, $row->status);
            $this->assertSame(NotificationDelivery::QUEUED, $row->status, 'Masih milik pekerja untuk diulang.');
            $this->assertNull($row->provider_id);
            $this->assertNull($row->sent_at);
            $this->assertSame(1, $row->attempts);
            $this->assertStringContainsString('tidak memulangkan pengenal', (string) $row->error);
        }
    }

    /** Mail::fake() memulangkan null: bukti yang benar bahwa jalur lama tidak bisa lagi menandai sent. */
    public function test_mail_fake_is_no_longer_enough_to_be_called_sent(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        app(SettingService::class)->set('notifications.email_enabled', true);
        $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur'));

        try {
            (new DeliverNotification($row->id))->handle();
            $this->fail('Tanpa Message-ID tidak boleh ada sent.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Message-ID', $e->getMessage());
        }

        $this->assertSame(NotificationDelivery::QUEUED, $row->refresh()->status);
        $this->assertNull($row->provider_id);
    }

    public function test_a_real_transport_yields_sent_with_its_message_id(): void
    {
        $transport = $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur'));

        (new DeliverNotification($row->id))->handle();

        $row->refresh();
        $this->assertSame(NotificationDelivery::SENT, $row->status);
        $this->assertSame($transport->messages[0]->getMessageId(), $row->provider_id);
        $this->assertMatchesRegularExpression('/^[^@\s]+@[^@\s]+$/', (string) $row->provider_id);
    }

    // --------------------------------------------- 3. dua hasil baru job

    public function test_a_channel_skip_is_recorded_as_skipped_with_its_sentence_and_never_retried(): void
    {
        $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        config(['queue.default' => 'database']);
        $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur'));
        $this->bindChannelReturning(new DeliverySkippedException('Template WhatsApp belum disetujui Meta (uji).'));

        DeliverNotification::dispatch($row->id);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

        $row->refresh();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);
        $this->assertSame('Template WhatsApp belum disetujui Meta (uji).', $row->error);
        $this->assertNull($row->next_attempt_at);
        $this->assertNull($row->sent_at);
        $this->assertSame(0, DB::table('jobs')->count(), 'Tidak ada yang diulang.');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Bukan kegagalan job.');
    }

    public function test_a_permanent_provider_rejection_fails_at_once_instead_of_five_times(): void
    {
        $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        config(['queue.default' => 'database']);
        $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur'));
        $this->bindChannelReturning(new DeliveryRejectedException('(190) Token akses tidak sah.'));

        DeliverNotification::dispatch($row->id);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

        $row->refresh();
        $this->assertSame(NotificationDelivery::FAILED, $row->status);
        $this->assertSame('(190) Token akses tidak sah.', $row->error);
        $this->assertSame(1, $row->attempts, 'Satu percobaan, bukan lima.');
        $this->assertNull($row->next_attempt_at);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Baris `failed` adalah kebenarannya; job-nya selesai dengan tertib.');
    }

    /** Pengecualian BIASA tetap diulang seperti P-0b — pembeda ketiga jenis itu nyata. */
    public function test_an_ordinary_exception_is_still_retried_by_the_worker(): void
    {
        $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        config(['queue.default' => 'database']);
        $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur'));
        $this->bindChannelReturning(new RuntimeException('SMTP 421 4.7.0 try again later'));

        DeliverNotification::dispatch($row->id);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

        $row->refresh();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame('SMTP 421 4.7.0 try again later', $row->error);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertSame(1, DB::table('jobs')->count(), 'Dilepas kembali untuk percobaan berikutnya.');
    }

    /** Sakelar dimatikan SESUDAH baris ditulis: job melewati, tidak mengirim. */
    public function test_the_job_re_checks_the_settings_switch(): void
    {
        $transport = $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        $row = $this->queuedRowFor($this->userWith('fin.approve', 'Direktur'));

        app(SettingService::class)->set('notifications.email_enabled', false);
        (new DeliverNotification($row->id))->handle();

        $row->refresh();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);
        $this->assertSame(DeliveryGate::EMAIL_DISABLED, $row->error);
        $this->assertSame([], $transport->messages);
    }
}
