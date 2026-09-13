<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Exceptions\PushDeviceLimitException;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Support\DeliveryGate;
use Modules\Core\Support\PushEndpoint;
use Modules\Core\Support\PushSubscriptions;
use Modules\Core\Support\WebPushSetup;

/**
 * Perangkat web push MILIK PEMANGGIL SENDIRI (P-3e, T3e.4).
 *
 * Pola me/preferences: tanpa gerbang izin, dan tidak ada izin yang bisa
 * menolongnya — tidak ada satu pun parameter di ketiga endpoint ini yang
 * menyebut orang lain. index() membaca hanya baris $request->user(),
 * destroy() mencari id DI DALAM baris miliknya (id orang lain 404, bukan 403:
 * dua kalimat berbeda adalah cara menghitung perangkat milik orang lain), dan
 * store() selalu menulis atas nama pemanggil.
 *
 * `endpoint` dipulangkan UTUH di index() — itu data milik pemanggil sendiri,
 * dan layar memerlukannya untuk menandai "perangkat ini": peramban tahu
 * endpoint langganannya sendiri dan membandingkannya apa adanya. Ia TIDAK
 * pernah dipulangkan untuk orang lain, karena orang lain tidak punya endpoint
 * di jawaban mana pun di sini.
 *
 * `public_key` ikut di index() dengan sengaja: tombol Aktifkan membutuhkannya
 * sebagai applicationServerKey, dan sebuah kunci publik yang diketik ulang di
 * klien adalah kunci yang suatu hari tidak cocok dengan .env server.
 */
class PushSubscriptionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $devices = PushSubscriptions::forUser($user)->map(static fn (PushSubscription $device): array => [
            'id' => $device->getKey(),
            'label' => $device->label(),
            'endpoint' => (string) $device->endpoint,
            'created_at' => $device->created_at?->toIso8601String(),
            'last_success_at' => $device->last_success_at?->toIso8601String(),
        ])->values();

        return $this->ok([
            'devices' => $devices,
            // Kunci PUBLIK — memang untuk dikirim ke peramban. Kunci privat
            // tidak punya jalan keluar dari WebPushSetup.
            'public_key' => WebPushSetup::publicKey(),
            // Kalimat yang SAMA dengan yang akan ditulis kotak keluar: layar
            // tidak boleh menyusun kalimat kedua yang mirip (P-3a §38).
            'reason' => DeliveryGate::reasonToSkip(NotificationDelivery::CHANNEL_WEBPUSH, $user),
            // Bagian sebab yang TIDAK BISA diatasi tindakan apa pun di
            // peramban (sakelar Pengaturan, VAPID di .env). Layar memakainya
            // untuk tidak menawarkan tombol yang pasti gagal — jalan buntu
            // keempat T3e.4 — dan kalimatnya sama persis dengan yang ditulis
            // kotak keluar, bukan kalimat kedua yang mirip.
            'server_reason' => DeliveryGate::webPushServerReason(),
            // Kanal ini DIMATIKAN ORANGNYA sendiri di kartu di atas (putaran
            // verifikasi: C-5). Ia tidak bisa dibaca dari `reason` saja:
            // `reason` juga berbunyi WEBPUSH_NO_DEVICE, dan itu justru keadaan
            // yang tombolnya ada untuk mengubah. Kalimatnya tetap datang dari
            // `reason` — yang dikirim di sini hanya PENANDA keadaannya, bukan
            // kalimat kedua yang mirip.
            'user_off' => ! DeliveryGate::userEnabled(NotificationDelivery::CHANNEL_WEBPUSH, $user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            // Endpoint milik layanan push, panjangnya tidak dijanjikan
            // spesifikasi (yang NYATA diukur 188 karakter). https wajib: Web
            // Push tidak punya bentuk lain, dan http di sini berarti seseorang
            // mengarang muatannya.
            'endpoint' => ['required', 'string', 'max:1000', 'url', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            // Endpoint yang DIGANTIKAN langganan ini, dikirim klien pada jalur
            // InvalidStateError (kunci VAPID baru saja diganti): peramban yang
            // sama membuang langganan lamanya dan berlangganan ulang, yang
            // memberi endpoint BARU. Tanpa nilai ini baris lama bertahan
            // selamanya sebagai perangkat hantu — satu baris merah "Gagal" per
            // pemberitahuan, untuk perangkat yang sebenarnya sehat
            // (putaran verifikasi: B-5/C-4).
            'previous_endpoint' => ['sometimes', 'nullable', 'string', 'max:1000', 'url', 'starts_with:https://'],
        ], [
            'endpoint.starts_with' => 'Endpoint langganan push harus https://.',
            'previous_endpoint.starts_with' => 'Endpoint langganan push harus https://.',
        ]);

        // Alamat DI DALAM jaringan server bukan perangkat siapa pun (P-3d §11,
        // dipakai ulang lewat PushEndpoint — putaran verifikasi: A-1/B-2).
        try {
            PushEndpoint::assertShape($data['endpoint']);
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['endpoint' => $e->getMessage()]);
        }

        try {
            $device = PushSubscriptions::register(
                $user,
                $data['endpoint'],
                $data['keys']['p256dh'],
                $data['keys']['auth'],
                $request->userAgent(),
                $data['previous_endpoint'] ?? null,
            );
        } catch (PushDeviceLimitException $e) {
            // Plafon perangkat (A-5): kalimatnya menyebut angkanya DAN jalan
            // keluarnya, karena "gagal" tanpa jalan keluar adalah tombol mati.
            throw ValidationException::withMessages(['endpoint' => $e->getMessage()]);
        }

        return $this->ok([
            'id' => $device->getKey(),
            'label' => $device->label(),
            'endpoint' => (string) $device->endpoint,
            'created_at' => $device->created_at?->toIso8601String(),
            'last_success_at' => $device->last_success_at?->toIso8601String(),
        ], 'Perangkat ini akan menerima pemberitahuan.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $device = PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->find($id);

        if ($device === null) {
            // Milik orang lain dan tidak ada sama sekali dijawab SAMA:
            // dua kalimat berbeda adalah cara menghitung perangkat orang lain.
            return $this->error('Perangkat tidak ditemukan pada akun Anda.', 404);
        }

        $device->delete();

        return $this->ok(null, 'Perangkat dicabut; ia tidak akan menerima pemberitahuan lagi.');
    }
}
