<?php

use Illuminate\Support\Facades\Route;
use Modules\ServiceDesk\Http\Controllers\CsatPageController;

/*
 * Rute WEB ServiceDesk — satu-satunya berkas rute web modul ini, dimuat oleh
 * ServiceDeskServiceProvider (routes/* milik akar dilarang disentuh,
 * CONVENTIONS §1).
 *
 * Halaman penilaian pelanggan (CSAT, F-9): publik dengan sengaja — pelanggan
 * TIDAK punya akun di sistem ini dan roadmap menolak portal pelanggan secara
 * tertulis, jadi tokennya-lah kapabilitasnya. Ketiga keputusan di bawah
 * disalin PERSIS dari Modules/Core/Routes/web.php, dan alasannya disalin ikut
 * karena alasan itulah yang membuatnya benar:
 *
 *  - throttle:10,1 mengikuti preseden rute login (Modules/Iam/Routes/api.php):
 *    sepuluh percobaan per menit per IP membuat menebak token 40 karakter bukan
 *    serangan yang selesai sebelum matahari padam;
 *  - regex token yang sama ketatnya, jadi apa pun yang tidak berbentuk token
 *    tidak pernah mencapai controller (404 router, bukan kueri);
 *  - TANPA grup 'web': tidak ada sesi, cookie, atau CSRF yang perlu dilindungi
 *    di halaman tanpa identitas ini — CSRF melindungi sesi yang di sini memang
 *    tidak ada.
 *
 * CATATAN OPS — PERIKSA SEBELUM MENGIRIM TAUTAN PERTAMA. Halaman ini hanya
 * benar-benar terbuka untuk pelanggan bila gerbang Basic Auth nginx erp1 sudah
 * turun; selama ia berdiri, pelanggan mendapat kotak sandi, bukan formulir.
 * Keadaan gerbang itu adalah fakta PRODUKSI, bukan fakta repo — dan dokumen
 * repo ini sendiri belum sepakat tentangnya (PANDUAN-ADMINISTRATOR §3.5/§12
 * dan PERSETUJUAN-EKSTERNAL.md masih menuliskannya berdiri). Jangan menebak
 * dari sini: buka satu tautan uji dari luar jaringan sebelum mengirim yang
 * pertama kepada pelanggan sungguhan.
 *
 * Yang TIDAK bergantung pada gerbang mana pun: tidak ada satu surel pun yang
 * terkirim dari sini (MAIL_MAILER=log di kedua .env), penerbitlah yang
 * mengirim tautannya.
 */

Route::get('penilaian/{token}', [CsatPageController::class, 'show'])
    ->where('token', '[A-Za-z0-9-]{20,64}')
    ->middleware('throttle:10,1');

Route::post('penilaian/{token}', [CsatPageController::class, 'rate'])
    ->where('token', '[A-Za-z0-9-]{20,64}')
    ->middleware('throttle:10,1');
