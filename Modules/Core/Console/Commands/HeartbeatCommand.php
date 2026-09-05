<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Services\HealthService;
use Modules\Core\Services\SettingService;

/**
 * Detak jantung penjadwal (Fase 0 / P-0b, T0b.2).
 *
 * Dijadwalkan tiap 5 menit oleh CoreServiceProvider. Yang ditulis hanya satu
 * stempel waktu di core_settings (scheduler.heartbeat_at) — cukup bagi
 * HealthService, spanduk dasbor, dan deploy/erp1-watchdog.sh untuk menjawab
 * satu pertanyaan yang sebelumnya tidak bisa dijawab siapa pun: apakah
 * penjadwal masih berjalan? Sebelum ini bukti satu-satunya adalah berhentinya
 * baris baru di /var/log/erp1-schedule.log (PANDUAN-ADMINISTRATOR §5.2).
 *
 * --age adalah sisi BACA untuk skrip shell: mencetak umur detak terakhir
 * dalam detik, atau `?` bila belum pernah ada — tidak pernah 0 untuk "tidak
 * tahu".
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'erp:heartbeat
        {--age : Cetak umur detak terakhir dalam detik (atau ? bila belum pernah ada) tanpa menulis apa pun}';

    protected $description = 'Tulis detak jantung penjadwal ke core_settings (scheduler.heartbeat_at)';

    public function handle(SettingService $settings, HealthService $health): int
    {
        if ($this->option('age')) {
            $age = $health->heartbeatAgeSeconds();
            $this->line($age === null ? '?' : (string) $age);

            return self::SUCCESS;
        }

        $now = now();
        $settings->set(HealthService::HEARTBEAT_KEY, $now->toIso8601String());

        $this->info('Detak jantung penjadwal dicatat: '.$now->toIso8601String());

        return self::SUCCESS;
    }
}
