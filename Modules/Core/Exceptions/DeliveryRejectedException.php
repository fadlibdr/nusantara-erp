<?php

namespace Modules\Core\Exceptions;

use RuntimeException;

/**
 * Penyedia MENOLAK pesan ini secara permanen (P-3a): token tidak sah, template
 * tidak ada, nomor bukan pengguna WhatsApp, parameter tidak cocok. Mengulang
 * permintaan yang sama lima kali dengan jeda 60/300/900/3600 detik tidak
 * mengubah jawabannya — ia hanya menunda kabar buruknya 78 menit.
 *
 * DeliverNotification menangkapnya sebagai `failed` SEKETIKA, dengan pesan
 * penyedia (yang sudah disaring dari rahasia) di kolom error, dan barisnya
 * menunggu Kirim ulang setelah penyebabnya dibetulkan. Kegagalan sementara
 * (429, 5xx, jaringan) tetap dilempar sebagai pengecualian biasa dan diulang.
 */
class DeliveryRejectedException extends RuntimeException {}
