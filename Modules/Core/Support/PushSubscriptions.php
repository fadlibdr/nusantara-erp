<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Services\AuditService;

/**
 * Daftar perangkat seseorang, dan satu-satunya tempat yang menulisnya
 * (P-3e, T3e.2/T3e.3).
 *
 * Dua penulis, dua alasan:
 *
 *  - register() dipanggil layar Profil ketika orangnya menekan "Aktifkan
 *    notifikasi di perangkat ini", dan oleh rotasi `pushsubscriptionchange`
 *    ketika peramban MEMUTAR endpoint-nya sendiri. Keduanya updateOrCreate
 *    atas endpoint_hash — satu langganan, satu baris, selamanya.
 *  - forgetExpired() dipanggil kanal ketika layanan push menjawab 404/410.
 *
 * KEJADIAN 404/410 TERCATAT DI TEMPAT YANG BERTAHAN SESUDAH BARISNYA HILANG.
 * Menuliskannya "di baris langganan" tidak berarti apa-apa: baris itulah yang
 * dihapus. Jadi ia ditulis ke core_audit_log — append-only, punya layarnya
 * sendiri (Sistem › Log Audit), dan ikut ke setiap cadangan — dengan label
 * perangkat dan sebabnya. Baris kotak keluar yang memicunya juga menyimpan
 * kalimat yang sama di kolom `error`, tetapi baris itu boleh dipangkas
 * operator suatu hari; log audit tidak punya jalur hapus di aplikasi ini.
 */
final class PushSubscriptions
{
    /** @return Collection<int, PushSubscription> */
    public static function forUser(User $user): Collection
    {
        return PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->orderBy('id')
            ->get();
    }

    public static function countFor(User $user): int
    {
        return PushSubscription::query()->where('user_id', $user->getKey())->count();
    }

    /**
     * Daftarkan (atau perbarui) satu perangkat.
     *
     * $previousEndpoint diisi HANYA oleh rotasi pushsubscriptionchange: baris
     * lama dibuang lebih dulu supaya perangkat yang sama tidak meninggalkan
     * endpoint mati yang akan dikirimi sampai layanan push menjawab 410.
     */
    public static function register(User $user, string $endpoint, string $p256dh, string $auth, ?string $userAgent = null, ?string $previousEndpoint = null): PushSubscription
    {
        if ($previousEndpoint !== null && trim($previousEndpoint) !== '' && trim($previousEndpoint) !== trim($endpoint)) {
            PushSubscription::query()
                ->where('user_id', $user->getKey())
                ->where('endpoint_hash', PushSubscription::hashFor($previousEndpoint))
                ->delete();
        }

        return PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($endpoint)],
            [
                'user_id' => $user->getKey(),
                'endpoint' => trim($endpoint),
                'p256dh' => $p256dh,
                'auth' => $auth,
                'device_label' => PushDeviceLabel::fromUserAgent($userAgent),
            ],
        );
    }

    /**
     * Layanan push menyatakan langganan ini mati (404/410). Barisnya dibuang,
     * dan kejadiannya dicatat di tempat yang bertahan sesudahnya.
     */
    public static function forgetExpired(PushSubscription $subscription, string $reason): void
    {
        $label = $subscription->label();
        $userId = (int) $subscription->user_id;

        app(AuditService::class)->event(
            $subscription,
            'deleted',
            [
                'device_label' => ['from' => $label, 'to' => null],
                'user_id' => ['from' => $userId, 'to' => null],
                'alasan' => ['from' => null, 'to' => $reason],
            ],
            $label,
        );

        $subscription->delete();
    }
}
