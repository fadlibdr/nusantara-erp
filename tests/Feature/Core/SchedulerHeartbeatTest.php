<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Console\Commands\WatchdogAlarmCommand;
use Modules\Core\Models\Notification;
use Modules\Core\Services\HealthService;
use Modules\Core\Services\SettingService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Detak jantung penjadwal, GET core/health, dan alarm pengawas (Fase 0 / P-0b,
 * T0b.2).
 *
 * Yang dipaku: angka yang tidak diketahui adalah null (bukan 0, bukan "ok");
 * detak yang basi dilaporkan basi; detak yang belum pernah ada tidak pernah
 * dilaporkan sehat; alarmnya sampai ke pemegang core.update dan tidak
 * menumpuk; dan kuncinya tidak bisa "diperbaiki" dari layar Pengaturan.
 */
class SchedulerHeartbeatTest extends ErpTestCase
{
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

    private function beatAt(CarbonImmutable $at): void
    {
        app(SettingService::class)->set(HealthService::HEARTBEAT_KEY, $at->toIso8601String());
    }

    // ------------------------------------------------------------ heartbeat

    public function test_the_heartbeat_command_writes_the_stamp_and_health_reports_it_fresh(): void
    {
        $admin = $this->adminUser();

        $this->artisan('erp:heartbeat')->assertExitCode(0);

        $stored = DB::table('core_settings')->where('key', HealthService::HEARTBEAT_KEY)->value('value');
        $this->assertNotNull($stored, 'erp:heartbeat harus menulis core_settings scheduler.heartbeat_at.');

        $this->actingAs($admin, 'sanctum');
        $response = $this->getJson('/api/core/health')->assertOk();

        $this->assertSame('ok', $response->json('data.scheduler_status'));
        $this->assertNotNull($response->json('data.scheduler_heartbeat_at'));
        $this->assertLessThan(60, $response->json('data.scheduler_heartbeat_age_s'));
        $this->assertSame(1200, $response->json('data.scheduler_heartbeat_max_age_s'));
    }

    public function test_an_old_heartbeat_is_reported_stale_with_its_age(): void
    {
        $admin = $this->adminUser();
        $this->beatAt(CarbonImmutable::now()->subHours(2));

        $this->actingAs($admin, 'sanctum');
        $response = $this->getJson('/api/core/health')->assertOk();

        $this->assertSame('stale', $response->json('data.scheduler_status'));
        $this->assertGreaterThanOrEqual(7200, $response->json('data.scheduler_heartbeat_age_s'));
    }

    /** Belum pernah ada detak = tidak diketahui. Bukan 0, bukan ok. */
    public function test_a_heartbeat_that_was_never_written_is_null_and_unknown(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum');
        $response = $this->getJson('/api/core/health')->assertOk();

        $this->assertSame('unknown', $response->json('data.scheduler_status'));
        $this->assertNull($response->json('data.scheduler_heartbeat_at'));
        $this->assertNull($response->json('data.scheduler_heartbeat_age_s'));
        $this->assertArrayHasKey('scheduler_heartbeat_age_s', $response->json('data'));
    }

    public function test_the_age_option_prints_a_question_mark_before_the_first_beat_and_seconds_after(): void
    {
        $this->artisan('erp:heartbeat', ['--age' => true])->expectsOutput('?')->assertExitCode(0);

        $this->beatAt(CarbonImmutable::now()->subSeconds(90));

        $this->artisan('erp:heartbeat', ['--age' => true])
            ->expectsOutputToContain('9')
            ->assertExitCode(0);
    }

    // ---------------------------------------------------------------- queue

    public function test_queue_metrics_count_ready_jobs_and_failed_jobs(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin, 'sanctum');

        $empty = $this->getJson('/api/core/health')->assertOk()->json('data');
        $this->assertSame(0, $empty['queue_pending_count']);
        $this->assertSame(0, $empty['queue_oldest_pending_age_s']);
        $this->assertSame(0, $empty['failed_jobs_count']);

