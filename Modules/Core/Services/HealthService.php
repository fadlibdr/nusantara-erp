<?php

namespace Modules\Core\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Setting;

/**
 * Apa yang GET api/core/health laporkan (Fase 0 / P-0b, T0b.2).
 *
 * Satu aturan: angka yang tidak bisa dihitung dijawab null — SPA menampilkannya
 * sebagai `?` — dan tidak pernah 0 atau "ok". Tabel yang belum ada (instalasi
 * yang migrasinya belum jalan), basis data yang tidak terbaca, detak jantung
 * yang belum pernah ditulis: semuanya "tidak diketahui", bukan "sehat".
 *
 * Detak jantung dibaca LANGSUNG dari core_settings, bukan lewat memo/cache
 * SettingService: pemeriksaan kesehatan harus melihat yang tersimpan, bukan
 * yang kebetulan dipegang cache (aturan yang sama dengan invalidOverrides()).
 */
class HealthService
{
    public const HEARTBEAT_KEY = 'scheduler.heartbeat_at';

    public const STATUS_OK = 'ok';

    public const STATUS_STALE = 'stale';

    public const STATUS_UNKNOWN = 'unknown';

    public function maxHeartbeatAgeSeconds(): int
    {
        return max(60, (int) config('erp.scheduler.heartbeat_max_age_s', 1200));
    }

    /** Detak terakhir yang tersimpan, atau null bila belum pernah / tidak terbaca. */
    public function heartbeatAt(): ?CarbonImmutable
    {
        if (! Schema::hasTable('core_settings')) {
            return null;
        }

        $value = Setting::query()->where('key', self::HEARTBEAT_KEY)->first()?->value;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function heartbeatAgeSeconds(?CarbonImmutable $at = null): ?int
    {
        $at ??= $this->heartbeatAt();

        // Detak dari "masa depan" (jam server mundur) dilaporkan 0, bukan negatif.
        return $at === null ? null : max(0, (int) $at->diffInSeconds(now(), false));
    }

    /**
     * ok | stale | unknown. `unknown` adalah jawaban jujur untuk "belum pernah
     * ada detak": penjadwal MUNGKIN tidak pernah berjalan sejak dipasang.
     */
    public function schedulerStatus(?int $age = null): string
    {
        $age ??= $this->heartbeatAgeSeconds();

        if ($age === null) {
            return self::STATUS_UNKNOWN;
        }

        return $age > $this->maxHeartbeatAgeSeconds() ? self::STATUS_STALE : self::STATUS_OK;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $at = $this->heartbeatAt();
        $age = $this->heartbeatAgeSeconds($at);
        $queue = $this->queueMetrics();

        return [
            'scheduler_status' => $this->schedulerStatus($age),
            'scheduler_heartbeat_at' => $at?->toIso8601String(),
            'scheduler_heartbeat_age_s' => $age,
            'scheduler_heartbeat_max_age_s' => $this->maxHeartbeatAgeSeconds(),
            'queue_pending_count' => $queue['pending'],
            'queue_oldest_pending_age_s' => $queue['oldest_age'],
            'failed_jobs_count' => $this->failedJobsCount(),
            'queued_deliveries_older_than_1h' => $this->queuedDeliveriesOlderThanAnHour(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Job yang SUDAH BOLEH diambil (available_at lewat, belum di-reserve) dan
     * belum diambil siapa pun: jumlahnya dan berapa lama yang tertua menunggu.
     * Job yang menunggu backoff sengaja tidak dihitung — ia menunggu waktunya,
     * bukan menunggu pekerja. Antrean kosong = 0/0; tabel tidak ada = null/null.
     *
     * @return array{pending: int|null, oldest_age: int|null}
     */
    private function queueMetrics(): array
    {
        if (! Schema::hasTable('jobs')) {
            return ['pending' => null, 'oldest_age' => null];
        }

        $nowUnix = now()->getTimestamp();

        $row = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $nowUnix)
            ->selectRaw('COUNT(*) AS pending, MIN(available_at) AS oldest')
            ->first();

        $pending = (int) ($row->pending ?? 0);

        return [
            'pending' => $pending,
            'oldest_age' => $pending === 0 ? 0 : max(0, $nowUnix - (int) $row->oldest),
        ];
    }

    private function failedJobsCount(): ?int
    {
        return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;
    }

    /**
     * Pengiriman (T0b.3) berstatus `queued` yang sudah satu jam lebih TIDAK
     * disentuh pekerja: pekerja antrean mati, atau dispatch-nya gagal dan
     * barisnya menunggu Kirim ulang.
     *
     * Umurnya diukur dari saat baris ini terakhir seharusnya bergerak, bukan
     * dari created_at: baris yang menunggu backoff (next_attempt_at di masa
     * depan — total backoff 60+300+900+3600 s sudah lebih dari sejam) sedang
     * melakukan persis yang seharusnya, dan baris gagal yang baru ditekan
     * Kirim ulang baru mulai menunggu sejak ditekan (updated_at), bukan sejak
     * dibuat kemarin (verifikasi P-0b, 5 Sep 2026: keduanya dihitung macet).
     * Jadi: next_attempt_at bila ada, kalau tidak updated_at (setiap percobaan
     * dan setiap Kirim ulang menyentuhnya), harus lewat lebih dari sejam.
     */
    private function queuedDeliveriesOlderThanAnHour(): ?int
    {
        if (! Schema::hasTable('core_notification_deliveries')) {
            return null;
        }

        $cutoff = now()->subHour();

        return (int) DB::table('core_notification_deliveries')
            ->where('status', 'queued')
            ->where(fn ($due) => $due
                ->where(fn ($unscheduled) => $unscheduled->whereNull('next_attempt_at')->where('updated_at', '<', $cutoff))
                ->orWhere('next_attempt_at', '<', $cutoff))
            ->count();
    }
}
