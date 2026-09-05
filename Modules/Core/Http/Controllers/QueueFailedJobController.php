<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Http\ApiController;
use Modules\Core\Http\Resources\FailedJobResource;
use Modules\Core\Models\FailedJob;

/**
 * Sistem › Antrean Gagal (Fase 0 / P-0b, T0b.4): tabel failed_jobs Laravel
 * dari layar, untuk orang yang tidak punya shell di server. Bergerbang
 * core.update (baca, kirim ulang) dan core.delete (hapus) — pemegang
 * Pengaturan; admin memegang keduanya.
 *
 * Kirim ulang memakai `queue:retry` milik kerangka kerja, bukan tiruannya:
 * jalur yang sama dengan CLI (attempts direset, retryUntil disegarkan, baris
 * gagal dihapus setelah job kembali ke antrean). Hapus lewat
 * FailedJobProviderInterface::forget — cara kerangka kerja yang sama.
 *
 * KECUALI DeliverNotification (T0b.3): kebenaran pengiriman itu adalah baris
 * core_notification_deliveries, yang sudah `failed` saat job-nya mendarat di
 * sini, dan handle() melewati baris yang bukan `queued`. queue:retry atas job
 * itu "berhasil" tanpa mengirim apa pun, menghapus catatan gagalnya, dan
 * meninggalkan barisnya tetap `failed` (verifikasi P-0b, 5 Sep 2026). Maka
 * ditolak 422 dengan penunjuk ke Sistem › Pengiriman Notifikasi; Kirim ulang
 * di sana menghapus catatan gagal ini sendiri (NotificationService::retry).
 */
class QueueFailedJobController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'queue' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $query = FailedJob::query()
            ->when(filled($data['queue'] ?? null), fn ($q) => $q->where('queue', $data['queue']))
            ->when(filled($data['q'] ?? null), function ($q) use ($data): void {
                $needle = '%'.$data['q'].'%';
                $q->where(fn ($inner) => $inner
                    ->where('uuid', 'like', $needle)
                    ->orWhere('exception', 'like', $needle)
                    ->orWhere('payload', 'like', $needle));
            })
            ->orderByDesc('id');

        return $this->listing($request, $query, FailedJobResource::class,
            sortable: ['failed_at', 'queue'],
            dateColumn: 'failed_at',
            perPageDefault: 25,
        );
    }

    public function show(FailedJob $failedJob): JsonResponse
    {
        return $this->ok(FailedJobResource::withTrace($failedJob));
    }

    public function retry(FailedJob $failedJob): JsonResponse
    {
        $uuid = (string) $failedJob->uuid;
        $deliveryId = $failedJob->deliveryId();

        if ($deliveryId !== null) {
            return $this->error(
                "Job ini adalah pengiriman notifikasi #{$deliveryId}. Mengembalikannya ke antrean tidak mengirim apa pun: "
                .'baris pengirimannya sudah berstatus gagal dan pekerja melewatinya. Kirim ulang dari Sistem › '
                .'Pengiriman Notifikasi — catatan gagal ini ikut dihapus di sana.',
                422,
            );
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        // queue:retry mencetak "No failed job matches the given ID" alih-alih
        // gagal; yang membuktikan job kembali ke antrean adalah hilangnya
        // baris gagal, jadi itulah yang diperiksa.
        if (app(FailedJobProviderInterface::class)->find($uuid) !== null) {
            return $this->error(
                'Job gagal '.$uuid.' tidak dapat dikembalikan ke antrean: '.trim(Artisan::output()),
                503,
            );
        }

        return $this->ok(
            ['uuid' => $uuid, 'job' => $failedJob->displayName(), 'queue' => $failedJob->queue],
            'Job dikembalikan ke antrean; pekerja akan mencobanya lagi.',
        );
    }

    public function destroy(FailedJob $failedJob): JsonResponse
    {
        app(FailedJobProviderInterface::class)->forget((string) $failedJob->uuid);

        return $this->ok(null, 'Catatan job gagal dihapus.');
    }
}
