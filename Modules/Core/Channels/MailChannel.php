<?php

namespace Modules\Core\Channels;

use Illuminate\Support\Facades\Mail;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Mail\ApprovalNotificationMail;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;

/**
 * Kanal e-mail: Mail::to()->send() yang dulu hidup di NotificationService::email(),
 * dipindah ke sini apa adanya. Tautan dokumen dibangun dari app.url + /app/ +
 * rute hash yang tersimpan di notifikasi — persis seperti sebelumnya.
 *
 * send() SINKRON (bukan ->queue()): pekerjaan ini sudah berjalan di dalam job
 * DeliverNotification; mengantrekannya sekali lagi hanya menyembunyikan
 * kegagalan SMTP dari baris pengiriman yang seharusnya mencatatnya.
 */
class MailChannel implements DeliveryChannel
{
    public function name(): string
    {
        return NotificationDelivery::CHANNEL_EMAIL;
    }

    public function send(NotificationDelivery $delivery, Notification $notification): ?string
    {
        $url = $notification->link === null
            ? null
            : rtrim((string) config('app.url'), '/').'/app/'.$notification->link;

        $sent = Mail::to($delivery->recipient)->send(
            new ApprovalNotificationMail($notification->title, (string) $notification->body, $url),
        );

        // Mail::fake() dan transport 'array'/'log' memulangkan null atau
        // pesan tanpa Message-ID; SMTP sungguhan memberi pengenalnya.
        try {
            $id = $sent?->getMessageId();
        } catch (\Throwable) {
            $id = null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }
}
