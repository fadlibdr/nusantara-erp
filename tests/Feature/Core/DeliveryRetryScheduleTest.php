<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Core\Channels\MailChannel;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\NotificationTemplates;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Support\UsesCapturingMailer;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * T3a.4 — ulang-kirim 1/5/15/60 menit dan "in-app tetap kanal kebenaran"
 * (P-3a). Keduanya SUDAH ada sejak P-0b; paket ini memverifikasi dan
 * MEMAKUNYA, bukan membangun ulang.
 *
 *  - Jadwalnya dipaku sebagai ANGKA LITERAL (60/300/900/3600 detik, 5
 *    percobaan) — pelajaran F-6: uji yang membaca harapannya dari konstanta
 *    yang diujinya tidak menjaga apa pun — DAN diukur lewat pekerja
 *    sungguhan: next_attempt_at yang ditulis setelah percobaan ke-1..4 berjarak
 *    persis 60, 300, 900, 3600 detik dari saat gagal, dan kosong setelah ke-5.
 *  - Kanal kebenaran: dengan KEDUA kanal luar mati, dengan keduanya nyala
 *    tetapi ditolak penyedia, dan dengan tulisan kotak keluar yang gagal —
 *    baris kotak masuk tetap ada, seketika, untuk alarm sistem maupun
 *    peristiwa dokumen.
 */