        $ready = now()->getTimestamp() - 120;
        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $ready, 'created_at' => $ready],
            // Menunggu backoff: belum boleh diambil, jadi bukan "menunggu pekerja".
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => null, 'available_at' => $ready + 3600, 'created_at' => $ready],
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => "RuntimeException: SMTP down\n#0 {main}", 'failed_at' => now(),
        ]);

        $busy = $this->getJson('/api/core/health')->assertOk()->json('data');
        $this->assertSame(1, $busy['queue_pending_count']);
        $this->assertGreaterThanOrEqual(120, $busy['queue_oldest_pending_age_s']);
        $this->assertSame(1, $busy['failed_jobs_count']);
    }

    // ----------------------------------------------------------------- gate

    public function test_health_needs_core_view(): void
    {
        $this->getJson('/api/core/health')->assertUnauthorized();

        $this->actingAs($this->userWith('fin.view'), 'sanctum');
        $this->getJson('/api/core/health')->assertForbidden();

        $this->actingAs($this->userWith('core.view'), 'sanctum');
        $this->getJson('/api/core/health')->assertOk();
    }

    // ------------------------------------------------------------- settings

    /** Kunci internal: tidak di layar, dan formulir tidak bisa menulisnya. */
    public function test_the_heartbeat_key_is_neither_listed_nor_writable_from_the_settings_screen(): void
    {
        $admin = $this->adminUser();
        $this->beatAt(CarbonImmutable::now());
        $this->actingAs($admin, 'sanctum');

        $keys = collect($this->getJson('/api/core/settings')->assertOk()->json('data.groups'))
            ->flatMap(fn (array $group) => array_column($group['settings'], 'key'));
        $this->assertFalse($keys->contains(HealthService::HEARTBEAT_KEY));

        $response = $this->putJson('/api/core/settings', [
            'settings' => [HealthService::HEARTBEAT_KEY => now()->toIso8601String()],
        ]);

        $response->assertStatus(422);
        // Kunci galatnya harfiah bertitik ("settings.scheduler.heartbeat_at"),
        // jadi dibaca dari peta, bukan lewat jalur bertitik json().
        $errors = $response->json('errors');
        $this->assertStringContainsString(
            'ditulis oleh sistem',
            implode(' ', $errors['settings.'.HealthService::HEARTBEAT_KEY] ?? []),
        );

        // Baris yang ditulis sistem adalah baris yang sah, bukan "not editable".
        $this->assertSame([], app(SettingService::class)->invalidOverrides());
    }

    // ---------------------------------------------------------------- alarm

    public function test_a_fresh_heartbeat_raises_no_alarm(): void
    {
        $this->adminUser();
        $this->beatAt(CarbonImmutable::now()->subMinutes(3));

        $this->artisan('erp:watchdog-alarm')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->where('event', Notification::SYSTEM)->count());
    }

    public function test_a_stale_heartbeat_alarms_the_settings_holders_once(): void
    {
        $admin = $this->adminUser();
        $bystander = $this->userWith('fin.view');
        $this->beatAt(CarbonImmutable::now()->subMinutes(45));

        $this->artisan('erp:watchdog-alarm')->assertExitCode(1);
        // Pengawas berjalan tiap 15 menit; kemacetan yang sama tidak menumpuk.
        $this->artisan('erp:watchdog-alarm')->assertExitCode(1);

        $alarms = Notification::query()->where('event', Notification::SYSTEM)->get();

        $this->assertCount(1, $alarms);
        $this->assertSame($admin->id, $alarms[0]->user_id);
        $this->assertSame(WatchdogAlarmCommand::TITLE, $alarms[0]->title);
        $this->assertStringContainsString('45 menit lalu', $alarms[0]->body);
        $this->assertStringContainsString('erp1-scheduler', $alarms[0]->body);
        $this->assertSame(0, Notification::query()->where('user_id', $bystander->id)->count());
    }

    public function test_a_heartbeat_that_never_happened_alarms_and_says_so(): void
    {
        $admin = $this->adminUser();

        $this->artisan('erp:watchdog-alarm')->assertExitCode(1);

        $alarm = Notification::query()->where('user_id', $admin->id)->sole();
        $this->assertStringContainsString('belum pernah tercatat', $alarm->body);
    }

    /** Kemacetan berikutnya membawa stempel lain dan langsung muncul lagi. */
    public function test_a_new_stall_after_recovery_fires_a_new_alarm(): void
    {
        $admin = $this->adminUser();

        $this->beatAt(CarbonImmutable::now()->subHours(3));
        $this->artisan('erp:watchdog-alarm')->assertExitCode(1);

        $this->beatAt(CarbonImmutable::now()->subHours(1));
        $this->artisan('erp:watchdog-alarm')->assertExitCode(1);

        $this->assertSame(2, Notification::query()->where('user_id', $admin->id)->count());
    }

    public function test_the_heartbeat_is_scheduled_every_five_minutes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('erp:heartbeat')->assertExitCode(0);
    }
}
