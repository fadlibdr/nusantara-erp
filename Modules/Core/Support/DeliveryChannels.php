<?php

namespace Modules\Core\Support;

use Modules\Core\Channels\MailChannel;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Models\NotificationDelivery;
use RuntimeException;

/**
 * Peta nama kanal → implementasi (Fase 0 / P-0b, T0b.3).
 *
 * Diresolusi lewat container supaya sebuah uji bisa mengganti kanal dengan
 * stub yang melempar (app()->instance(MailChannel::class, …)). Kanal yang
 * ada di NotificationDelivery::CHANNELS tetapi belum diimplementasikan
 * (whatsapp, webpush — Fase 3) MELEMPAR dengan kalimat yang menyebutnya:
 * job gagal, barisnya `failed` dengan alasan itu, bukan `sent` kosong.
 */
class DeliveryChannels
{
    /** @var array<string, class-string<DeliveryChannel>> */
    private const CHANNELS = [
        NotificationDelivery::CHANNEL_EMAIL => MailChannel::class,
    ];

    public static function for(string $channel): DeliveryChannel
    {
        $class = self::CHANNELS[$channel] ?? null;

        if ($class === null) {
            throw new RuntimeException(in_array($channel, NotificationDelivery::CHANNELS, true)
                ? "Kanal {$channel} belum tersedia (Fase 3)."
                : "Kanal {$channel} tidak dikenal.");
        }

        return app($class);
    }
}
