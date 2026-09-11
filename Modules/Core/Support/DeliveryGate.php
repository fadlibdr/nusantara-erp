<?php

namespace Modules\Core\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\UserPreference;

/**
 * SATU tempat yang menjawab "mengapa kanal ini tidak akan mengirim kepada
 * orang ini sekarang" (P-3a).
 *
 * Aturan `skipped` yang benar di satu permukaan tetapi bocor di permukaan
 * lain adalah cacat yang berulang di kampanye ini. Maka setiap sebab hanya
 * ditulis di sini, dan EMPAT permukaan memanggil fungsi yang sama:
 *
 *   NotificationService::outbox()   — tulis baris `skipped` alih-alih `queued`
 *   NotificationService::retry()    — tolak 422 dengan kalimat + petunjuknya
 *   DeliverNotification::handle()   — keadaan berubah sejak baris ditulis
 *                                     (sakelar dimatikan, mailer diganti,
 *                                     kanal dimatikan orangnya): baris jadi
 *                                     `skipped`, bukan dikirim
 *   GET core/me/notification-channels — layar Profil menunjukkan keadaan yang
 *                                     SAMA kepada orangnya (T3a.2)
 *
 * Urutan pemeriksaan disengaja dari yang paling global ke yang paling
 * pribadi: sakelar Pengaturan → konfigurasi server → pilihan pengguna →
 * alamat. Sebab PERTAMA yang benar yang ditulis; kalau administrator
 * membetulkan yang pertama, Kirim ulang akan menyebut yang berikutnya.
 *
 * Jam tenang BUKAN sebab skipped: ia menunda (postponement()), tidak pernah
 * membuang — lihat QuietHours.
 *
 * Setiap kalimat dalam Bahasa Indonesia dan menyebut apa yang kurang —
 * kalimat inilah yang tampil di kolom "Galat / alasan".
 */
final class DeliveryGate
{
    public const EMAIL_DISABLED = 'E-mail dinonaktifkan di Pengaturan.';

    public const EMAIL_NO_ADDRESS = 'Penerima tidak punya alamat e-mail.';

    public const USER_OFF = 'Dimatikan pengguna di Profil › Notifikasi.';

    /** Kunci preferensi P1-C yang membawa pilihan kanal per pengguna (T3a.2). */
    public const PREF_CHANNELS = 'notify.channels';

    /** Kanal luar yang bisa dipilih orangnya — urutan tampil di Profil. */
    public const USER_CHANNELS = [NotificationDelivery::CHANNEL_EMAIL, NotificationDelivery::CHANNEL_WHATSAPP];

    /**
     * Sebab `skipped`, atau null bila kanal ini boleh mencoba mengirim kepada
     * orang ini.
     */
    public static function reasonToSkip(string $channel, User $recipient): ?string
    {
        return match ($channel) {
            NotificationDelivery::CHANNEL_EMAIL => self::emailReason($recipient),
            default => "Kanal {$channel} belum tersedia (Fase 3).",
        };
    }

    /**
     * Alamat yang dituju kanal ini untuk orang ini — dibaca SEGAR dari
     * penggunanya, bukan dari `recipient` yang dibekukan di baris (baris
     * `skipped` karena alamat kosong menyuruh melengkapi alamatnya lalu kirim
     * ulang, dan perintah itu hanya bisa dipenuhi bila yang dibaca alamat baru
     * — verifikasi P-0b, 5 Sep 2026).
     */
    public static function address(string $channel, User $recipient): string
    {
        return match ($channel) {
            NotificationDelivery::CHANNEL_EMAIL => trim((string) $recipient->email),
            default => '',
        };
    }

    /**
     * Kalimat 422 untuk Kirim ulang: sebab yang sama, ditambah apa yang harus
     * dilakukan supaya kirim ulang berikutnya berhasil.
     */
    public static function retryRefusal(string $reason): string
    {
        return match (true) {
            $reason === self::EMAIL_DISABLED => 'E-mail masih dinonaktifkan di Pengaturan — nyalakan dulu, lalu kirim ulang.',
            $reason === self::EMAIL_NO_ADDRESS => 'Penerima tidak punya alamat e-mail; lengkapi alamatnya di Sistem › Pengguna, lalu kirim ulang.',
            $reason === self::USER_OFF => 'Penerima mematikan kanal ini di Profil › Notifikasi; hanya penerimanya sendiri yang bisa menyalakannya lagi, lalu kirim ulang.',
            str_starts_with($reason, 'MAIL_MAILER=') => 'MAIL_MAILER masih '.MailTransport::mailerName().' — belum ada server surel. Arahkan MAIL_* di .env ke server sungguhan (DEPLOYMENT.md §11), lalu kirim ulang.',
            default => rtrim($reason, '.').' — betulkan dulu, lalu kirim ulang.',
        };
    }

    /**
     * Penundaan jam tenang untuk orang ini SEKARANG: sampai kapan, dan
     * kalimatnya untuk kolom "Galat / alasan". Null bila di luar jendela atau
     * tanpa jam tenang. Dipakai kotak keluar, Kirim ulang, dan job.
     *
     * @return array{until: CarbonImmutable, reason: string}|null
     */
    public static function postponement(User $recipient, ?CarbonInterface $now = null): ?array
    {
        $quiet = QuietHours::forUser($recipient);

        if ($quiet === null) {
            return null;
        }

        $until = $quiet->resumeAt($now ?? CarbonImmutable::now());

        return $until === null ? null : ['until' => $until, 'reason' => $quiet->postponedSentence($until)];
    }

    /**
     * Pilihan kanal si penerima: true = nyala. Kunci yang belum pernah
     * dipilih TIDAK punya baris (CONVENTIONS §15), dan bawaannya NYALA — kanal
     * yang mati diam-diam untuk semua orang bukan bawaan, itu kanal yang tidak
     * ada.
     */
    public static function userEnabled(string $channel, User $recipient): bool
    {
        $value = self::preference($recipient, self::PREF_CHANNELS);

        if (! is_array($value) || ! array_key_exists($channel, $value)) {
            return true;
        }

        return $value[$channel] !== false;
    }

    public static function preference(User $recipient, string $key): mixed
    {
        return UserPreference::query()
            ->where('user_id', $recipient->getKey())
            ->where('key', $key)
            ->first()?->value;
    }

    private static function emailReason(User $recipient): ?string
    {
        if (! Erp::bool('notifications.email_enabled', false)) {
            return self::EMAIL_DISABLED;
        }

        $mailer = MailTransport::skipReason();
        if ($mailer !== null) {
            return $mailer;
        }

        if (! self::userEnabled(NotificationDelivery::CHANNEL_EMAIL, $recipient)) {
            return self::USER_OFF;
        }

        if (self::address(NotificationDelivery::CHANNEL_EMAIL, $recipient) === '') {
            return self::EMAIL_NO_ADDRESS;
        }

        return null;
    }
}
