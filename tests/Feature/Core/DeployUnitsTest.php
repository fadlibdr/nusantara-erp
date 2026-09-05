<?php

namespace Tests\Feature\Core;

use Illuminate\Filesystem\Filesystem;
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

    /**
     * Cut-over MySQL memarkir pengawas di `down`/`rollback` dan mengembalikannya
     * di `up`/`rollback`: pengawas root yang dibiarkan hidup memulai ulang
     * erp1-scheduler 20 menit setelah `down` dan menulis alarm + detak ke SQLite
     * yang dibekukan — gerbang sha256 di `salin`/`rollback` lalu gagal
     * (verifikasi P-0b, 5 Sep 2026).
     */
    public function test_the_cutover_parks_the_watchdog_before_stopping_the_units_and_restores_it_after(): void
    {
        $cutover = (string) file_get_contents(base_path('deploy/cutover-erp1.sh'));

        $this->assertStringContainsString('WATCHDOG_CRON=/etc/cron.d/erp1-watchdog', $cutover);
        // Titik dalam nama: cron mengabaikan berkas /etc/cron.d yang mengandungnya.
        $this->assertStringContainsString('WATCHDOG_PARKED=/etc/cron.d/erp1-watchdog.cutover-parked', $cutover);

        foreach (['step_down', 'step_rollback'] as $step) {
            $body = $this->functionBody($cutover, $step);
            $park = strpos($body, 'watchdog_park');
            $stop = strpos($body, 'units_stop');
            $this->assertNotFalse($park, "{$step} harus memarkir pengawas.");
            $this->assertNotFalse($stop);
            $this->assertLessThan($stop, $park, "{$step}: pengawas diparkir SEBELUM unit dihentikan.");
        }

        foreach (['step_up', 'step_rollback'] as $step) {
            $body = $this->functionBody($cutover, $step);
            $restore = strpos($body, 'watchdog_restore');
            $start = strpos($body, 'units_start');
            $this->assertNotFalse($restore, "{$step} harus mengembalikan pengawas.");
            $this->assertNotFalse($start);
            $this->assertGreaterThan($start, $restore, "{$step}: pengawas kembali SESUDAH unit dinyalakan.");
        }
    }

    /**
     * Lapisan kedua: `php artisan down` tangan (tanpa cutover-erp1.sh) — skrip
     * pengawas diam selama storage/framework/down ada, tanpa memanggil artisan
     * sama sekali; tanpa berkas itu ia berjalan seperti biasa (detak `?` →
     * MACET → erp:watchdog-alarm, keluar 1). Dijalankan sungguhan dengan `php`
     * palsu di PATH yang mencatat setiap panggilannya.
     */
    public function test_the_watchdog_script_stands_down_in_maintenance_mode(): void
    {
        $bash = (new ExecutableFinder)->find('bash');

        if ($bash === null) {
            $this->markTestSkipped('bash tidak ditemukan di host ini.');
        }

        $site = sys_get_temp_dir().'/erp1-watchdog-'.uniqid();
        $bin = $site.'/bin';
        mkdir($site.'/storage/framework', 0755, true);
        mkdir($bin, 0755, true);
        $calls = $site.'/php-calls.log';
        file_put_contents($bin.'/php', implode("\n", [
            '#!/usr/bin/env bash',
            'printf \'%s\\n\' "$*" >> \''.$calls.'\'',
            'case "$*" in *--age*) echo \'?\' ;; esac',
            '',
        ]));
        chmod($bin.'/php', 0755);

        $env = [
            'PATH' => $bin.':'.getenv('PATH'),
            'ERP1_SITE' => $site,
            'ERP1_RUN_AS' => trim((string) shell_exec('id -un')),
            'ERP1_SCHEDULER_UNIT' => 'erp1-scheduler-uji-tidak-ada',
        ];

        try {
            // Mode pemeliharaan: diam, keluar 0, php tidak pernah dipanggil.
            touch($site.'/storage/framework/down');
            $process = new Process([$bash, base_path('deploy/erp1-watchdog.sh')], null, $env);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('mode pemeliharaan', $process->getOutput());
            $this->assertFileDoesNotExist($calls, 'Dalam mode pemeliharaan artisan tidak boleh dipanggil.');

            // Tanpa berkas down: jalur biasa — detak `?` = macet, alarm dinaikkan.
            unlink($site.'/storage/framework/down');
            $process = new Process([$bash, base_path('deploy/erp1-watchdog.sh')], null, $env);
            $process->run();
            $this->assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('PENJADWAL MACET', $process->getOutput());
            $this->assertStringContainsString('artisan erp:heartbeat --age', (string) file_get_contents($calls));
            $this->assertStringContainsString('artisan erp:watchdog-alarm', (string) file_get_contents($calls));
        } finally {
            (new Filesystem)->deleteDirectory($site);
        }
    }

    /**
     * Urutan pasang di README adalah bagian dari keselamatan: baris cron
     * schedule:run dihapus SEBELUM `systemctl enable --now` (dua penjadwal =
     * setiap perintah harian jalan dua kali, dan tidak ada withoutOverlapping
     * di jadwal mana pun), dan pengawas baru dijalankan SETELAH detak jantung
     * pertama ada (sebelumnya `?` = macet → unit yang baru dinyalakan dimulai
     * ulang dan alarm palsu ke semua pemegang core.update) — verifikasi P-0b,
     * 5 Sep 2026.
     */
    public function test_the_readme_install_order_is_cron_off_then_enable_then_wait_for_the_first_heartbeat(): void
    {
        $readme = (string) file_get_contents(base_path('deploy/systemd/README.md'));
        $this->assertSame(1, preg_match('/## Pasang.*?```bash\n(.*?)```/s', $readme, $m), 'Blok pasang tidak ditemukan.');
        $install = $m[1];

        $this->assertSame(1, preg_match('/^sed -i .*schedule:run.* \/etc\/cron\.d\/erp1$/m', $install, $sed, PREG_OFFSET_CAPTURE), 'README harus menghapus baris schedule:run dari /etc/cron.d/erp1.');
        $cronOff = $sed[0][1];
        $enable = strpos($install, 'systemctl enable --now erp1-queue erp1-scheduler');
        $this->assertNotFalse($enable);
        $this->assertLessThan($enable, $cronOff, 'Baris cron dihapus SEBELUM unit dinyalakan.');

        $this->assertStringContainsString('withoutOverlapping', $install);
        $this->assertStringContainsString('05:00', $install, 'Jendela perintah harian disebut.');

        // Pengawas: tunggu detak pertama (erp:heartbeat --age bukan `?`) SEBELUM
        // menjalankannya sekali dan SEBELUM memasang berkas cron-nya.
        $wait = strpos($install, 'erp:heartbeat --age');
        $runOnce = strpos($install, 'bash $SITE/deploy/erp1-watchdog.sh');
        $cron = strpos($install, 'install -m 0644 $SITE/deploy/cron.d/erp1-watchdog /etc/cron.d/erp1-watchdog');
        $this->assertNotFalse($wait, 'README harus menunggu detak pertama.');
        $this->assertNotFalse($runOnce);
        $this->assertNotFalse($cron);
        $this->assertGreaterThan($enable, $wait, 'Menunggu detak baru masuk akal setelah unit dinyalakan.');
        $this->assertLessThan($runOnce, $wait, 'Pengawas dijalankan SETELAH detak pertama ada.');
        $this->assertLessThan($cron, $wait, 'Berkas cron pengawas dipasang SETELAH detak pertama ada.');
    }

    /** Isi satu fungsi bash `name() { … }` — sampai `}` pertama di awal baris. */
    private function functionBody(string $script, string $name): string
    {
        $this->assertSame(1, preg_match('/^'.preg_quote($name, '/').'\(\) \{\n(.*?)^\}/ms', $script, $m), "Fungsi {$name} tidak ditemukan.");

        return $m[1];
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

        // Batas waktu pekerja eksplisit, di bawah retry_after antrean database
        // (job yang sama diambil pekerja lain sementara yang lama masih
        // memegangnya), dan batas soket SMTP di bawah batas pekerja: host SMTP
        // yang bisu harus gagal sebagai pesan penyedia di baris pengiriman,
        // bukan sebagai pekerja yang dibunuh pcntl tanpa mencatat apa pun.
        $this->assertSame(1, preg_match('/^ExecStart=.*--timeout=(\d+)\b/m', $queue, $timeout), 'erp1-queue harus menyatakan --timeout.');
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), (int) $timeout[1]);
        $this->assertIsInt(config('mail.mailers.smtp.timeout'), 'smtp.timeout harus angka, bukan null (= default_socket_timeout).');
        $this->assertLessThan((int) $timeout[1], config('mail.mailers.smtp.timeout'));

        // Deploy memulai ulang keduanya agar pekerja memuat kode baru — dan
        // hanya bila unitnya sudah dipasang (is-enabled), supaya host yang
        // masih di cron tidak gagal deploy.
        $sync = (string) file_get_contents(base_path('deploy/sync-erp1.sh'));
        $this->assertStringContainsString('systemctl is-enabled --quiet "$unit"', $sync);
        $this->assertStringContainsString('systemctl restart "$unit"', $sync);
    }
}
