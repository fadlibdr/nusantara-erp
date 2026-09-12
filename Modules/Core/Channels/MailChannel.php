<?php

namespace Modules\Core\Channels;

use Illuminate\Support\Facades\Mail;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Exceptions\DeliverySkippedException;
use Modules\Core\Mail\ApprovalNotificationMail;
use Modules\Core\Mail\EventNotificationMail;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Support\MailTransport;
use Modules\Core\Support\NotificationTemplates;
use RuntimeException;

/**
 * Kanal e-mail: Mail::to()->send() yang dulu hidup di NotificationService::email(),
 * dipindah ke sini apa adanya. Tautan dokumen dibangun dari app.url + /app/ +
 * rute hash yang tersimpan di notifikasi — persis seperti sebelumnya.
 *
 * send() SINKRON (bukan ->queue()): pekerjaan ini sudah berjalan di dalam job
 * DeliverNotification; mengantrekannya sekali lagi hanya menyembunyikan
 * kegagalan SMTP dari baris pengiriman yang seharusnya mencatatnya.
 *
 * DUA KEJUJURAN yang ditambahkan P-3a (11 Sep 2026):
 *
 *  1. Mailer `log`/`array`/`null` tidak MENGIRIM apa pun — tetapi Mail::send()
 *     tetap memulangkan SentMessage ber-Message-ID buatan lokal, dan sampai
 *     P-3a job menandai barisnya `sent` (diukur: provider_id
 *     "…@example.co.id" pada MAIL_MAILER=log, keadaan produksi). Sekarang kanal
 *     MELEMPAR DeliverySkippedException dengan kalimatnya sebelum menyentuh
 *     Mail:: — barisnya `skipped`. Diperiksa di sini juga, bukan hanya di
 *     kotak keluar: konfigurasi bisa berganti antara baris ditulis dan job
 *     dijalankan, dan Kirim ulang membaca keadaan saat ini.
 *  2. Tanpa Message-ID tidak ada `sent`. Mailer yang memulangkan null (Mail::fake
 *     di uji, mailer kustom yang tidak mengembalikan SentMessage) tidak memberi
 *     bukti apa pun bahwa surat diterima; kanal melempar RuntimeException biasa
 *     — barisnya `failed` setelah percobaan habis, bukan `sent` kosong. Message-ID
 *     e-mail dibuat klien (Symfony), tetapi ia hanya sampai ke sini setelah
 *     percakapan SMTP selesai dengan 250: itulah "penyedia menerima" untuk
 *     kanal ini.
 */
class MailChannel implements DeliveryChannel
{
    public function name(): string
    {
        return NotificationDelivery::CHANNEL_EMAIL;
    }

    public function send(NotificationDelivery $delivery, Notification $notification): ?string
    {
        $skip = MailTransport::skipReason();

        if ($skip !== null) {
            throw new DeliverySkippedException($skip);
        }

        $url = $notification->link === null
            ? null
            : rtrim((string) config('app.url'), '/').'/app/'.$notification->link;

        // Template per peristiwa (T3a.1) bila notifikasinya menyebut salah satu
        // dari lima kunci NotificationTemplates; selain itu TEMPLATE UMUM
        // P-0b — dikatakan di sini, bukan ditebak dari judul.
        $template = NotificationTemplates::forNotification($notification);
        $mailable = $template === null
            ? new ApprovalNotificationMail($notification->title, (string) $notification->body, $url)
            : new EventNotificationMail($template, $notification->title, (string) $notification->body, $url);

        $sent = Mail::to($delivery->recipient)->send($mailable);

        try {
            $id = $sent?->getMessageId();
        } catch (\Throwable) {
            $id = null;
        }

        if (! is_string($id) || trim($id) === '') {
            throw new RuntimeException(
                'Mailer tidak memulangkan Message-ID, jadi tidak ada bukti surat diterima server — '
                .'status tidak bisa ditandai terkirim.',
            );
        }

        return trim($id);
    }
}
