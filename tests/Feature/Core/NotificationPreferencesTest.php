<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Channels\MailChannel;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\UserPreference;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\DeliveryGate;
use Modules\Core\Support\QuietHours;
use Modules\Core\Support\UserPreferences;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Support\UsesCapturingMailer;

/**
 * Preferensi kanal per pengguna + jam tenang (P-3a, T3a.2).
 *
 * Dua aturan, masing-masing dipaku di SETIAP permukaan yang menegakkannya:
 *
 *  KANAL DIMATIKAN PENGGUNA → `skipped` "Dimatikan pengguna di Profil ›
 *  Notifikasi." di kotak keluar, ditolak 422 di Kirim ulang, dan diperiksa
 *  ulang di job (dimatikan SESUDAH baris ditulis → tetap tidak dikirim).
 *  Kunci yang belum pernah ditulis = nyala.
 *
 *  JAM TENANG MENUNDA, TIDAK PERNAH MEMBUANG — dan tidak pernah menyentuh
 *  kanal dalam aplikasi. Notifikasi pukul 02.00 WIB: baris kotak masuk ada
 *  SEKETIKA; baris kotak keluar `queued` dengan next_attempt_at = 06.00 WIB
 *  dan job ber-delay yang sama. Diperiksa di kotak keluar, Kirim ulang, dan
 *  job (pekerja sungguhan melepasnya kembali ke antrean). Jendela yang
 *  melintasi tengah malam (22:00–06:00) adalah kasus yang paling mudah
 *  salah; ia diuji di kedua sisinya dan di kedua batasnya.
 *
 * Zona: Asia/Jakarta. Jam uji ditulis dalam UTC lalu dibaca kembali dalam
 * WIB supaya konversinya benar-benar diuji, bukan dilewati.
 */
