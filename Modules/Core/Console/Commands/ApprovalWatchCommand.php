<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Services\NotificationService;
use Modules\Core\Support\ApprovalQueue;
use Modules\Core\Support\Erp;

/**
 * Umur antrean persetujuan — tanggal yang tidak diawasi siapa pun.
 *
 * WatchedDeadlines mengawasi 19 tanggal (PO dijanjikan, PKWT berakhir, pajak
 * masa …) tetapi tidak satu pun "dokumen ini menunggu persetujuan sejak …",
 * karena tanggal itu tidak hidup di satu kolom satu tabel melainkan di baris
 * `submitted` core_approvals untuk 28 jenis dokumen. Diukur di produksi
 * 4 Sep 2026: pembayaran keluar Rp 10 jt menunggu 33 hari tanpa satu pun
 * pengingat — notifikasi pengajuan pertama sudah lama terbaca dan tenggelam.
 *
 * Dua tingkat, seperti deadline-watch: setelah `approvals.aging_days` hari
 * semua pemegang izin approve diingatkan lagi (dedupe per dokumen lewat
 * signature, diulang tiap 3 hari); setelah dua kalinya, direktur (pemegang
 * fin.approve) ikut diberi tahu — eskalasi, bukan pengingat. Pengaju sendiri
 * tidak pernah menjadi penerima: ia tidak bisa berbuat apa-apa.
 */
class ApprovalWatchCommand extends Command
{
    protected $signature = 'erp:approval-watch {--dry-run : Tampilkan saja, jangan kirim notifikasi}';

    protected $description = 'Ingatkan penyetuju (dan eskalasi ke direktur) untuk dokumen yang menunggu persetujuan terlalu lama';

    public function handle(NotificationService $notifications): int
    {
        $agingDays = max(1, Erp::int('approvals.aging_days', 5));
        $queue = ApprovalQueue::pending(null);
        $stale = array_values(array_filter($queue['rows'], fn ($r) => ($r['days_waiting'] ?? 0) >= $agingDays));

        foreach ($queue['failed'] as $label) {
            $this->warn("Dilewati: {$label} (gagal dibaca)");
        }
        if (!$stale) {
            $this->info("Tidak ada dokumen menunggu ≥ {$agingDays} hari.");

            return self::SUCCESS;
        }

        foreach ($stale as $row) {
            $escalate = $row['days_waiting'] >= $agingDays * 2;
            $amount = $row['amount'] !== null ? ' · Rp '.number_format($row['amount'], 0, ',', '.') : '';
            $title = $escalate
                ? "Eskalasi: {$row['label']} {$row['code']} menunggu {$row['days_waiting']} hari"
                : "{$row['label']} {$row['code']} menunggu persetujuan {$row['days_waiting']} hari";
            $body = "{$row['code']}{$amount}"
                .($row['submitted_by'] ? " · diajukan oleh {$row['submitted_by']}" : '')
                .' · belum diputus. Buka dokumennya untuk menyetujui atau menolak.';

            $this->line(($escalate ? '[ESKALASI] ' : '[INGAT]    ')."{$row['code']} {$row['label']} {$row['days_waiting']} hari");
            if ($this->option('dry-run')) {
                continue;
            }

            // Signature = kode dokumen: satu pengingat hidup per dokumen; body
            // (umur) boleh berubah tiap hari tanpa membanjiri kotak masuk.
            $notifications->system($row['permission'], $title, $body, $row['link'], 3, $row['code']);
            if ($escalate && $row['permission'] !== 'fin.approve') {
                $notifications->system('fin.approve', $title, $body, $row['link'], 3, $row['code']);
            }
        }

        $this->info(count($stale).' dokumen menunggu ≥ '.$agingDays.' hari.');

        return self::SUCCESS;
    }
}
