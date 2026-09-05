<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Jobs\DeliverNotification;

/**
 * Baris tabel failed_jobs Laravel (Fase 0 / P-0b, T0b.4), dibaca layar
 * Sistem › Antrean Gagal. Model tipis tanpa timestamps: tabelnya milik
 * kerangka kerja (failed_at diisi useCurrent), dan yang menulisnya hanya
 * pekerja antrean lewat FailedJobProviderInterface — tidak pernah kode ini.
 */
class FailedJob extends BaseModel
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    /** Nama job dari payload (displayName), atau `?` bila payload tidak terbaca. */
    public function displayName(): string
    {
        $payload = json_decode((string) $this->payload, true);

        return is_array($payload) && is_string($payload['displayName'] ?? null) && $payload['displayName'] !== ''
            ? $payload['displayName']
            : '?';
    }

    /**
     * Id baris core_notification_deliveries bila job ini DeliverNotification,
     * null untuk job lain. Dibaca dari payload yang dibekukan pekerja:
     * data.command adalah objek job yang diserialkan PHP, dan satu-satunya
     * propertinya adalah deliveryId. Dicocokkan dengan regex, bukan
     * unserialize(): membaca sebuah angka tidak perlu menghidupkan objeknya.
     */
    public function deliveryId(): ?int
    {
        if ($this->displayName() !== DeliverNotification::class) {
            return null;
        }

        $payload = json_decode((string) $this->payload, true);
        $command = is_array($payload) ? ($payload['data']['command'] ?? null) : null;

        return is_string($command) && preg_match('/s:10:"deliveryId";i:(\d+);/', $command, $m) === 1
            ? (int) $m[1]
            : null;
    }

    /**
     * Catatan gagal milik satu baris pengiriman. LIKE hanya menyempitkan
     * (nama kelas tanpa garis miring terbalik — LIKE MySQL memperlakukan
     * backslash sebagai escape); yang memutuskan adalah deliveryId() per baris.
     *
     * @return Collection<int, static>
     */
    public static function forDelivery(int $deliveryId): Collection
    {
        return static::query()
            ->where('payload', 'like', '%DeliverNotification%')
            ->get()
            ->filter(fn (self $job): bool => $job->deliveryId() === $deliveryId)
            ->values();
    }

    /** Baris pertama pengecualian: "RuntimeException: SMTP 550 … in …:12". */
    public function exceptionExcerpt(): string
    {
        $first = strtok((string) $this->exception, "\n");

        return is_string($first) && $first !== '' ? mb_substr($first, 0, 300) : '?';
    }
}
