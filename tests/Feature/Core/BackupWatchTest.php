<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Core\Models\Notification;
use Modules\Core\Services\NotificationService;
use Tests\ErpTestCase;

/**
 * The bridge between the root-cron backup and the people who can fix it.
 *
 * deploy/backup-erp1.sh runs outside this application entirely; its failures
 * land in a log file and in cron mail addressed to a mailbox nobody has. The
 * one channel an operator reads every day is the ERP, so erp:backup-watch
 * reads the status file the script writes and raises an in-app alarm when the
 * offsite copy is unconfigured, failing, or stale.
 */
class BackupWatchTest extends ErpTestCase
{
    private string $statusFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statusFile = tempnam(sys_get_temp_dir(), 'erp1status');
        config(['erp.backup.status_file' => $this->statusFile]);
    }

    protected function tearDown(): void
    {
        @unlink($this->statusFile);
        parent::tearDown();
    }

    private function writeStatus(array $overrides = []): void
    {
        file_put_contents($this->statusFile, json_encode($overrides + [
            'configured' => true,
            'destination' => 'rsync:backup@host:/srv/erp1-backups',
            'last_run' => now()->toIso8601String(),
            'last_result' => 'ok',
            'last_success' => now()->toIso8601String(),
            'last_pushed' => 2,
            'remote_count' => 30,
            'newest_artifact' => now()->format('Ymd-His'),
            'last_drill' => now()->toIso8601String(),
            'last_drill_result' => 'passed',
            'key_fingerprint' => 'abc123',
        ]));
    }

    private function alarms(): Collection
    {
        return Notification::query()->where('event', Notification::SYSTEM)->get();
    }

    // ---------------------------------------------------------------- states

    public function test_a_healthy_offsite_copy_raises_nothing(): void
    {
        $this->adminUser();
        $this->writeStatus();

        $this->artisan('erp:backup-watch')->assertExitCode(0);

        $this->assertCount(0, $this->alarms());
    }

    /** The state this whole feature exists to make impossible to ignore. */
    public function test_an_unconfigured_offsite_copy_alarms_the_approvers(): void
    {
        $admin = $this->adminUser();
        $this->writeStatus(['configured' => false, 'last_success' => null]);

        $this->artisan('erp:backup-watch')->assertExitCode(1);

        $alarm = $this->alarms()->sole();
        $this->assertSame($admin->id, $alarm->user_id);
        $this->assertStringContainsString('offsite belum dikonfigurasi', $alarm->title);
        $this->assertStringContainsString('disk yang sama', $alarm->body);
    }

    /**
     * Configured once and then quietly broken is the more dangerous state:
     * everyone remembers setting it up and nobody is watching the log.
     */
    public function test_a_stale_offsite_copy_alarms_with_its_age(): void
    {
        $this->adminUser();
        $this->writeStatus(['last_success' => now()->subDays(5)->toIso8601String()]);

        $this->artisan('erp:backup-watch')->assertExitCode(1);

        $alarm = $this->alarms()->sole();
        $this->assertStringContainsString('macet', $alarm->title);
        $this->assertStringContainsString('5 hari', $alarm->body);
    }

    public function test_a_success_yesterday_is_not_stale(): void
    {
        $this->adminUser();
        $this->writeStatus(['last_success' => now()->subDay()->toIso8601String()]);

        $this->artisan('erp:backup-watch')->assertExitCode(0);

        $this->assertCount(0, $this->alarms());
    }

    public function test_a_garbled_status_file_is_an_alarm_not_a_crash(): void
    {
        $this->adminUser();
        file_put_contents($this->statusFile, 'bukan json {');

        $this->artisan('erp:backup-watch')->assertExitCode(1);

        $this->assertStringContainsString('tidak terbaca', $this->alarms()->sole()->title);
    }

    /** A dev machine has no status file and must stay silent. */
    public function test_a_missing_file_outside_production_stays_quiet(): void
    {
        $this->adminUser();
        unlink($this->statusFile);

        $this->artisan('erp:backup-watch')->assertExitCode(0);

        $this->assertCount(0, $this->alarms());
    }

    // -------------------------------------------- the laundering scenarios
    //
    // last_success proves the SYNC ran; none of these three can be caught by
    // it. They are the states the adversarial review showed could stay green
    // for weeks: a sync with nothing to push, an emptied remote, a restore
    // drill that failed.

    /**
     * The nightly local backup dies; the afternoon --offsite-only retry keeps
     * finding nothing new to push and keeps stamping a fresh last_success.
     * Only the newest artifact's own timestamp betrays that backups stopped.
     */
    public function test_a_fresh_sync_of_stale_artifacts_still_alarms(): void
    {
        $this->adminUser();
        $this->writeStatus([
            'last_success' => now()->toIso8601String(),
            'newest_artifact' => now()->subDays(6)->format('Ymd-His'),
        ]);

        $this->artisan('erp:backup-watch')->assertExitCode(1);

        $alarm = $this->alarms()->sole();
        $this->assertStringContainsString('menua', $alarm->title);
        $this->assertStringContainsString('cadangan LOKAL berhenti', $alarm->body);
    }

    public function test_an_empty_remote_alarms_even_with_a_fresh_success(): void
    {
        $this->adminUser();
        $this->writeStatus(['remote_count' => 0]);

        $this->artisan('erp:backup-watch')->assertExitCode(1);

        $this->assertStringContainsString('kosong', $this->alarms()->sole()->title);
    }

    public function test_a_failed_restore_drill_alarms(): void
    {
        $this->adminUser();
        $this->writeStatus(['last_drill_result' => 'failed']);

        $this->artisan('erp:backup-watch')->assertExitCode(1);

        $this->assertStringContainsString('pemulihan', $this->alarms()->sole()->title);
    }

    /** A garbled artifact stamp reads as unknown, and unknown is an alarm. */
    public function test_an_unparseable_artifact_stamp_is_an_alarm_not_freshness(): void
    {
        $this->adminUser();
        $this->writeStatus(['newest_artifact' => 'kemarin sore']);

        $this->artisan('erp:backup-watch')->assertExitCode(1);

        $this->assertStringContainsString('menua', $this->alarms()->sole()->title);
    }

    // ----------------------------------------------------------------- dedup

    /**
     * The watch runs daily; the alarm must nag, not bury. Nine unread copies
     * of the same warning read as noise, and noise is how an inbox dies.
     */
    public function test_an_unread_alarm_is_not_duplicated_the_next_day(): void
    {
        $this->adminUser();
        $this->writeStatus(['configured' => false, 'last_success' => null]);

        $this->artisan('erp:backup-watch')->assertExitCode(1);
        $this->artisan('erp:backup-watch')->assertExitCode(1);

        $this->assertCount(1, $this->alarms());
    }

    public function test_a_read_alarm_fires_again(): void
    {
        $this->adminUser();
        $this->writeStatus(['configured' => false, 'last_success' => null]);

        $this->artisan('erp:backup-watch');
        Notification::query()->update(['read_at' => now()]);
        $this->artisan('erp:backup-watch');

        $this->assertCount(2, Notification::query()->where('event', Notification::SYSTEM)->get());
    }

    // ------------------------------------------------------------ recipients

    /** The alarm goes to people who can make somebody fix it — core.approve. */
    public function test_the_alarm_reaches_only_core_approvers(): void
    {
        $admin = $this->adminUser();
        $bystander = User::query()->create([
            'name' => 'Staf Gudang', 'email' => 'gudang@test.local',
            'password' => 'password', 'is_active' => true,
        ]);

        $this->writeStatus(['configured' => false, 'last_success' => null]);
        $this->artisan('erp:backup-watch');

        $this->assertSame([$admin->id], $this->alarms()->pluck('user_id')->all());
        $this->assertNotContains($bystander->id, $this->alarms()->pluck('user_id')->all());
    }

    /** system() must never take the caller down with a delivery failure. */
    public function test_a_delivery_failure_is_swallowed_by_the_guard(): void
    {
        // No permission seeded at all: approvers() throws inside the guard.
        app(NotificationService::class)->system('perm.that.does.not.exist', 'Judul', 'Isi');

        $this->assertTrue(true, 'reaching here is the assertion');
    }
}
