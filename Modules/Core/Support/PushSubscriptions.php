<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Exceptions\PushDeviceLimitException;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Services\AuditService;

/**
 * Daftar perangkat seseorang, dan satu-satunya tempat yang menulisnya
 * (P-3e, T3e.2/T3e.3).
 *
 * Dua penulis, dua alasan:
 *
 *  - register() dipanggil layar Profil ketika orangnya menekan "Aktifkan
 *    notifikasi di perangkat ini", dan oleh rotasi `pushsubscriptionchange`
 *    ketika peramban MEMUTAR endpoint-nya sendiri. Keduanya updateOrCreate
 *    atas endpoint_hash — satu langganan, satu baris, selamanya.
 *  - forgetExpired() dipanggil kanal ketika layanan push menjawab 404/410.
 *
 * KEJADIAN 404/410 TERCATAT DI TEMPAT YANG BERTAHAN SESUDAH BARISNYA HILANG.
 * Menuliskannya "di baris langganan" tidak berarti apa-apa: baris itulah yang
 * dihapus. Jadi ia ditulis ke core_audit_log — append-only dan ikut ke setiap
 * cadangan — dengan label perangkat dan sebabnya. Baris kotak keluar yang
 * memicunya juga menyimpan kalimat yang sama di kolom `error`, tetapi baris
 * itu boleh dipangkas operator suatu hari; log audit tidak punya jalur hapus
 * di aplikasi ini.
 *
 * APLIKASI INI BELUM PUNYA LAYAR LOG AUDIT (putaran verifikasi: B-3).
 * Barisnya memang ditulis; yang tidak ada adalah menu untuk membacanya —
 * tidak ada entri navigasi dan tidak ada rute SPA, dan PANDUAN-ADMINISTRATOR
 * §3.10 mengatakannya hitam di atas putih. Yang ada adalah
 * `GET api/core/audit-log?auditable_type=…` di balik izin core.view. Menyebut
 * "Sistem › Log Audit" di sini akan mengirim administrator yang sedang
 * menyelidiki ke menu yang tidak punya barisnya, lalu membuatnya menyimpulkan
 * catatannya tidak ada.
 *
 * PLAFON PERANGKAT (putaran verifikasi: A-5). Satu baris kotak keluar per
 * perangkat berarti seorang penerima dengan N perangkat menghasilkan N
 * permintaan keluar per pemberitahuan, masing-masing bisa menahan pekerja
 * antrean sampai waktu tunggunya habis. Karena itu MAX_PER_USER: bukan aturan
 * kenyamanan, melainkan batas penguatan lalu lintas yang bisa dipicu pengguna
 * biasa.
 */
final class PushSubscriptions
{
    /**
     * Perangkat yang boleh dipegang seorang pengguna sekaligus.
     *
     * Sepuluh adalah angka yang tidak pernah ditemui orang sungguhan — ponsel,
     * komputer kantor, komputer rumah, tablet lapangan sudah empat — dan
     * sekaligus plafon yang membuat satu pemberitahuan tidak pernah menjadi
     * ratusan permintaan keluar.
     */
    public const MAX_PER_USER = 10;

    /** @return Collection<int, PushSubscription> */
    public static function forUser(User $user): Collection
    {
        return PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->orderBy('id')
            ->get();
    }

    public static function countFor(User $user): int
    {
        return PushSubscription::query()->where('user_id', $user->getKey())->count();
    }