class NotificationPreferencesTest extends ErpTestCase
{
    use UsesCapturingMailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function holder(string $permission = 'core.update', string $name = 'Direktur'): User
    {
        $role = Role::findOrCreate('peran-'.md5($permission.$name), 'web');
        $role->givePermissionTo($permission);
        $user = User::query()->create([
            'name' => $name,
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function emailOn(): void
    {
        app(SettingService::class)->set('notifications.email_enabled', true);
        $this->useCapturingMailer();
    }

    private function prefer(User $user, string $key, mixed $value): void
    {
        UserPreference::query()->updateOrCreate(['user_id' => $user->id, 'key' => $key], ['value' => $value]);
    }

    private function alarm(string $title = 'Cadangan basi'): void
    {
        app(NotificationService::class)->system('core.update', $title, 'Isi.');
    }

    /**
     * Kanal e-mail yang SELALU ditolak sementara ("SMTP 421 … #n") dan
     * menghitung berapa kali ia benar-benar dipanggil — pekerja sungguhan yang
     * mengukur percobaan, bukan stub yang mengaku.
     */
    private function refusingMail(): object
    {
        $counter = new class
        {
            public int $calls = 0;
        };
        app()->instance(MailChannel::class, new class($counter) implements DeliveryChannel
        {
            public function __construct(private readonly object $counter) {}

            public function name(): string
            {
                return NotificationDelivery::CHANNEL_EMAIL;
            }

            public function send(NotificationDelivery $delivery, Notification $notification): ?string
            {
                $this->counter->calls++;

                throw new RuntimeException('SMTP 421 4.7.0 try again later #'.$this->counter->calls);
            }
        });

        return $counter;
    }

    /** Pekerja sungguhan mengambil job yang ada pada jam WIB ini (available_at dipaksa lewat). */
    private function workAt(string $hhmm, string $date = '2026-09-11'): void
    {
        $this->atWib($hhmm, $date);
        DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);
    }

    /** Jam WIB pada 11 Sep 2026, ditetapkan lewat UTC (WIB = UTC+7). */
    private function atWib(string $hhmm, string $date = '2026-09-11'): CarbonImmutable
    {
        $wib = CarbonImmutable::parse("{$date} {$hhmm}", QuietHours::ZONE);
        Carbon::setTestNow($wib->setTimezone('UTC'));

        return $wib;
    }

    // ---------------------------------------------------------- preferensi

    public function test_the_two_keys_are_whitelisted_with_their_ceilings(): void
    {
        $described = collect(UserPreferences::describe())->keyBy('key');

        $this->assertSame(['key' => 'notify.channels', 'label' => 'Kanal notifikasi', 'max_bytes' => 256, 'max_entries' => null], $described['notify.channels']);
        $this->assertSame(['key' => 'notify.quiet_hours', 'label' => 'Jam tenang', 'max_bytes' => 64, 'max_entries' => null], $described['notify.quiet_hours']);
    }

    public function test_notify_channels_accepts_booleans_for_the_two_outside_channels_only(): void
    {
        $this->actingAs($this->holder(), 'sanctum');

        $this->putJson('/api/core/me/preferences/notify.channels', ['value' => ['email' => false]])->assertOk();
        $this->putJson('/api/core/me/preferences/notify.channels', ['value' => ['email' => true, 'whatsapp' => false]])->assertOk();

        $this->putJson('/api/core/me/preferences/notify.channels', ['value' => ['sms' => true]])
            ->assertStatus(422)->assertJsonFragment(['message' => 'notify.channels: Kanal tidak dikenal: sms.']);
        $this->putJson('/api/core/me/preferences/notify.channels', ['value' => ['email' => 'ya']])
            ->assertStatus(422)->assertJsonFragment(['message' => 'notify.channels: Nilai kanal "email" harus true atau false.']);
        $this->putJson('/api/core/me/preferences/notify.channels', ['value' => ['email']])
            ->assertStatus(422)->assertJsonFragment(['message' => 'notify.channels: Kanal notifikasi harus berupa objek {email: true/false, whatsapp: true/false}.']);
    }

    public function test_notify_quiet_hours_accepts_a_window_or_null_and_refuses_the_rest(): void
    {
        $this->actingAs($this->holder(), 'sanctum');
        $put = fn (mixed $value) => $this->putJson('/api/core/me/preferences/notify.quiet_hours', ['value' => $value]);

        $put(['start' => '22:00', 'end' => '06:00'])->assertOk();
        $put(['start' => '12:00', 'end' => '13:00'])->assertOk();
        $put(false)->assertOk();
        // Baris `false` ADA: pilihan "tanpa jam tenang" pernah dibuat.
        $this->assertSame(1, UserPreference::query()->where('key', 'notify.quiet_hours')->count());
        $this->assertFalse(UserPreference::query()->where('key', 'notify.quiet_hours')->sole()->value);
        $this->assertNull(QuietHours::forUser(User::query()->sole()));

        // null bukan "matikan": kolomnya NOT NULL, dan kalimatnya menyebut false.
        $put(null)->assertStatus(422)
            ->assertJsonFragment(['message' => 'notify.quiet_hours: Jam tenang harus berupa objek {start, end} dengan jam "HH:MM", atau false untuk mematikannya.']);
        $put(true)->assertStatus(422);

        $put(['start' => '22:00'])->assertStatus(422)->assertJsonFragment(['message' => 'notify.quiet_hours: Jam tenang "end" harus berupa jam "HH:MM" (00:00–23:59).']);
        $put(['start' => '25:00', 'end' => '06:00'])->assertStatus(422);
        $put(['start' => '7:00', 'end' => '08:00'])->assertStatus(422);
        $put(['start' => '22:00', 'end' => '22:00'])->assertStatus(422)
            ->assertJsonFragment(['message' => 'notify.quiet_hours: Jam mulai dan jam selesai jam tenang tidak boleh sama — untuk mematikan kanal seharian pakai sakelar kanalnya.']);
        $put(['start' => '22:00', 'end' => '06:00', 'zone' => 'UTC'])->assertStatus(422)
            ->assertJsonFragment(['message' => 'notify.quiet_hours: Field tidak dikenal pada jam tenang: zone.']);
        $put(['22:00', '06:00'])->assertStatus(422);
    }

    // --------------------------------------------- kanal dimatikan pengguna

    public function test_a_channel_the_user_switched_off_is_skipped_with_the_reason_and_no_job(): void
    {
        Queue::fake();
        $this->emailOn();
        $user = $this->holder();
        $this->prefer($user, 'notify.channels', ['email' => false]);

        $this->alarm();

        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);
        $this->assertSame('Dimatikan pengguna di Profil › Notifikasi.', $row->error);
        Queue::assertNothingPushed();
        // Kanal kebenaran tidak ikut mati.
        $this->assertSame(1, Notification::query()->where('user_id', $user->id)->count());
    }

    /**
     * Urutan sebab GLOBAL-DULU (DeliveryGate: Pengaturan → mailer → pengguna →
     * alamat) hanya dipaku harness S37, tidak oleh PHP — mutasi yang memeriksa
     * "Dimatikan pengguna" SEBELUM MailTransport lolos hijau (verifikasi P-3a,
     * 12 Sep 2026, B-3). Dipaku di kedua permukaan: kotak keluar dan Profil.
     */
    public function test_with_a_log_mailer_the_global_reason_wins_over_the_users_switch_at_both_surfaces(): void
    {
        Queue::fake();
        app(SettingService::class)->set('notifications.email_enabled', true);
        config(['mail.default' => 'log']);
        $user = $this->holder();
        $this->prefer($user, 'notify.channels', ['email' => false]);

        $this->alarm();
        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);
        $this->assertStringStartsWith('MAIL_MAILER=log — belum ada server surel', (string) $row->error);
        $this->assertStringNotContainsString('Dimatikan pengguna', (string) $row->error);

        $this->actingAs($user, 'sanctum');
        $email = $this->getJson('/api/core/me/notification-channels')->assertOk()->json('data.channels.0');
        $this->assertFalse($email['enabled_by_user'], 'Pilihannya tersimpan…');
        $this->assertFalse($email['will_deliver']);
        $this->assertStringStartsWith('MAIL_MAILER=log — belum ada server surel', (string) $email['reason'], '…tetapi sebab yang lebih global yang disebut.');
        Queue::assertNothingPushed();
    }

