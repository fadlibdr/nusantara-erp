<?php

namespace Modules\Iam\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Iam\Models\ApiToken;

/**
 * SEBUAH TOKEN TIDAK BOLEH MENCETAK TOKEN, DAN TIDAK BOLEH MENGGANTI KATA
 * SANDI PEMILIKNYA (P-3d).
 *
 * Rute `me/*` adalah layanan mandiri: ia menyentuh rekam pemanggil sendiri dan
 * karena itu tidak dijaga izin apa pun. Konsekuensinya, ability sebuah token
 * tidak bisa mempersempitnya — tidak ada izin di sana untuk dipersempit. Untuk
 * sebagian besar rute itu (preferensi, onboarding, menandai notifikasi terbaca)
 * itu tepat: sebuah integrasi yang mengubah preferensinya sendiri tidak
 * membahayakan siapa pun.
 *
 * Tiga di antaranya lain, dan ketiganya adalah jalan memutar yang membatalkan
 * seluruh gagasan token terbatas:
 *
 *   POST me/api-tokens   sebuah token "hanya baca keuangan" mencetak token
 *                        KEDUA dengan SELURUH izin pemiliknya, lalu memakainya.
 *                        Pembatasan apa pun yang dipasang paket ini akan
 *                        berumur satu permintaan.
 *   PUT  me/password     kunci akun berpindah tangan; pemiliknya tidak bisa
 *                        masuk lagi, dan yang memegang token bisa.
 *   PUT  me/phone        nomor WhatsApp DAN persetujuan bertanggalnya — alarm
 *                        operasional perusahaan diarahkan ke nomor lain.
 *
 * Maka ketiganya menuntut SESI: masuk lewat halaman masuk dengan kata sandi.
 * Token sesi SPA lolos karena itulah yang dipakai halaman itu; token pribadi
 * ditolak 403 dengan kalimat yang menyebut apa yang harus dilakukan.
 *
 * Dipasang dengan NAMA KELAS pada rutenya, bukan lewat alias di
 * `bootstrap/app.php` — aturan rumah paket ini melarang menyentuh berkas itu,
 * dan Laravel menerima nama kelas apa adanya sebagai middleware rute.
 */
class SessionOnly
{
    public const REFUSAL = 'Tindakan ini tidak bisa dilakukan dengan token API. '
        .'Membuat atau mencabut token, mengganti kata sandi, dan mengubah nomor WhatsApp hanya bisa dilakukan '
        .'setelah masuk lewat halaman masuk — kalau tidak, sebuah token terbatas bisa mencetak token yang tidak terbatas.';

    public function handle(Request $request, Closure $next): mixed
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof ApiToken && $token->isPersonal()) {
            return new JsonResponse(['message' => self::REFUSAL], 403);
        }

        return $next($request);
    }
}
