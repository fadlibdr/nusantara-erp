<?php

namespace Modules\Core\Contracts;

use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;

/**
 * Satu kanal luar untuk menyampaikan notifikasi (Fase 0 / P-0b, T0b.3).
 *
 * Kontraknya sengaja sempit: kirim SATU baris pengiriman, kembalikan pengenal
 * dari penyedia bila ada (Message-ID e-mail, wamid WhatsApp), dan LEMPAR bila
 * gagal — pesan pengecualian itulah yang disimpan DeliverNotification ke
 * kolom error dan yang dibaca operator di layar. Kanal tidak boleh menelan
 * kegagalannya sendiri; yang menelan adalah yang membuat pengiriman gagal
 * tampak berhasil.
 *
 * Fase 3 menambah WhatsApp (Meta Cloud API / Qontak) dan web push dengan
 * mengimplementasikan antarmuka ini dan mendaftarkannya di DeliveryChannels.
 */
interface DeliveryChannel
{
    /** Nama kanal, salah satu NotificationDelivery::CHANNELS. */
    public function name(): string;

    /**
     * @return string|null pengenal pesan dari penyedia, bila ada
     *
     * @throws \Throwable bila penyedia menolak atau tidak terjangkau
     */
    public function send(NotificationDelivery $delivery, Notification $notification): ?string;
}
