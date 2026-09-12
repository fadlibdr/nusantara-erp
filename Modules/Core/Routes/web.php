<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ExternalApprovalPageController;
use Modules\Core\Http\Controllers\WhatsAppWebhookController;

/*
 * Rute WEB Core — satu-satunya berkas rute web modul, dimuat oleh
 * CoreServiceProvider (routes/* milik akar dilarang disentuh, CONVENTIONS §1).
 *
 * Halaman keputusan MK/Owner: publik dengan sengaja — pihak eksternal tidak
 * punya login, tokennya-lah kapabilitasnya. throttle:10,1 mengikuti preseden
 * rute login (Modules/Iam/Routes/api.php): sepuluh percobaan per menit per IP
 * membuat menebak token 40 karakter bukan serangan yang selesai sebelum
 * matahari padam. TANPA grup 'web': tidak ada sesi/cookie/CSRF yang perlu
 * dilindungi di halaman tanpa identitas ini.
 *
 * CATATAN OPS: di erp1 produksi, gerbang Basic Auth nginx masih memblokir
 * akses anonim — tautan ini baru benar-benar terbuka untuk MK/Owner setelah
 * gerbang itu diturunkan (rotasi sandi, item pemilik yang masih terbuka).
 * Lihat docs/PERSETUJUAN-EKSTERNAL.md.
 */

Route::get('persetujuan/{token}', [ExternalApprovalPageController::class, 'show'])
    ->where('token', '[A-Za-z0-9-]{20,64}')
    ->middleware('throttle:10,1');

Route::post('persetujuan/{token}', [ExternalApprovalPageController::class, 'decide'])
    ->where('token', '[A-Za-z0-9-]{20,64}')
    ->middleware('throttle:10,1');

/*
 * Webhook status WhatsApp (P-3a, T3a.3) — permukaan publik kedua, dan
 * satu-satunya yang menerima POST tanpa token di URL. Kapabilitasnya adalah
 * TANDA TANGAN: X-Hub-Signature-256 = HMAC-SHA256(badan mentah, app secret),
 * diverifikasi hash_equals di controller; tanpa WHATSAPP_APP_SECRET semua
 * permintaan 403. Tanpa grup 'web' — tidak ada sesi/CSRF yang perlu dijaga,
 * dan Meta memang tidak membawa cookie. GET-nya dibatasi seperti login; POST
 * dibatasi longgar (Meta mengirim satu status per pesan per tahap) — tanda
 * tanganlah yang menjaganya, bukan laju.
 */
Route::get('whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:30,1');

Route::post('whatsapp/webhook', [WhatsAppWebhookController::class, 'statuses'])
    ->middleware('throttle:600,1');
