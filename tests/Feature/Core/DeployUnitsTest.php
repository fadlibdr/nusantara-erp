<?php

namespace Tests\Feature\Core;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Berkas di deploy/ dijalankan oleh root di server produksi dan tidak pernah
 * lewat interpreter apa pun sebelum itu. Yang dipaku di sini adalah tiga
 * hubungan yang bisa bergeser diam-diam antar berkas yang saling menunjuk
 * (Fase 0 / P-0b, T0b.1):
 *
 *  1. setiap skrip shell masih bisa di-parse bash (`bash -n`) — deploy 5 Sep
 *     2026 mati karena satu baris `#` di dalam lanjutan backslash, dan tidak
 *     ada yang memberi tahu sebelum rsync berjalan tanpa argumen;
 *  2. setiap berkas log yang ditulis unit systemd (`append:`) dirotasi oleh
 *     deploy/logrotate/erp1 — log yang tidak dirotasi memenuhi disk yang sama
 *     dengan basis data;
 *  3. unit antrean memenuhi kontrak roadmap (`queue:work database --tries=5`)
 *     dan keduanya berjalan sebagai www-data dari direktori situs.
 *
 * Tanpa basis data: TestCase polos, bukan ErpTestCase.
 */
class DeployUnitsTest extends TestCase
{
    private const SITE = '/var/www/erp1.pi2.co.id';

    public function test_every_deploy_shell_script_parses(): void
    {
        $bash = (new ExecutableFinder)->find('bash');

        if ($bash === null) {
            $this->markTestSkipped('bash tidak ditemukan di host ini.');
        }

        $scripts = array_merge(
            glob(base_path('deploy/*.sh')) ?: [],
            glob(base_path('deploy/*/*.sh')) ?: [],
        );

        $this->assertNotEmpty($scripts, 'Tidak ada skrip shell di deploy/ — glob-nya yang salah, bukan deploy-nya.');

        foreach ($scripts as $script) {
            $process = new Process([$bash, '-n', $script]);
            $process->run();

            $this->assertSame(
                0,
                $process->getExitCode(),
                basename($script).' gagal bash -n: '.trim($process->getErrorOutput()),
            );
        }
    }

    public function test_every_log_a_unit_appends_to_is_rotated(): void
    {
        $rotated = (string) file_get_contents(base_path('deploy/logrotate/erp1'));
        $units = glob(base_path('deploy/systemd/*.service')) ?: [];

        $this->assertNotEmpty($units);

        foreach ($units as $unit) {
            preg_match_all('/^Standard(?:Output|Error)=append:(\S+)$/m', (string) file_get_contents($unit), $found);

            $this->assertNotEmpty($found[1], basename($unit).' tidak menulis log ke berkas (append:).');

            foreach (array_unique($found[1]) as $path) {
                $this->assertStringContainsString(
                    $path,
                    $rotated,
                    basename($unit)." menulis ke {$path}, tetapi deploy/logrotate/erp1 tidak merotasinya.",
                );
            }
        }

        // Unit menulis dengan append: dan tidak membuka ulang berkasnya; tanpa
        // copytruncate rotasi meninggalkan proses menulis ke inode lama.
        $this->assertStringContainsString('copytruncate', $rotated);
    }

    /**
     * Pengawas (T0b.2): cron root menunjuk skrip yang ada; skrip memulai
     * ulang unit penjadwal dan memanggil erp:watchdog-alarm — dua nama yang
     * hidup di tiga berkas berbeda dan bisa bergeser sendiri-sendiri.
     */
    public function test_the_watchdog_cron_points_at_a_script_that_restarts_the_scheduler(): void
    {
        $cron = (string) file_get_contents(base_path('deploy/cron.d/erp1-watchdog'));

        $this->assertMatchesRegularExpression('/^\*\/15 \* \* \* \* root bash (\S+)/m', $cron);
        preg_match('/^\*\/15 \* \* \* \* root bash (\S+)/m', $cron, $m);

        $this->assertSame(self::SITE.'/deploy/erp1-watchdog.sh', $m[1]);
        $this->assertFileExists(base_path('deploy/erp1-watchdog.sh'));

        $script = (string) file_get_contents(base_path('deploy/erp1-watchdog.sh'));
        $this->assertStringContainsString('erp:heartbeat --age', $script);
        $this->assertStringContainsString('erp:watchdog-alarm', $script);
        $this->assertStringContainsString('systemctl restart "$UNIT"', $script);
        $this->assertStringContainsString('erp1-scheduler', $script);

        // Log pengawas ikut dirotasi.
        $this->assertStringContainsString('/var/log/erp1/watchdog.log', $cron);
        $this->assertStringContainsString('/var/log/erp1/watchdog.log', (string) file_get_contents(base_path('deploy/logrotate/erp1')));
    }

    public function test_the_units_meet_the_roadmap_contract(): void
    {
        $queue = (string) file_get_contents(base_path('deploy/systemd/erp1-queue.service'));
        $scheduler = (string) file_get_contents(base_path('deploy/systemd/erp1-scheduler.service'));

        foreach (['erp1-queue' => $queue, 'erp1-scheduler' => $scheduler] as $name => $unit) {
            $this->assertMatchesRegularExpression('/^User=www-data$/m', $unit, "{$name} harus berjalan sebagai www-data.");
            $this->assertMatchesRegularExpression('/^WorkingDirectory='.preg_quote(self::SITE, '/').'$/m', $unit, "{$name} harus berjalan dari direktori situs.");
            $this->assertMatchesRegularExpression('/^Restart=always$/m', $unit, "{$name} harus Restart=always.");
        }

        $this->assertMatchesRegularExpression(
            '/^ExecStart=\S+php artisan queue:work database .*--tries=5\b/m',
            $queue,
            'erp1-queue harus menjalankan queue:work database --tries=5 (ROADMAP-HASHMICRO P-0b).',
        );
        $this->assertMatchesRegularExpression('/^ExecStart=\S+php artisan schedule:work$/m', $scheduler);

        // Deploy memulai ulang keduanya agar pekerja memuat kode baru — dan
        // hanya bila unitnya sudah dipasang (is-enabled), supaya host yang
        // masih di cron tidak gagal deploy.
        $sync = (string) file_get_contents(base_path('deploy/sync-erp1.sh'));
        $this->assertStringContainsString('systemctl is-enabled --quiet "$unit"', $sync);
        $this->assertStringContainsString('systemctl restart "$unit"', $sync);
    }
}