class DeliveryRetryScheduleTest extends ErpTestCase
{
    use FinanceFixtures;
    use UsesCapturingMailer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function holder(string $permission, string $name): User
    {
        $role = Role::findOrCreate('peran-'.md5($permission.$name), 'web');
        $role->givePermissionTo($permission);
        $user = User::query()->create([
            'name' => $name, 'email' => str()->random(8).'@nusantara.test', 'password' => bcrypt('password'), 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function refusingMail(string $message): void
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

    // -------------------------------------------------------- jadwal 1/5/15/60

    public function test_the_schedule_is_one_five_fifteen_sixty_minutes_over_five_tries(): void
    {
        $this->assertSame([60, 300, 900, 3600], DeliverNotification::BACKOFF);
        $this->assertSame([60, 300, 900, 3600], (new DeliverNotification(1))->backoff());
        $this->assertSame(5, (new DeliverNotification(1))->tries);
        $this->assertSame(4, count(DeliverNotification::BACKOFF), 'Empat jeda di antara lima percobaan.');

        // Permukaan lain yang bisa mengubah jadwal diam-diam: unit systemd
        // pekerja (deploy/systemd/erp1-queue.service) — --tries pekerja
        // membatasi job yang TIDAK menyatakan $tries, dan --backoff=60 adalah
        // jeda untuk job tanpa backoff(). Keduanya harus tetap sejalan.
        $unit = (string) file_get_contents(base_path('deploy/systemd/erp1-queue.service'));
        $this->assertStringContainsString('queue:work database --tries=5 --backoff=60', $unit);
    }

    /** Diukur lewat pekerja sungguhan, bukan dihitung ulang dari konstanta. */
    public function test_a_real_worker_writes_the_next_attempt_at_exactly_those_intervals_then_none(): void
    {
        app(SettingService::class)->set('notifications.email_enabled', true);
        $this->useCapturingMailer();
        $this->refusingMail('SMTP 421 4.7.0 try again later');
        config(['queue.default' => 'database']);
        $this->holder('core.update', 'Direktur');
        Carbon::setTestNow('2026-09-11 09:00:00');

        app(NotificationService::class)->system('core.update', 'Cadangan basi', 'Isi.');
        $row = NotificationDelivery::query()->where('channel', 'email')->sole();

        $expected = [60, 300, 900, 3600, null];
        foreach ($expected as $i => $delay) {
            $attempt = $i + 1;
            $failedAt = CarbonImmutable::parse('2026-09-11 09:00:00')->addMinutes($attempt * 10);
            Carbon::setTestNow($failedAt);
            DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

            $row->refresh();
            $this->assertSame($attempt, $row->attempts);

            if ($delay === null) {
                $this->assertSame(NotificationDelivery::FAILED, $row->status);
                $this->assertNull($row->next_attempt_at, 'Percobaan terakhir: tidak ada jadwal.');
                $this->assertSame(0, DB::table('jobs')->count());
            } else {
                $this->assertSame(NotificationDelivery::QUEUED, $row->status);
                $this->assertSame($delay, (int) $failedAt->diffInSeconds($row->next_attempt_at), "Setelah percobaan ke-{$attempt}: {$delay} detik.");
                // Dan job yang dilepas pekerja memang tersedia pada saat itu.
                $job = DB::table('jobs')->sole();
                $this->assertSame($failedAt->getTimestamp() + $delay, (int) $job->available_at);
            }
        }
    }

    // ------------------------------------------------------ kanal kebenaran

    public function test_with_both_outside_channels_off_the_in_app_row_is_still_written_immediately(): void
    {
        $this->seedLedger(2026);
        $approver = $this->holder('fin.approve', 'Direktur');
        $admin = $this->holder('core.update', 'Admin');
        // Kedua sakelar mati (bawaan) — dan dipaku eksplisit.
        $this->assertFalse((bool) app(SettingService::class)->get('notifications.email_enabled'));
        $this->assertFalse((bool) app(SettingService::class)->get('notifications.whatsapp_enabled'));

        // Peristiwa dokumen.
        $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id, 'description' => 'Material', 'dpp' => 1_000_000,
            'bill_date' => '2026-03-10', 'vendor_invoice_no' => 'INV-'.str()->random(5),
        ])->submit($this->holder('fin.create', 'Staf'));
        // Alarm sistem dengan template.
        app(NotificationService::class)->system('core.update', 'Penjadwal tidak berjalan', 'Isi.', null, null, null, NotificationTemplates::SCHEDULER_DOWN);

        $this->assertSame(1, Notification::query()->where('user_id', $approver->id)->where('event', Notification::SUBMITTED)->count());
        $this->assertSame(1, Notification::query()->where('user_id', $admin->id)->where('event', Notification::SYSTEM)->count());

        $rows = NotificationDelivery::query()->get();
        $this->assertCount(4, $rows, 'Dua penerima × dua kanal luar.');
        $this->assertSame(['skipped'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame(['email', 'whatsapp'], $rows->pluck('channel')->unique()->sort()->values()->all());
        Http::assertNothingSent();
    }

    public function test_with_both_outside_channels_refusing_the_in_app_row_is_still_written(): void
    {
        app(SettingService::class)->set('notifications.email_enabled', true);
        app(SettingService::class)->set('notifications.whatsapp_enabled', true);
        $this->useCapturingMailer();
        $this->refusingMail('SMTP 550');
        config(['erp.whatsapp.token' => 'uji-token', 'erp.whatsapp.phone_number_id' => '1', 'erp.whatsapp.templates.scheduler.down' => 'erp_scheduler_down']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad', 'code' => 190]], 401)]);
        $admin = $this->holder('core.update', 'Admin');
        $admin->forceFill(['phone_e164' => '+628123456789', 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_via' => 'profil'])->save();

        // QUEUE_CONNECTION=sync: kedua job berjalan di dalam panggilan ini dan keduanya ditolak.
        app(NotificationService::class)->system('core.update', 'Penjadwal tidak berjalan', 'Isi.', null, null, null, NotificationTemplates::SCHEDULER_DOWN);

        $inApp = Notification::query()->where('user_id', $admin->id)->sole();
        $this->assertSame('Penjadwal tidak berjalan', $inApp->title);

        $byChannel = NotificationDelivery::query()->get()->keyBy('channel');
        $this->assertNotSame(NotificationDelivery::SENT, $byChannel['email']->status);
        $this->assertSame(NotificationDelivery::FAILED, $byChannel['whatsapp']->status);
        $this->assertStringContainsString('SMTP 550', (string) $byChannel['email']->error);
    }
}