    public function test_a_key_never_written_means_on(): void
    {
        Queue::fake();
        $this->emailOn();
        $this->holder();

        $this->alarm();

        $this->assertSame(NotificationDelivery::QUEUED, NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole()->status);
        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_retry_refuses_a_channel_the_user_switched_off(): void
    {
        Queue::fake();
        $this->emailOn();
        $user = $this->holder();
        $this->prefer($user, 'notify.channels', ['email' => false]);
        $this->alarm();
        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();

        $this->actingAs($this->adminUser(), 'sanctum');
        $response = $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertStatus(422);

        $this->assertSame(
            'Penerima mematikan kanal ini di Profil › Notifikasi; hanya penerimanya sendiri yang bisa menyalakannya lagi, lalu kirim ulang.',
            $response->json('message'),
        );
        Queue::assertNothingPushed();
    }

    public function test_the_job_re_checks_the_users_switch(): void
    {
        $transport = $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        $user = $this->holder();
        $notification = Notification::query()->create(['user_id' => $user->id, 'event' => Notification::SYSTEM, 'title' => 'Cadangan basi', 'body' => 'Isi.']);
        $row = NotificationDelivery::query()->create(['notification_id' => $notification->id, 'channel' => 'email', 'recipient' => $user->email, 'status' => 'queued', 'attempts' => 0]);

        // Dimatikan SESUDAH baris ditulis, SEBELUM pekerja sampai.
        $this->prefer($user, 'notify.channels', ['email' => false]);
        (new DeliverNotification($row->id))->handle();

        $this->assertSame(NotificationDelivery::SKIPPED, $row->refresh()->status);
        $this->assertSame(DeliveryGate::USER_OFF, $row->error);
        $this->assertSame([], $transport->messages);
    }

    // ------------------------------------------------------------ jam tenang

    public function test_the_window_across_midnight_is_computed_on_both_sides_and_both_edges(): void
    {
        $quiet = QuietHours::fromPreference(['start' => '22:00', 'end' => '06:00']);
        $wib = fn (string $s) => CarbonImmutable::parse($s, QuietHours::ZONE);
        $resume = fn (string $s) => $quiet->resumeAt($wib($s)->setTimezone('UTC'))?->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i');

        $this->assertSame('2026-09-12 06:00', $resume('2026-09-11 23:30'), 'sisi malam → besok pagi');
        $this->assertSame('2026-09-12 06:00', $resume('2026-09-12 02:00'), 'sisi pagi → hari ini');
        $this->assertSame('2026-09-12 06:00', $resume('2026-09-11 22:00'), 'awal inklusif');
        $this->assertNull($resume('2026-09-12 06:00'), 'akhir eksklusif');
        $this->assertNull($resume('2026-09-11 12:00'));
        $this->assertNull($resume('2026-09-11 21:59'));
        $this->assertSame('22:00–06:00 WIB', $quiet->label());

        $day = QuietHours::fromPreference(['start' => '12:00', 'end' => '13:00']);
        $this->assertSame('2026-09-11 13:00', $day->resumeAt($wib('2026-09-11 12:30')->setTimezone('UTC'))?->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));
        $this->assertNull($day->resumeAt($wib('2026-09-11 13:00')));
        $this->assertNull($day->resumeAt($wib('2026-09-11 11:59')));

        $this->assertNull(QuietHours::fromPreference(null));
        $this->assertNull(QuietHours::fromPreference(false));
        $this->assertNull(QuietHours::fromPreference(['start' => '22:00', 'end' => '22:00']), 'Jendela kosong bukan jam tenang.');
        $this->assertNull(QuietHours::fromPreference('22:00-06:00'));
    }

