<?php

namespace Modules\Core\Support;

use Modules\Core\Channels\MailChannel;
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
 * (webpush — Fase 3 P-3e) MELEMPAR dengan kalimat yang menyebutnya:
 * job gagal, barisnya `failed` dengan alasan itu, bukan `sent` kosong.
 * WhatsApp ada sejak P-3a (T3a.3).
 */
class DeliveryChannels
{
    /** @var array<string, class-string<DeliveryChannel>> */
    private const CHANNELS = [
        NotificationDelivery::CHANNEL_EMAIL => MailChannel::class,
        NotificationDelivery::CHANNEL_WHATSAPP => WhatsAppChannel::class,
    ];

    public static function for(string $channel): DeliveryChannel
    {
        $class = self::CHANNELS[$channel] ?? null;

        if ($class === null) {
            throw new RuntimeException(in_array($channel, NotificationDelivery::CHANNELS, true)
                ? "Kanal {$channel} belum tersedia (Fase 3, P-3e)."
                : "Kanal {$channel} tidak dikenal.");
        }

        return app($class);
    }
}
