<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris Sistem › Pengiriman Notifikasi. Judul dan penerima (nama) diratakan
 * dari notifikasinya supaya daftar terbaca tanpa lookup; error adalah pesan
 * penyedia apa adanya (≤ 500) — itulah yang dibutuhkan operator.
 */
class NotificationDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'notification_id' => $this->notification_id,
            'title' => $this->notification?->title,
            'event' => $this->notification?->event,
            'user_name' => $this->notification?->user?->name,
            'channel' => $this->channel,
            'recipient' => $this->recipient,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,
            'provider_id' => $this->provider_id,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