    /**
     * Daftarkan (atau perbarui) satu perangkat.
     *
     * $previousEndpoint diisi oleh DUA pemanggil, dan keduanya punya alasan
     * yang sama: peramban yang SAMA baru saja memberi endpoint yang BERBEDA.
     * Rotasi `pushsubscriptionchange` adalah satu; yang kedua adalah layar
     * Profil pada jalur InvalidStateError, yaitu hari setelah pemilik
     * mengganti kunci VAPID — langganan lama dibuang di peramban dan yang baru
     * dibuat dengan kunci baru (putaran verifikasi: B-5/C-4). Tanpa nilai ini
     * baris lama bertahan sebagai perangkat hantu yang tidak pernah dibuang
     * siapa pun: 404/410 menghapus, tetapi langganan lama sesudah ganti kunci
     * dijawab 401/403, dan 401/403 tidak menghapus apa pun.
     *
     * SATU ENDPOINT, SATU PEMILIK — DAN PERPINDAHANNYA DICATAT (putaran
     * verifikasi: A-3). Langganan push milik PERAMBAN, bukan akun: di komputer
     * lapangan yang dipakai bergantian, peramban memulangkan endpoint yang
     * SAMA untuk siapa pun yang sedang masuk. Jadi barisnya memang harus
     * berpindah — dua baris untuk satu langganan berarti pemberitahuan orang
     * pertama tetap dikirim ke layar orang kedua. Yang tidak boleh adalah
     * berpindah DIAM-DIAM: perpindahan antar-pengguna menulis baris audit,
     * supaya "kenapa perangkat saya hilang dari daftar" punya jawaban.
     */
    public static function register(User $user, string $endpoint, string $p256dh, string $auth, ?string $userAgent = null, ?string $previousEndpoint = null): PushSubscription
    {
        if ($previousEndpoint !== null && trim($previousEndpoint) !== '' && trim($previousEndpoint) !== trim($endpoint)) {
            PushSubscription::query()
                ->where('user_id', $user->getKey())
                ->where('endpoint_hash', PushSubscription::hashFor($previousEndpoint))
                ->delete();
        }

        $hash = PushSubscription::hashFor($endpoint);
        $existing = PushSubscription::query()->where('endpoint_hash', $hash)->first();

        /*
         * Plafon diperiksa ketika perangkat ini akan menjadi perangkat BARU
         * BAGI ORANG INI — bukan hanya ketika endpoint-nya belum dikenal
         * siapa pun (putaran penutup, V-2). Versi pertama memeriksa
         * `$existing === null` saja, jadi pendaftaran yang MENGAMBIL ALIH
         * endpoint milik akun lain — jalur "peramban bersama" yang memang
         * disengaja beberapa baris di bawah — melewati plafon sepenuhnya, dan
         * seseorang yang sudah memegang sepuluh perangkat berakhir dengan
         * sebelas. MAX_PER_USER dinyatakan batas penguatan lalu lintas di tiga
         * dokumen; sebuah batas yang punya jalan memutar bukan batas.
         */
        if ($existing === null || (int) $existing->user_id !== (int) $user->getKey()) {
            self::assertRoomFor($user);
        }

        $previousOwner = $existing === null ? null : (int) $existing->user_id;

        $device = PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => $hash],
            [
                'user_id' => $user->getKey(),
                'endpoint' => trim($endpoint),
                'p256dh' => $p256dh,
                'auth' => $auth,
                'device_label' => PushDeviceLabel::fromUserAgent($userAgent),
            ],
        );

        if ($previousOwner !== null && $previousOwner !== (int) $user->getKey()) {
            app(AuditService::class)->event(
                $device,
                'updated',
                [
                    'user_id' => ['from' => $previousOwner, 'to' => (int) $user->getKey()],
                    'alasan' => ['from' => null, 'to' => 'Peramban yang sama didaftarkan oleh pengguna lain (perangkat bersama); langganannya berpindah.'],
                ],
                $device->label(),
            );
        }

        return $device;
    }

    /**
     * @throws PushDeviceLimitException bila plafon sudah penuh
     */
    private static function assertRoomFor(User $user): void
    {
        if (self::countFor($user) < self::MAX_PER_USER) {
            return;
        }

        throw new PushDeviceLimitException(
            'Akun ini sudah memegang '.self::MAX_PER_USER.' perangkat, dan itu batasnya: setiap pemberitahuan '
            .'dikirim ke SETIAP perangkat, satu per satu. Cabut perangkat yang sudah tidak dipakai di '
            .'Profil › Notifikasi, lalu aktifkan perangkat ini lagi.',
        );
    }

    /**
     * Layanan push menyatakan langganan ini mati (404/410). Barisnya dibuang,
     * dan kejadiannya dicatat di tempat yang bertahan sesudahnya.
     */
    public static function forgetExpired(PushSubscription $subscription, string $reason): void
    {
        $label = $subscription->label();
        $userId = (int) $subscription->user_id;

        app(AuditService::class)->event(
            $subscription,
            'deleted',
            [
                'device_label' => ['from' => $label, 'to' => null],
                'user_id' => ['from' => $userId, 'to' => null],
                'alasan' => ['from' => null, 'to' => $reason],
            ],
            $label,
        );

        $subscription->delete();
    }
}
