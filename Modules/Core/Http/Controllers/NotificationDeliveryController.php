<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Exceptions\DeliveryRetryRefusedException;
use Modules\Core\Http\ApiController;
use Modules\Core\Http\Resources\NotificationDeliveryResource;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Services\NotificationService;
use Throwable;

/**
 * Sistem › Pengiriman Notifikasi (Fase 0 / P-0b, T0b.3). Bergerbang
 * core.update — orang yang membuka Pengaturan, karena di sanalah sakelar
 * e-mail yang menentukan `skipped` atau bukan. Baca-saja kecuali Kirim ulang:
 * baris ditulis NotificationService dan job DeliverNotification, dan riwayat
 * pengiriman yang bisa disunting bukan riwayat.
 */
class NotificationDeliveryController extends ApiController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(NotificationDelivery::STATUSES)],
            'channel' => ['nullable', Rule::in(NotificationDelivery::CHANNELS)],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $query = NotificationDelivery::query()
            ->with(['notification:id,title,event,user_id', 'notification.user:id,name'])
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(isset($data['channel']), fn ($q) => $q->where('channel', $data['channel']))
            ->when(filled($data['q'] ?? null), function ($q) use ($data): void {
                $needle = '%'.$data['q'].'%';
                $q->where(fn ($inner) => $inner
                    ->where('recipient', 'like', $needle)
                    ->orWhere('error', 'like', $needle)
                    ->orWhereHas('notification', fn ($n) => $n->where('title', 'like', $needle)));
            })
            ->orderByDesc('id');

        return $this->listing($request, $query, NotificationDeliveryResource::class,
            sortable: ['created_at', 'status', 'attempts', 'sent_at'],
            dateColumn: 'created_at',
            perPageDefault: 25,
        );
    }

    public function show(NotificationDelivery $notificationDelivery): JsonResponse
    {
        $notificationDelivery->load(['notification:id,title,event,user_id', 'notification.user:id,name']);

        return $this->ok(new NotificationDeliveryResource($notificationDelivery));
    }

    public function retry(NotificationDelivery $notificationDelivery): JsonResponse
    {
        try {
            $delivery = $this->notifications->retry($notificationDelivery);
        } catch (DeliveryRetryRefusedException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            // Antrean menolak dispatch — termasuk InvalidArgumentException-nya
            // sendiri untuk koneksi yang tidak terkonfigurasi, yang bukan 422.
            // Barisnya sudah `queued` (jujur: menunggu), dan orangnya harus
            // tahu kenapa tombolnya tidak menghasilkan apa-apa.
            return $this->error('Antrean tidak dapat menerima job: '.$e->getMessage().' Baris tetap berstatus antre.', 503);
        }

        $delivery->load(['notification:id,title,event,user_id', 'notification.user:id,name']);

        return $this->ok(new NotificationDeliveryResource($delivery), 'Pengiriman diantrekan ulang.');
    }
}
