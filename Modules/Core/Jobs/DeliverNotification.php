<?php

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Support\DeliveryChannels;
use Throwable;

/**
 * Sampaikan SATU baris core_notification_deliveries lewat kanalnya
 * (Fase 0 / P-0b, T0b.3).
 *
 * Yang dipegang job hanya id barisnya: baris itulah kebenaran tentang
 * pengiriman ini, dan pekerja yang berjalan setahun kemudian membacanya
 * segar dari basis data, bukan dari payload yang dibekukan saat dispatch.
 *
 * Percobaan: 5, backoff 60 / 300 / 900 / 3600 detik (ROADMAP P-0b). Setiap
 * kegagalan menaikkan attempts, menyimpan pesan penyedia (error, ≤ 500) dan
 * next_attempt_at, lalu MELEMPAR ULANG supaya pekerja yang menjadwalkan
 * percobaan berikutnya. Setelah percobaan terakhir pekerja memanggil failed():
 * status `failed`, pesan terakhir tetap di error, dan barisnya menunggu
 * "Kirim ulang" di Sistem › Pengiriman Notifikasi. Tidak ada jalur yang
 * membuat kegagalan tampak berhasil.
 *
 * ShouldQueueAfterCommit: dispatch dari dalam transaksi ditunda sampai
 * commit — baris pengiriman yang belum ter-commit tidak boleh dikerjakan
 * pekerja lain.
 */
class DeliverNotification implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public const BACKOFF = [60, 300, 900, 3600];

    public function __construct(public readonly int $deliveryId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::query()->with('notification')->find($this->deliveryId);

        // Baris dihapus (notifikasinya dihapus, cascade) atau sudah selesai
        // lewat jalur lain: tidak ada yang perlu dikirim, dan mengirim ulang
        // e-mail yang sudah `sent` adalah kesalahan yang lebih buruk.
        if ($delivery === null || $delivery->status !== NotificationDelivery::QUEUED || $delivery->notification === null) {
            return;
        }

        $delivery->attempts += 1;

        try {
            $providerId = DeliveryChannels::for($delivery->channel)->send($delivery, $delivery->notification);
        } catch (Throwable $e) {
            // Percobaan ke-n gagal: catat, jadwalkan, lempar ulang. Yang
            // menentukan jadwal adalah hitungan PEKERJA untuk job ini
            // ($this->attempts(), 1..$tries) — bukan attempts baris, yang
            // adalah riwayat kumulatif dan berlanjut setelah Kirim ulang (baris
            // gagal mulai di 5: BACKOFF[5] tidak ada → next_attempt_at kosong
            // padahal pekerja masih menjadwalkan empat percobaan lagi —
            // verifikasi P-0b, 5 Sep 2026). Indeks backoff = percobaan pekerja
            // yang baru gagal - 1; percobaan terakhir = next_attempt_at kosong.
            $attempt = $this->attempts();
            $delay = self::BACKOFF[$attempt - 1] ?? null;

            $delivery->forceFill([
                'error' => self::message($e),
                'next_attempt_at' => $delay === null || $attempt >= $this->tries ? null : now()->addSeconds($delay),
            ])->save();

            throw $e;
        }

        $delivery->forceFill([
            'status' => NotificationDelivery::SENT,
            'provider_id' => $providerId,
            'error' => null,
            'sent_at' => now(),
            'next_attempt_at' => null,
        ])->save();
    }

    /** Dipanggil pekerja setelah percobaan terakhir (atau job kedaluwarsa). */
    public function failed(?Throwable $e): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== NotificationDelivery::QUEUED) {
            return;
        }

        $delivery->forceFill([
            'status' => NotificationDelivery::FAILED,
            'error' => $e === null ? ($delivery->error ?? 'Gagal tanpa pesan.') : self::message($e),
            'next_attempt_at' => null,
        ])->save();
    }

    private static function message(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return Str::limit($message === '' ? get_class($e) : $message, 480);
    }
}
