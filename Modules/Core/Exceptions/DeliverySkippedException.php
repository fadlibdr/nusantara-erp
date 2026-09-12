<?php

namespace Modules\Core\Exceptions;

use RuntimeException;

/**
 * Kanal MEMUTUSKAN untuk tidak mencoba mengirim, dengan sebab yang jujur
 * (P-3a): mailer masih `log`, kanal belum dikonfigurasi, penerima mematikan
 * kanalnya, nomor belum opt-in, template belum disetujui Meta.
 *
 * DeliverNotification menangkapnya sebagai `skipped` — bukan `failed` yang
 * diulang lima kali sia-sia, bukan `sent` kosong. Pesannya adalah kalimat
 * Indonesia yang tampil di kolom "Galat / alasan" layar Pengiriman
 * Notifikasi, jadi ia HARUS menyebut apa yang kurang, bukan sekadar
 * "dilewati".
 */
class DeliverySkippedException extends RuntimeException {}
