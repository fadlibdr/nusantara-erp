<?php

namespace Modules\Core\Support;

use Modules\Core\Channels\MailChannel;
use Modules\Core\Channels\WebPushChannel;
use Modules\Core\Channels\WhatsAppChannel;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Models\NotificationDelivery;
use RuntimeException;

/**
 * Peta nama kanal → implementasi (Fase 0 / P-0b, T0b.3).
 *
 * Diresolusi lewat container supaya sebuah uji bisa mengganti kanal dengan
 * stub yang melempar (app()->instance(MailChannel::class, …)). Kanal yang
 * ada di NotificationDelivery::CHANNELS tetapi belum diimplementasikan
 * MELEMPAR dengan kalimat yang menyebutnya: job gagal, barisnya `failed`
 * dengan alasan itu, bukan `sent` kosong.
 *
 * WhatsApp ada sejak P-3a (T3a.3); web push sejak P-3e (T3e.3) — sejak itu
 * KETIGA kanal NotificationDelivery::CHANNELS punya implementasi, dan cabang
 * "belum tersedia" di bawah tidak lagi bisa dicapai oleh nama yang sah. Ia
 * tetap ada untuk kanal keempat yang didaftarkan di CHANNELS lebih dulu
 * daripada pengirimnya.
 */
class DeliveryChannels
{
    /** @var array<string, class-string<DeliveryChannel>> */
    private const CHANNELS = [
        NotificationDelivery::CHANNEL_EMAIL => MailChannel::class,
        NotificationDelivery::CHANNEL_WHATSAPP => WhatsAppChannel::class,
        NotificationDelivery::CHANNEL_WEBPUSH => WebPushChannel::class,
    ];

    public static function for(string $channel): DeliveryChannel
    {
        $class = self::CHANNELS[$channel] ?? null;

        if ($class === null) {
            throw new RuntimeException(in_array($channel, NotificationDelivery::CHANNELS, true)
                ? "Kanal {$channel} terdaftar tetapi pengirimnya belum ditulis."
                : "Kanal {$channel} tidak dikenal.");
        }

        return app($class);
    }
}
