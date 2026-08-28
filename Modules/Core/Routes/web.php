<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ExternalApprovalPageController;

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
