<?php

namespace Modules\Core\Contracts;

/**
 * Kanal yang penyedianya TIDAK memberi pengenal pesan — dan tidak bisa
 * memberinya (P-3e, T3e.3).
 *
 * Aturan rumah sejak P-3a berbunyi: sebuah baris pengiriman hanya boleh `sent`
 * bila penyedia memberi PENGENAL (Message-ID setelah percakapan SMTP selesai,
 * wamid dari Meta). Aturan itu ada karena `MAIL_MAILER=log` pernah menghasilkan
 * `sent` dengan Message-ID buatan mesin sendiri — klaim tanpa server di
 * baliknya.
 *
 * WEB PUSH TIDAK PUNYA MESSAGE ID DALAM STANDARNYA. RFC 8030 §5 menjadikan
 * header `Location` OPSIONAL: sebagian layanan push mengirimnya (dan itu URL
 * sumber daya pesan, bukan pengenal yang bisa ditanyakan statusnya), sebagian
 * tidak mengirim apa pun selain `201 Created`. Maka ada dua pilihan, dan hanya
 * satu yang jujur:
 *
 *   (i)  mengarang pengenal (uuid kita sendiri) supaya aturannya terpenuhi —
 *        yaitu persis kebohongan yang aturan itu dibuat untuk mencegahnya:
 *        sebuah kolom "pengenal penyedia" yang tidak pernah disentuh penyedia;
 *   (ii) mengatakan bahwa untuk kanal ini BUKTI PENERIMAAN adalah status HTTP
 *        201/2xx dari layanan push itu sendiri, dan membiarkan provider_id
 *        kosong ketika layanan push memang tidak memberi Location.
 *
 * Paket ini memilih (ii), dan antarmuka penanda inilah bentuk tertulisnya.
 * DeliverNotification melonggarkan syarat "pengenal wajib" HANYA untuk kanal
 * yang mengimplementasikan antarmuka ini. MailChannel dan WhatsAppChannel
 * TIDAK mengimplementasikannya, dan sebuah uji memaku bahwa keduanya tetap
 * mencatat percobaan gagal ketika pengenalnya kosong — longgarnya tidak
 * merembes ke kanal yang penyedianya memang memberi pengenal.
 *
 * Kanal yang mengimplementasikan ini tetap wajib MELEMPAR bila gagal. Yang
 * dilonggarkan hanya "kosong berarti belum tentu sampai"; "gagal berarti gagal"
 * tidak pernah dilonggarkan.
 */
interface ChannelWithoutMessageId {}
