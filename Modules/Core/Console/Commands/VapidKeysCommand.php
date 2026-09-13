<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Modules\Core\Support\WebPushSetup;
use Throwable;

/**
 * Cetak sepasang kunci VAPID baru untuk pemilik (P-3e, T3e.1).
 *
 * MENCETAK, tidak menulis. Perintah ini tidak menyentuh .env, tidak menyimpan
 * apa pun ke basis data, dan tidak pernah menampilkan kunci privat yang SUDAH
 * terpasang — ia hanya membangkitkan pasangan baru dan menyerahkannya kepada
 * orang yang menjalankannya. Alasannya sederhana: satu-satunya salinan kunci
 * privat yang dipercaya aplikasi ini adalah baris di .env milik pemilik, dan
 * sebuah perintah yang bisa menuliskannya juga adalah perintah yang bisa
 * menimpanya.
 *
 * DAN SATU PERINGATAN YANG HARUS IKUT TERCETAK: mengganti kunci VAPID
 * MEMBATALKAN SELURUH LANGGANAN YANG ADA. `applicationServerKey` terikat pada
 * langganan di peramban — sesudah kunci diganti, setiap POST ke endpoint lama
 * dijawab 403 oleh layanan push, dan setiap perangkat harus menekan
 * "Aktifkan" lagi di Profil › Notifikasi. Itu bukan efek samping yang halus,
 * itu seluruh basis perangkat sekaligus.
 */
class VapidKeysCommand extends Command
{
    protected $signature = 'core:vapid-keys
        {--subject= : Isi VAPID_SUBJECT yang akan dicetak di contoh (mailto:… atau https://…)}';

    protected $description = 'Cetak sepasang kunci VAPID baru untuk web push (tidak menulis .env, tidak menyimpan apa pun)';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->error('Gagal membangkitkan kunci VAPID: '.$e->getMessage());
            $this->line('Kurva prime256v1 (EC) harus didukung openssl mesin ini — periksa `php -r "print_r(openssl_get_curve_names());"`.');

            return self::FAILURE;
        }

        $subject = trim((string) $this->option('subject')) ?: 'mailto:admin@perusahaan.co.id';

        $this->info('Sepasang kunci VAPID baru (base64url). Salin ketiga baris di bawah ke .env:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT='.$subject);
        $this->newLine();

        $this->warn('MENGGANTI KUNCI VAPID MEMBATALKAN SELURUH LANGGANAN YANG ADA.');
        $this->line(
            'applicationServerKey terikat pada langganan di peramban: sesudah kunci diganti, layanan push menolak '
            .'(403) setiap pengiriman ke langganan lama, dan SETIAP perangkat harus menekan "Aktifkan notifikasi di '
            .'perangkat ini" lagi di Profil › Notifikasi. Jangan mengganti kunci untuk merapikan konfigurasi.'
        );
        $this->newLine();
        $this->line('Kunci privat ini TIDAK disimpan di mana pun oleh perintah ini — hanya .env yang menyimpannya.');
        $this->line('Sesudah .env terisi: nyalakan "Kirim juga lewat web push" di Sistem › Pengaturan › Notifikasi.');

        if (WebPushSetup::configured()) {
            $this->newLine();
            $this->comment(
                'Catatan: instalasi ini SUDAH punya VAPID yang terisi. Kunci di atas adalah pasangan BARU yang belum '
                .'dipakai siapa pun; memasangnya berarti membatalkan langganan yang sekarang berjalan.'
            );
        }

        return self::SUCCESS;
    }
}