    public function test_at_two_in_the_morning_the_in_app_row_is_immediate_and_the_outbox_waits_until_six(): void
    {
        Queue::fake();
        $this->emailOn();
        $user = $this->holder();
        $this->prefer($user, 'notify.quiet_hours', ['start' => '22:00', 'end' => '06:00']);
        $this->atWib('02:00', '2026-09-12');

        $this->alarm();

        // Kanal kebenaran: seketika.
        $inApp = Notification::query()->where('user_id', $user->id)->sole();
        $this->assertSame('2026-09-12 02:00', $inApp->created_at->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));

        // Kotak keluar: menunggu, tidak dibuang.
        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame('2026-09-12 06:00', $row->next_attempt_at->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));
        $this->assertSame(
            'Ditunda oleh jam tenang penerima (22:00–06:00 WIB) sampai 12 Sep 2026 06:00 WIB — tidak dibuang; pemberitahuan di dalam aplikasi sudah masuk.',
            $row->error,
        );
        $this->assertSame(0, $row->attempts);

        Queue::assertPushed(DeliverNotification::class, function (DeliverNotification $job) use ($row): bool {
            return $job->deliveryId === $row->id
                && $job->delay instanceof \DateTimeInterface
                && CarbonImmutable::instance($job->delay)->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i') === '2026-09-12 06:00';
        });
    }

    public function test_at_half_past_eleven_at_night_the_outbox_waits_until_tomorrow_six(): void
    {
        Queue::fake();
        $this->emailOn();
        $user = $this->holder();
        $this->prefer($user, 'notify.quiet_hours', ['start' => '22:00', 'end' => '06:00']);
        $this->atWib('23:30');

        $this->alarm();

        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();
        $this->assertSame('2026-09-12 06:00', $row->next_attempt_at->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));
        $this->assertStringContainsString('sampai 12 Sep 2026 06:00 WIB', (string) $row->error);
    }

    public function test_outside_the_window_nothing_is_delayed(): void
    {
        Queue::fake();
        $this->emailOn();
        $user = $this->holder();
        $this->prefer($user, 'notify.quiet_hours', ['start' => '22:00', 'end' => '06:00']);
        $this->atWib('09:15');

        $this->alarm();

        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();
        $this->assertNull($row->next_attempt_at);
        $this->assertNull($row->error);
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->delay === null);
    }

    /**
     * Permukaan ketiga — pekerja SUNGGUHAN: baris yang job-nya tiba di dalam
     * jendela (backoff yang jatuh pukul 22.00) dilepas kembali sampai 06.00,
     * tanpa mencatat percobaan dan tanpa memanggil kanal.
     */
    public function test_a_worker_arriving_inside_the_window_hands_the_job_over_until_it_ends(): void
    {
        $transport = $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        config(['queue.default' => 'database']);
        $user = $this->holder();
        $this->prefer($user, 'notify.quiet_hours', ['start' => '22:00', 'end' => '06:00']);
        $this->atWib('12:00');
        $this->alarm();
        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();
        $this->assertNull($row->next_attempt_at, 'Ditulis di luar jendela: tanpa penundaan.');

        // Pekerja baru sempat mengambilnya pukul 22.05.
        $this->atWib('22:05');
        DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

        $row->refresh();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame(0, $row->attempts, 'Menunda bukan mencoba.');
        $this->assertSame('2026-09-12 06:00', $row->next_attempt_at->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));
        $this->assertStringContainsString('Ditunda oleh jam tenang', (string) $row->error);
        $this->assertSame([], $transport->messages);

        $job = DB::table('jobs')->sole();
        $this->assertSame(
            '2026-09-12 06:00',
            CarbonImmutable::createFromTimestamp((int) $job->available_at, 'UTC')->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'),
            'Job pengganti menunggu dengan available_at = akhir jendela.',
        );

        // Pukul 06.00 ia berangkat.
        $this->atWib('06:00', '2026-09-12');
        DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

        $this->assertSame(NotificationDelivery::SENT, $row->refresh()->status);
        $this->assertCount(1, $transport->messages);
    }

    public function test_retry_during_the_window_queues_for_its_end_and_says_so(): void
    {
        Queue::fake();
        $this->emailOn();
        $user = $this->holder();
        $this->prefer($user, 'notify.quiet_hours', ['start' => '22:00', 'end' => '06:00']);
        $notification = Notification::query()->create(['user_id' => $user->id, 'event' => Notification::SYSTEM, 'title' => 'Cadangan basi', 'body' => 'Isi.']);
        $row = NotificationDelivery::query()->create(['notification_id' => $notification->id, 'channel' => 'email', 'recipient' => $user->email, 'status' => 'failed', 'attempts' => 5, 'error' => 'SMTP 550']);
        $this->atWib('23:00');

        $this->actingAs($this->adminUser(), 'sanctum');
        $response = $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertOk();

        $this->assertSame(NotificationDelivery::QUEUED, $response->json('data.status'));
        $this->assertStringContainsString('Ditunda oleh jam tenang penerima', $response->json('data.error'));
        $this->assertSame('2026-09-12 06:00', CarbonImmutable::parse($response->json('data.next_attempt_at'))->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->delay instanceof \DateTimeInterface);
    }

    // ------------------------- jam tenang TIDAK memakan hitungan percobaan

    /**
     * Verifikasi P-3a (12 Sep 2026, B-1): job yang dilepas release() oleh jam
     * tenang menaikkan hitungan percobaan PEKERJA. Empat kegagalan sementara
     * dalam 21 menit (21:30 → 21:51) lalu backoff 3600 s mendarat 22:51 — di
     * dalam 22:00–06:00 — dan pukul 06:00 pekerja menggagalkan job SEBELUM
     * handle() berjalan: baris `failed` "Percobaan habis … Pesan penyedia
     * terakhir: Ditunda oleh jam tenang …" dengan hanya EMPAT panggilan
     * kanal. Satu malam gangguan SMTP sementara sudah cukup (kegagalan
     * pertama antara ±20:39 dan ±04:39 WIB), bukan "lima malam berturut-turut".
     *
     * Penundaan bukan percobaan: pukul 06:00 percobaan KELIMA harus terjadi.
     */
    public function test_a_quiet_hours_postponement_costs_no_worker_attempt_so_the_fifth_attempt_still_happens_after_the_window(): void
    {
        $this->emailOn();
        $counter = $this->refusingMail();
        config(['queue.default' => 'database']);
        $user = $this->holder();
        $this->prefer($user, 'notify.quiet_hours', ['start' => '22:00', 'end' => '06:00']);

        $this->atWib('21:30');
        $this->alarm();
        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();
        $this->assertNull($row->next_attempt_at, 'Ditulis di luar jendela: tanpa penundaan.');

        foreach ([['21:30', 60], ['21:31', 300], ['21:36', 900], ['21:51', 3600]] as $i => [$clock, $delay]) {
            $this->workAt($clock);
            $row->refresh();
            $this->assertSame($i + 1, $row->attempts, 'percobaan ke-'.($i + 1));
            $this->assertSame(NotificationDelivery::QUEUED, $row->status);
            $this->assertSame($delay, (int) $this->atWib($clock)->diffInSeconds($row->next_attempt_at), 'backoff setelah percobaan ke-'.($i + 1));
        }
        $this->assertSame('2026-09-11 22:51', $row->next_attempt_at->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'), 'Percobaan ke-5 jatuh DI DALAM jendela.');

        // 22:51 — pekerja mengambilnya di dalam jendela: ditunda, BUKAN dicoba, BUKAN dihitung.
        $this->workAt('22:51');
        $row->refresh();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame(4, $row->attempts, 'Menunda bukan mencoba.');
        $this->assertSame(4, $counter->calls);
        $this->assertSame('2026-09-12 06:00', $row->next_attempt_at->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));
        $this->assertStringContainsString('Ditunda oleh jam tenang penerima (22:00–06:00 WIB) sampai 12 Sep 2026 06:00 WIB', (string) $row->error);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $job = DB::table('jobs')->sole();
        $this->assertSame('2026-09-12 06:00', CarbonImmutable::createFromTimestamp((int) $job->available_at, 'UTC')->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));
        $payload = json_decode((string) $job->payload, true);
        $this->assertSame(0, (int) $job->attempts, 'Job yang menunggu 06:00 belum dihitung sekali pun oleh pekerja.');
        $this->assertSame(1, (int) $payload['maxTries'], 'Sisa jadwal: satu percobaan lagi dari lima.');

        // 06:00 — percobaan KELIMA benar-benar terjadi; gagal lagi → failed dengan pesan PENYEDIA.
        $this->workAt('06:00', '2026-09-12');
        $row->refresh();
        $this->assertSame(5, $counter->calls, 'Kanal dipanggil lima kali: penundaan tidak memakan percobaan.');
        $this->assertSame(5, $row->attempts);
        $this->assertSame(NotificationDelivery::FAILED, $row->status);
        $this->assertSame('SMTP 421 4.7.0 try again later #5', $row->error);
        $this->assertStringNotContainsString('Ditunda', (string) $row->error);
        $this->assertStringNotContainsString('Percobaan habis', (string) $row->error);
        $this->assertNull($row->next_attempt_at);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    /** Jadwal 1/5/15/60 tidak diulang dari awal sesudah penundaan: ia berlanjut dari posisinya. */
    public function test_after_a_postponement_the_backoff_schedule_continues_where_it_stopped(): void
    {
        $this->emailOn();
        $counter = $this->refusingMail();
        config(['queue.default' => 'database']);
        $user = $this->holder();
        $this->prefer($user, 'notify.quiet_hours', ['start' => '22:00', 'end' => '06:00']);

        $this->atWib('21:40');
        $this->alarm();
        $row = NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_EMAIL)->sole();

        $this->workAt('21:40'); // ke-1 gagal → +60
        $this->workAt('21:41'); // ke-2 gagal → +300
        $this->workAt('21:46'); // ke-3 gagal → +900 = 22:01, di dalam jendela
        $row->refresh();
        $this->assertSame(3, $row->attempts);
        $this->assertSame('2026-09-11 22:01', $row->next_attempt_at->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));

        $this->workAt('22:01'); // ditunda sampai 06:00
        $row->refresh();
        $this->assertSame(3, $row->attempts);
        $this->assertSame(2, (int) json_decode((string) DB::table('jobs')->sole()->payload, true)['maxTries'], 'Dua percobaan tersisa dari lima.');

        $this->workAt('06:00', '2026-09-12'); // ke-4 gagal → +3600 (bukan +60: jadwal berlanjut)
        $row->refresh();
        $this->assertSame(4, $row->attempts);
        $this->assertSame(4, $counter->calls);
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame('2026-09-12 07:00', $row->next_attempt_at->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'), 'Setelah percobaan ke-4: 3600 s, posisi jadwal dipertahankan.');
        $this->assertSame(
            '2026-09-12 07:00',
            CarbonImmutable::createFromTimestamp((int) DB::table('jobs')->sole()->available_at, 'UTC')->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'),
            'Pekerja pun melepas job dengan jeda yang sama.',
        );

        $this->workAt('07:00', '2026-09-12'); // ke-5 gagal → failed
        $row->refresh();
        $this->assertSame(5, $row->attempts);
        $this->assertSame(NotificationDelivery::FAILED, $row->status);
        $this->assertSame('SMTP 421 4.7.0 try again later #5', $row->error);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Kalimat penundaan bukan pesan penyedia: bila pekerja menggagalkan job
     * tanpa sempat mendapat jawaban penyedia (kehabisan waktu, percobaan habis)
     * sementara kolom error masih memuat "Ditunda oleh jam tenang …", kalimat
     * itu tidak boleh dibawa sebagai "Pesan penyedia terakhir".
     */
    public function test_the_postponement_sentence_is_never_reported_as_the_providers_last_message(): void
    {
        $user = $this->holder();
        $this->prefer($user, 'notify.quiet_hours', ['start' => '22:00', 'end' => '06:00']);
        $sentence = QuietHours::forUser($user)->postponedSentence($this->atWib('06:00', '2026-09-12'));
        $this->assertStringStartsWith('Ditunda oleh jam tenang penerima (22:00–06:00 WIB) sampai 12 Sep 2026 06:00 WIB — tidak dibuang', $sentence);
        $notification = Notification::query()->create(['user_id' => $user->id, 'event' => Notification::SYSTEM, 'title' => 'Cadangan basi', 'body' => 'Isi.']);
        $make = fn () => NotificationDelivery::query()->create(['notification_id' => $notification->id, 'channel' => 'email', 'recipient' => $user->email, 'status' => 'queued', 'attempts' => 4, 'error' => $sentence]);

        $exhausted = $make();
        (new DeliverNotification($exhausted->id))->failed(new MaxAttemptsExceededException(DeliverNotification::class.' has been attempted too many times.'));
        $this->assertSame('Percobaan habis sebelum penyedia menjawab.', $exhausted->refresh()->error);

        $timedOut = $make();
        (new DeliverNotification($timedOut->id))->failed(new TimeoutExceededException(DeliverNotification::class.' has timed out.'));
        $this->assertSame('Pekerja antrean kehabisan waktu saat mengirim — penyedia tidak menjawab dalam batas waktu pekerja.', $timedOut->refresh()->error);

        // Pesan penyedia sungguhan tetap dibawa.
        $refused = $make();
        $refused->forceFill(['error' => 'SMTP 421 4.7.0 try again later'])->save();
        (new DeliverNotification($refused->id))->failed(new MaxAttemptsExceededException('x'));
        $this->assertSame('Percobaan habis sebelum penyedia menjawab. Pesan penyedia terakhir: SMTP 421 4.7.0 try again later', $refused->refresh()->error);
    }

    // ---------------------------------------------- GET me/notification-channels

    public function test_the_profile_endpoint_tells_the_same_truth_as_the_outbox(): void
    {
        $this->getJson('/api/core/me/notification-channels')->assertUnauthorized();

        $user = $this->holder();
        $this->actingAs($user, 'sanctum');

        // E-mail mati di Pengaturan: sebab yang sama dengan kotak keluar.
        $data = $this->getJson('/api/core/me/notification-channels')->assertOk()->json('data');
        $this->assertSame(['email', 'whatsapp'], array_column($data['channels'], 'channel'));
        $email = $data['channels'][0];
        $this->assertSame($user->email, $email['address']);
        $this->assertTrue($email['enabled_by_user']);
        $this->assertFalse($email['will_deliver']);
        $this->assertSame(DeliveryGate::EMAIL_DISABLED, $email['reason']);
        $this->assertNull($data['quiet_hours']);
        $this->assertFalse($data['quiet_now']);
        $this->assertSame('Asia/Jakarta', $data['zone']);

        // E-mail nyala, mailer sungguhan, pengguna mematikannya: sebab berpindah ke pengguna.
        $this->emailOn();
        $this->putJson('/api/core/me/preferences/notify.channels', ['value' => ['email' => false]])->assertOk();
        $this->putJson('/api/core/me/preferences/notify.quiet_hours', ['value' => ['start' => '22:00', 'end' => '06:00']])->assertOk();
        $this->atWib('23:00');

        $data = $this->getJson('/api/core/me/notification-channels')->assertOk()->json('data');
        $this->assertFalse($data['channels'][0]['enabled_by_user']);
        $this->assertSame(DeliveryGate::USER_OFF, $data['channels'][0]['reason']);
        $this->assertSame(['start' => '22:00', 'end' => '06:00', 'zone' => 'Asia/Jakarta'], $data['quiet_hours']);
        $this->assertTrue($data['quiet_now']);
        $this->assertSame('2026-09-12 06:00', CarbonImmutable::parse($data['postponed_until'])->setTimezone(QuietHours::ZONE)->format('Y-m-d H:i'));

        // Dinyalakan lagi: akan mencoba.
        $this->putJson('/api/core/me/preferences/notify.channels', ['value' => ['email' => true]])->assertOk();
        $data = $this->getJson('/api/core/me/notification-channels')->assertOk()->json('data');
        $this->assertTrue($data['channels'][0]['will_deliver']);
        $this->assertNull($data['channels'][0]['reason']);
    }
}
