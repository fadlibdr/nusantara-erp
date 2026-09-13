<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Support\PushEndpoint;

/**
 * `pushsubscriptionchange`: peramban MEMUTAR endpoint-nya sendiri, dan server
 * harus MENGGANTI barisnya — bukan menumpuk (P-3e, T3e.5).
 *
 * KENAPA RUTE INI PUBLIK, DAN KENAPA IA BUKAN DI BAWAH /api.
 *
 * Yang memanggilnya adalah service worker, bukan halaman. Dua hal yang tidak
 * bisa ditawar:
 *
 *  1. Worker TIDAK PUNYA kredensial. Sesi SPA ini adalah token di
 *     localStorage (dikirim sebagai X-Api-Token), dan localStorage TIDAK bisa
 *     dibaca dari service worker — tidak ada API-nya. Jadi tidak ada bentuk
 *     "panggil API sebagai penggunanya" yang tersedia di sana sama sekali.
 *  2. `pushsubscriptionchange` menyala ketika TIDAK ADA tab yang terbuka; itu
 *     seluruh gunanya. Jadi "titipkan ke halaman" bukan jawaban.
 *
 * Dan satu hal lagi yang bukan kebetulan: `tests/Feature/Core/
 * PwaServiceWorkerTest` memaku bahwa kode sw.js tidak menyebut `/api` sama
 * sekali (aturan daftar-izin cache, CONVENTIONS §21). Rute ini duduk di
 * Routes/web.php bersama halaman persetujuan eksternal dan webhook WhatsApp —
 * permukaan publik yang kapabilitasnya bukan sesi.
 *
 * KAPABILITASNYA ADALAH ENDPOINT LAMA. Endpoint push adalah rahasia yang
 * hanya diketahui peramban itu dan server ini; ia sudah menjadi kapabilitas
 * dalam standarnya sendiri (siapa pun yang memegangnya bisa mem-POST ke
 * langganan itu). Lima batas menjaganya — (a) sampai (c) sejak T3e.5,
 * (d) dan (e) sejak putaran verifikasi:
 *
 *  a. TIDAK PERNAH MEMBUAT. Endpoint lama yang tidak cocok satu baris pun
 *     dijawab 204 dan tidak menulis apa pun — rute ini tidak bisa dipakai
 *     mendaftarkan perangkat, hanya memindahkan yang sudah ada.
 *  b. ASAL HARUS SAMA. Layanan push memutar endpoint DI DALAM layanannya
 *     sendiri; endpoint baru yang host-nya berbeda ditolak, jadi sebuah baris
 *     tidak bisa dialihkan ke layanan push milik penyerang.
 *  c. TIDAK PERNAH MENYENTUH BARIS MILIK ORANG LAIN (putaran verifikasi:
 *     A-4). Rotasi memindahkan langganan DI DALAM satu akun. Cabang
 *     "endpoint barunya sudah terdaftar" di bawah membuang baris lama, dan
 *     tanpa batas ini ia membuang baris SIAPA PUN yang endpoint-nya dipegang
 *     pemanggil — dari rute yang tidak meminta sesi sama sekali. Diukur
 *     13 Sep 2026 di pohon ini: satu POST tanpa sesi menghapus langganan
 *     korban dan meninggalkan langganan penyerang.
 *  d. BUKAN ALAMAT INTERNAL, dua-duanya. Endpoint di sini disimpan dan
 *     nantinya di-POST oleh pekerja antrean persis seperti endpoint
 *     pendaftaran, jadi ia melewati penjaga yang sama (PushEndpoint, dari
 *     kebijakan P-3d §11) — putaran verifikasi: A-1/B-2.
 *  e. Laju dibatasi seperti halaman persetujuan.
 *
 * Batas yang TERSISA dikatakan apa adanya di LAPORAN §6 dan
 * KEPUTUSAN-INTEGRASI: seseorang yang berhasil membaca endpoint milik orang
 * lain bisa memindahkan langganan itu ke perangkatnya sendiri DI DALAM akun
 * pemiliknya, di dalam layanan push yang sama — pemiliknya berhenti menerima
 * pemberitahuan di perangkat itu. Endpoint bisa dibaca dari basis data, dari
 * peramban orang itu, ATAU dari kolom "Galat / alasan" di Sistem › Pengiriman
 * Notifikasi — sumber ketiga itu ditutup di putaran verifikasi ini (A-6/B-4:
 * endpoint disamarkan sebelum masuk kolom error), dan dua yang pertama sudah
 * berarti memegang lebih banyak daripada satu endpoint.
 */
class PushRotationController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'old_endpoint' => ['required', 'string', 'max:1000', 'url', 'starts_with:https://'],
            'endpoint' => ['required', 'string', 'max:1000', 'url', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // (d) Alamat internal ditolak di pintu ini juga.
        foreach (['old_endpoint', 'endpoint'] as $field) {
            try {
                PushEndpoint::assertShape($data[$field]);
            } catch (LogicException $e) {
                throw ValidationException::withMessages([$field => $e->getMessage()]);
            }
        }

        $old = PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hashFor($data['old_endpoint']))
            ->first();

        // (a) Tidak pernah membuat. Endpoint lama yang tidak dikenal bukan
        // galat — worker sebuah peramban yang langganannya sudah dicabut
        // orangnya akan sampai di sini — dan bukan alasan menulis baris baru.
        if ($old === null) {
            return $this->ok(null, 'Tidak ada langganan dengan endpoint lama itu; tidak ada yang diganti.');
        }

        // (b) Asal harus sama.
        if ($this->originOf($data['old_endpoint']) !== $this->originOf($data['endpoint'])) {
            return $this->error(
                'Endpoint baru berada di layanan push yang berbeda dengan endpoint lama; rotasi hanya memindahkan '
                .'langganan di dalam layanan push yang sama.',
                422,
            );
        }

        $newHash = PushSubscription::hashFor($data['endpoint']);
        $existing = PushSubscription::query()->where('endpoint_hash', $newHash)->first();

        // Endpoint barunya sudah terdaftar (orangnya sempat menekan Aktifkan
        // lagi sebelum worker sampai ke sini): yang lama dibuang, yang baru
        // yang bertahan — satu perangkat, satu baris.
        //
        // (c) TAPI HANYA BILA KEDUANYA ORANG YANG SAMA. Kasus yang dibayangkan
        // cabang ini adalah SATU orang dengan dua baris; dua orang berarti
        // pemanggil memasangkan endpoint korban dengan endpoint miliknya
        // sendiri, dan menuruti itu berarti menghapus langganan korban dari
        // rute tanpa sesi.
        if ($existing !== null && $existing->getKey() !== $old->getKey()) {
            if ((int) $existing->user_id !== (int) $old->user_id) {
                return $this->error(
                    'Endpoint lama dan endpoint baru terdaftar pada dua akun yang berbeda; rotasi hanya memindahkan '
                    .'langganan di dalam satu akun.',
                    422,
                );
            }

            $old->delete();
            $existing->forceFill(['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']])->save();

            return $this->ok(null, 'Langganan perangkat ini diperbarui.');
        }

        $old->forceFill([
            'endpoint' => trim($data['endpoint']),
            'endpoint_hash' => $newHash,
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
        ])->save();

        return $this->ok(null, 'Langganan perangkat ini dipindahkan ke endpoint barunya.');
    }

    /** "https://fcm.googleapis.com" — skema + host + porta, tanpa jalur. */
    private function originOf(string $url): string
    {
        $parts = parse_url(trim($url));

        return strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : ''));
    }
}
