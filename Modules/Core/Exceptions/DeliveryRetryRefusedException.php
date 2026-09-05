<?php

namespace Modules\Core\Exceptions;

use InvalidArgumentException;

/**
 * Kirim ulang pengiriman ditolak dengan alasannya (sudah `sent`, e-mail masih
 * dimatikan, penerima tanpa alamat) — NotificationService::retry (T0b.3).
 *
 * Kelas sendiri, bukan InvalidArgumentException polos: antrean yang tidak
 * terkonfigurasi melempar InvalidArgumentException juga ("The [x] queue
 * connection has not been configured"), dan `catch (InvalidArgumentException)`
 * di controller menyamarkannya sebagai 422 "permintaan salah" — padahal itu
 * 503 "antrean menolak" yang barisnya sudah `queued` (verifikasi P-0b, 5 Sep
 * 2026). Tetap turunan InvalidArgumentException supaya pemanggil lama yang
 * menangkap induknya tidak berubah perilaku.
 */
class DeliveryRetryRefusedException extends InvalidArgumentException {}
