<?php

namespace Modules\Core\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Services\NotificationService;

/**
 * Reads the offsite-backup status that deploy/backup-erp1.sh writes, and raises
 * an in-app alarm when the offsite copy is missing, failing, or stale.
 *
 * The backup runs as root under cron, entirely outside this application; its
 * failures land in /var/log/erp1-backup.log and in cron mail addressed to a
 * mailbox nobody has. This command is the bridge: the one channel an operator
 * actually looks at every day is the ERP itself, so that is where "your last
 * offsite copy is four days old" belongs. The alarm goes to holders of
 * core.approve — the people who can make somebody fix it.
 *
 * The status file is trusted but never required: a dev machine has none and
 * stays silent, a production machine without one has never completed a backup
 * run, which is itself the loudest alarm this command can raise.
 */
class BackupWatchCommand extends Command
{
    protected $signature = 'erp:backup-watch';

    protected $description = 'Raise an in-app alarm when the offsite backup copy is missing or stale';

    public function handle(NotificationService $notifications): int
    {
        $path = (string) config('erp.backup.status_file');
        $maxAgeDays = (int) config('erp.backup.offsite_max_age_days', 3);

        if (! is_file($path)) {
            if (! app()->environment('production')) {
                $this->info("No backup status at {$path} — not production, staying quiet.");

                return self::SUCCESS;
            }

            $notifications->system(
                'core.approve',
                'Cadangan tidak pernah berjalan',
                "Berkas status cadangan tidak ditemukan di {$path}. Cron backup di server "
                    .'kemungkinan tidak pernah berhasil satu kali pun — periksa /var/log/erp1-backup.log.',
            );
            $this->warn('Status file missing on production — alarm raised.');

            return self::FAILURE;
        }

        $status = json_decode((string) file_get_contents($path), true);

        if (! is_array($status)) {
            $notifications->system(
                'core.approve',
                'Status cadangan tidak terbaca',
                "Berkas {$path} bukan JSON yang sah — skrip backup kemungkinan gagal di tengah jalan.",
            );
            $this->warn('Status file unparseable — alarm raised.');

            return self::FAILURE;
        }

        if (($status['configured'] ?? false) !== true) {
            $notifications->system(
                'core.approve',
                'Salinan cadangan offsite belum dikonfigurasi',
                'Semua cadangan masih berada di disk yang sama dengan basis data yang '
                    .'dilindunginya. Bila disk itu mati, seluruh data perusahaan ikut mati. '
                    .'Isi /etc/erp1/backup.conf di server (contoh: deploy/erp1-backup.conf.example), '
                    .'lalu jalankan backup-erp1.sh --offsite-only dan --restore-drill.',
            );
            $this->warn('Offsite not configured — alarm raised.');

            return self::FAILURE;
        }

        $lastSuccess = $status['last_success'] ?? null;
        $age = $lastSuccess === null ? null : (int) CarbonImmutable::parse($lastSuccess)->diffInDays(now());

        if ($age === null || $age >= $maxAgeDays) {
            $days = $age === null ? 'belum pernah' : "terakhir {$age} hari lalu";

            $notifications->system(
                'core.approve',
                'Salinan cadangan offsite macet',
                "Sinkronisasi offsite ke {$status['destination']} {$days} berhasil "
                    ."(ambang: {$maxAgeDays} hari). Periksa /var/log/erp1-backup.log di server; "
                    .'jalankan backup-erp1.sh --offsite-only untuk mencoba ulang.',
            );
            $this->warn("Offsite stale ({$days}) — alarm raised.");

            return self::FAILURE;
        }

        /*
         * last_success only proves the SYNC ran. A sync with nothing to push
         * keeps "succeeding" forever after the local backup has died, and a
         * prune bug could empty the remote while stamping ok. The artifact's
         * own timestamp and the surviving count are the numbers that cannot
         * stay fresh on their own — so they are checked independently.
         */
        if (($status['remote_count'] ?? null) === 0) {
            $notifications->system(
                'core.approve',
                'Penyimpanan cadangan offsite kosong',
                "Sinkronisasi ke {$status['destination']} dilaporkan berhasil tetapi tidak ada "
                    .'satu pun arsip di tujuan. Cadangan offsite secara efektif tidak ada — '
                    .'periksa /var/log/erp1-backup.log di server.',
            );
            $this->warn('Offsite remote is empty — alarm raised.');

            return self::FAILURE;
        }

        $newest = $this->parseArtifactStamp($status['newest_artifact'] ?? null);
        $newestAge = $newest === null ? null : (int) $newest->diffInDays(now());

        if ($newestAge === null || $newestAge >= $maxAgeDays) {
            $when = $newestAge === null ? 'tidak diketahui' : "{$newestAge} hari";

            $notifications->system(
                'core.approve',
                'Arsip cadangan offsite menua',
                'Sinkronisasi offsite berjalan, tetapi arsip basis data terbaru di '
                    ."{$status['destination']} berumur {$when} (ambang: {$maxAgeDays} hari). "
                    .'Kemungkinan cadangan LOKAL berhenti dibuat — sinkronisasi yang tidak '
                    .'punya apa-apa untuk dikirim tetap tampak berhasil. Periksa '
                    .'/var/log/erp1-backup.log di server.',
            );
            $this->warn("Newest offsite artifact is {$when} old — alarm raised.");

            return self::FAILURE;
        }

        if (($status['last_drill_result'] ?? null) === 'failed') {
            $notifications->system(
                'core.approve',
                'Uji pemulihan cadangan gagal',
                "Restore drill terakhir terhadap {$status['destination']} GAGAL — salinan "
                    .'offsite mungkin tidak dapat dipulihkan. Jalankan backup-erp1.sh '
                    .'--restore-drill di server dan baca kegagalannya di /var/log/erp1-backup.log.',
            );
            $this->warn('Last restore drill failed — alarm raised.');

            return self::FAILURE;
        }

        $this->info("Offsite healthy: last success {$lastSuccess}, {$status['remote_count']} file(s), newest artifact {$newestAge}d old at {$status['destination']}.");

        return self::SUCCESS;
    }

    /**
     * The artifact stamp is the script's own filename format, YYYYMMDD-HHMMSS,
     * in the server's local time. Anything unparseable reads as null — and
     * null is treated as an alarm, never as fresh.
     */
    private function parseArtifactStamp(?string $stamp): ?CarbonImmutable
    {
        if ($stamp === null || preg_match('/^\d{8}-\d{6}$/', $stamp) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Ymd-His', $stamp);
        } catch (\Throwable) {
            return null;
        }
    }
}
