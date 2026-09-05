<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Services\HealthService;
use Modules\Core\Services\NotificationService;

/**
 * Alarm dalam aplikasi ketika penjadwal berhenti (Fase 0 / P-0b, T0b.2).
 *
 * Dipanggil deploy/erp1-watchdog.sh (root, tiap 15 menit) setelah ia memulai
 * ulang erp1-scheduler. Perintah ini menghitung umur detaknya SENDIRI, bukan
 * mempercayai pemanggil: bila detaknya ternyata segar (unit sudah pulih dan
 * sempat menulis), ia diam dan keluar 0; bila basi atau belum pernah ada, ia
 * menaikkan alarm ke pemegang core.update — orang yang membuka Pengaturan —
 * dan keluar 1.
 *
 * De-dup lewat signature = stempel detak terakhir: satu kemacetan = satu
 * salinan yang belum dibaca, lalu diulang setiap 15 menit setelah dibaca
 * selama masih macet (NotificationService::system, renag null). Kemacetan
 * BERIKUTNYA membawa stempel lain dan langsung muncul lagi.
 */
class WatchdogAlarmCommand extends Command
{
    public const TITLE = 'Penjadwal tidak berjalan';

    protected $signature = 'erp:watchdog-alarm
        {--force : Naikkan alarm walau detak jantung masih segar (uji jalur notifikasi)}';

    protected $description = 'Naikkan alarm dalam aplikasi bila detak jantung penjadwal lebih tua dari ambang';

    public function handle(HealthService $health, NotificationService $notifications): int
    {
        $at = $health->heartbeatAt();
        $age = $health->heartbeatAgeSeconds($at);
        $status = $health->schedulerStatus($age);
        $maxMinutes = intdiv($health->maxHeartbeatAgeSeconds(), 60);

        if ($status === HealthService::STATUS_OK && ! $this->option('force')) {
            $this->info("Penjadwal sehat: detak terakhir {$age} detik lalu (ambang {$maxMinutes} menit).");

            return self::SUCCESS;
        }

        if ($at === null) {
            $body = 'Detak jantung penjadwal belum pernah tercatat: unit erp1-scheduler belum pernah '
                .'menjalankan erp:heartbeat sejak dipasang, atau tabel pengaturan tidak terbaca. '
                .'Periksa `systemctl status erp1-scheduler` dan /var/log/erp1/scheduler.log di server.';
        } else {
            $minutes = intdiv((int) $age, 60);
            $when = $at->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB';
            $body = "Detak jantung penjadwal terakhir {$when} ({$minutes} menit lalu; ambang {$maxMinutes} menit). "
                .'Pengawas telah mencoba memulai ulang erp1-scheduler — periksa `systemctl status erp1-scheduler` '
                .'dan /var/log/erp1/scheduler.log di server. Selama penjadwal berhenti, akrual alat, jadwal PM, '
                .'pengawas tenggat dan alarm cadangan tidak berjalan.';
        }

        $notifications->system(
            'core.update',
            self::TITLE,
            $body,
            null,
            null,
            $at?->toIso8601String() ?? 'never',
        );

        $this->warn(($at === null ? 'Detak jantung belum pernah ada' : "Detak jantung basi ({$age} detik)").' — alarm dinaikkan.');

        return self::FAILURE;
    }
}
