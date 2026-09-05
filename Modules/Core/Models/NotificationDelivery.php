<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu upaya menyampaikan sebuah core_notifications lewat kanal luar.
 * Lihat komentar migrasi 000194 untuk makna tiap status.
 */
class NotificationDelivery extends BaseModel
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_WEBPUSH = 'webpush';

    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP, self::CHANNEL_WEBPUSH];

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const STATUSES = [self::QUEUED, self::SENT, self::FAILED, self::SKIPPED];

    /** Yang boleh Kirim ulang: semua kecuali yang sudah diterima penyedia. */
    public const RETRYABLE = [self::QUEUED, self::FAILED, self::SKIPPED];

    protected $table = 'core_notification_deliveries';

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
