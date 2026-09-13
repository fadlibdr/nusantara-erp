<?php

namespace Modules\Core\Channels;

use LogicException;
use Minishlink\WebPush\Encryption;
use Modules\Core\Contracts\ChannelWithoutMessageId;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Exceptions\DeliveryRejectedException;
use Modules\Core\Exceptions\DeliverySkippedException;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Support\ProviderErrorScrubber;
use Modules\Core\Support\PushEndpoint;
use Modules\Core\Support\PushSubscriptions;
use Modules\Core\Support\WebPushSender;
use Modules\Core\Support\WebPushSetup;
use RuntimeException;
use Throwable;

/**
 * Kanal web push — protokol Web Push standar (RFC 8030/8291/8292), TANPA SDK
 * penyedia dan tanpa Firebase (P-3e, T3e.3).
 *
 * "Tidak ada FCM" berarti: tidak ada SDK Google, tidak ada kunci server
 * Firebase, tidak ada ketergantungan pada satu perusahaan. Yang kita lakukan
 * hanyalah mem-POST ke alamat yang DIBERIKAN PERAMBAN; kalau alamat itu
 * kebetulan `fcm.googleapis.com`, itu karena Chrome memilih layanan push-nya
 * sendiri — Firefox akan memberi alamat Mozilla dan Safari alamat Apple, dan
 * kode ini tidak berubah satu baris pun.
 *
 * SATU BARIS = SATU PERANGKAT. Berbeda dari e-mail dan WhatsApp, yang menulis
 * satu baris per penerima, kanal ini ber-fan-out di kotak keluar: satu baris
 * core_notification_deliveries per LANGGANAN, dengan push_subscription_id-nya.
 * Alasannya di LAPORAN §2; yang penting di sini: kelas ini mengirim ke SATU
 * langganan, jadi ia tidak pernah harus memilih satu jawaban dari tiga.
 *
 * Tiga hasil, seperti kanal lain:
 *   sent      2xx dari layanan push. provider_id = header `Location` bila
 *             layanan push mengirimnya, KOSONG bila tidak — lihat
 *             ChannelWithoutMessageId: web push tidak punya message id dalam
 *             standarnya, dan mengarang satu adalah kebohongan yang aturan
 *             "pengenal wajib" justru dibuat untuk mencegahnya.
 *   rejected  404/410 (langganan mati → DIHAPUS, kejadiannya dicatat) dan
 *             401/403 (VAPID salah/tidak diterima) → DeliveryRejectedException
 *             → `failed` seketika, satu percobaan.
 *   retried   429 / 5xx / jaringan → pengecualian biasa → 5 percobaan dengan
 *             backoff yang sudah ada.
 *
 * ISINYA TERENKRIPSI UJUNG-KE-UJUNG (aes128gcm) DENGAN KUNCI MILIK PERAMBAN:
 * layanan push tidak bisa membaca judul maupun isi pemberitahuan. Yang TETAP
 * dilihatnya adalah bahwa ada pesan, kapan, dan untuk endpoint yang mana —
 * dan hanya itu yang boleh diklaim (KEPUTUSAN-INTEGRASI §12).
 *
 * Pemeriksaan konfigurasi berjalan SEBELUM pengirim disentuh: tanpa VAPID
 * tidak ada satu permintaan pun yang keluar dari mesin. Itu dipaku uji dengan
 * pengirim yang meledak bila dipanggil — Http::preventStrayRequests() TIDAK
 * cukup di sini, karena pustaka pengirimnya memakai Guzzle mentah.
 */
class WebPushChannel implements ChannelWithoutMessageId, DeliveryChannel
{
    /**
     * Plafon muatan, dan alasannya BUKAN batas pustaka.
     *
     * Batas keras aes128gcm di pustaka ini adalah 4.078 byte (melempar di
     * atasnya). Yang dipakai di sini adalah 2.820 — panjang padding otomatis
     * pustaka (MAX_COMPATIBILITY_PAYLOAD_LENGTH) — karena di bawah angka itu
     * SETIAP badan permintaan keluar dengan panjang yang sama persis (diukur
     * 13 Sep 2026: 2.922 byte, apa pun isinya). Muatan yang melewatinya
     * membuat panjang badan ikut berubah, dan panjang badan adalah satu-satunya
     * hal tentang isi pesan yang bisa dibaca layanan push. Jadi muatannya
     * DIPOTONG di sini, bukan dibiarkan melempar di pustaka.
     */
    public const MAX_PAYLOAD_BYTES = Encryption::MAX_COMPATIBILITY_PAYLOAD_LENGTH;

    public function name(): string
    {
        return NotificationDelivery::CHANNEL_WEBPUSH;
    }

    public function send(NotificationDelivery $delivery, Notification $notification): ?string
    {
        $skip = WebPushSetup::skipReason();
        if ($skip !== null) {
            throw new DeliverySkippedException($skip);
        }

        $subscription = $this->subscriptionOf($delivery);
        $endpoint = (string) $subscription->endpoint;

        // PEMERIKSAAN KEDUA, TEPAT SEBELUM MENGIRIM (putaran verifikasi:
        // A-1/B-2). Yang pertama berjalan saat perangkat didaftarkan; ini
        // berjalan sekarang, karena sebuah baris kotak keluar bisa menunggu
        // berjam-jam di jam tenang dan sebuah nama yang kemarin menunjuk
        // alamat publik bisa hari ini menunjuk 127.0.0.1. Alasannya sama
        // persis dengan DeliverWebhook, dan kodenya memang sama (P-3d §11).
        try {
            PushEndpoint::assertSafeToSend($endpoint);
        } catch (LogicException $e) {
            throw new DeliveryRejectedException(
                "Perangkat «{$subscription->label()}» tidak dikirimi: ".$e->getMessage(),
            );
        }

        try {
            $report = app(WebPushSender::class)->send($subscription, self::payloadFor($notification));
        } catch (Throwable $e) {
            // Pustaka melempar SEBELUM ada permintaan (kunci VAPID tidak bisa
            // diurai, kunci peramban rusak). Itu bukan jawaban penyedia, tetapi
            // juga bukan sesuatu yang berubah bila diulang.
            throw new DeliveryRejectedException(
                'Pengiriman web push gagal disiapkan: '.ProviderErrorScrubber::webPush($e->getMessage(), $endpoint),
            );
        }

        // 404/410: langganan ini MATI. Perangkatnya dihapus dan kejadiannya
        // dicatat di log audit — yang bertahan sesudah barisnya hilang — lalu
        // barisnya `failed` seketika: mengulang empat kali ke endpoint yang
        // sudah tidak ada hanya menunda kabar yang sama.
        if ($report->isSubscriptionExpired()) {
            $status = $report->getResponse()?->getStatusCode() ?? 410;
            $label = $subscription->label();

            PushSubscriptions::forgetExpired(
                $subscription,
                "Layanan push menjawab HTTP {$status}: langganan tidak berlaku lagi.",
            );

            throw new DeliveryRejectedException(
                "Langganan perangkat «{$label}» sudah tidak berlaku (HTTP {$status}) — perangkat dihapus dari daftar. "
                .'Orangnya harus menekan "Aktifkan notifikasi di perangkat ini" lagi di Profil › Notifikasi.',
            );
        }

        $status = $report->getResponse()?->getStatusCode();

        // PENGALIHAN TIDAK DIIKUTI, DAN TIDAK PERNAH DIHITUNG TERKIRIM
        // (putaran verifikasi: A-2). Dengan allow_redirects dimatikan di
        // WebPushSender, Guzzle memulangkan 3xx itu sendiri sebagai jawaban
        // yang berhasil — dan pustaka ini menandai setiap jawaban yang bukan
        // galat HTTP sebagai success, termasuk 3xx. Tanpa cabang ini sebuah
        // 307 akan tercatat `sent` sementara layanan push tidak pernah
        // menerima apa pun.
        if ($status !== null && $status >= 300 && $status < 400) {
            throw new DeliveryRejectedException(
                "Layanan push menjawab HTTP {$status} (pengalihan) untuk perangkat «{$subscription->label()}». "
                .'Pengalihan TIDAK diikuti: sebuah permintaan bertanda tangan yang dipindahkan ke alamat lain '
                .'meninggalkan setiap pemeriksaan alamat yang berlaku di atasnya. Pemberitahuan ini tidak terkirim.',
            );
        }

        if ($report->isSuccess()) {
            // "Terakhir berhasil" milik PERANGKATNYA, bukan barisnya: layar
            // Profil menjawab "kapan perangkat ini terakhir benar-benar
            // menerima" tanpa menelusuri kotak keluar, dan sebuah perangkat
            // yang tanggalnya kosong berbulan-bulan adalah perangkat yang
            // pantas dicabut.
            $subscription->forceFill(['last_success_at' => now()])->save();

            // RFC 8030 §5: `Location` OPSIONAL. Yang ada dipakai apa adanya,
            // yang tidak ada dibiarkan kosong — bukti penerimaan kanal ini
            // adalah 2xx itu sendiri (ChannelWithoutMessageId).
            return trim((string) ($report->getResponse()?->getHeaderLine('Location') ?? ''));
        }

        $message = $this->describe($report->getReason(), $status, $subscription->label(), $endpoint);

        // 401/403 = header VAPID ditolak layanan push: kunci salah, subject
        // ditolak, atau langganan dibuat dengan applicationServerKey LAIN
        // (yaitu: kunci VAPID pernah diganti). Permanen — mengulang lima kali
        // tidak akan membuat kuncinya cocok.
        if ($status === 401 || $status === 403) {
            throw new DeliveryRejectedException(
                $message.' Kunci VAPID ditolak layanan push: periksa VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY/VAPID_SUBJECT di .env '
                .'(DEPLOYMENT.md §11.3). Bila kunci baru saja diganti, SELURUH langganan lama memang batal dan setiap '
                .'perangkat harus mendaftar ulang.',
            );
        }

        throw new RuntimeException($message);
    }

    /**
     * Muatan yang dibaca sw.js. Empat field, semuanya sudah terenkripsi saat
     * meninggalkan mesin ini.
     *
     * `tag` = id notifikasi: perangkat yang menerima pemberitahuan yang sama
     * dua kali (Kirim ulang sesudah kegagalan sementara pada perangkat itu)
     * MENIMPA yang lama alih-alih menumpuk dua baris identik di baki
     * pemberitahuan.
     */
    public static function payloadFor(Notification $notification): string
    {
        $url = $notification->link === null ? null : rtrim((string) config('app.url'), '/').'/app/'.$notification->link;

        $title = self::flatten((string) $notification->title, 120);
        $body = self::flatten((string) $notification->body, 600);

        $build = static fn (string $body): string => (string) json_encode([
            'judul' => $title,
            'isi' => $body,
            'tautan' => $url,
            'tag' => 'erp-notif-'.$notification->getKey(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $payload = $build($body);

        // Potong sampai muat. Dilakukan di sini, bukan dibiarkan melempar di
        // pustaka: sebuah notifikasi yang isinya panjang harus SAMPAI dengan
        // isi terpotong, bukan tidak sampai sama sekali.
        //
        // SYARAT HENTINYA ADALAH "ISINYA MASIH MENGECIL", BUKAN "ISINYA
        // KOSONG" (putaran verifikasi: A-7/B-8). Setiap putaran menambahkan
        // '…' di ujung, jadi $body TIDAK PERNAH menjadi string kosong: ia
        // mengecil sampai '…' lalu berhenti mengecil. Penjaga lama karena itu
        // tidak pernah bisa menyala, dan bila bagian muatan yang TIDAK
        // dipotong (judul, tautan) sendirian sudah melewati plafon, gelungnya
        // berputar tanpa akhir DI DALAM PEKERJA ANTREAN — bukan galat yang
        // tercatat, melainkan pekerja yang menggantung sampai --timeout
        // membunuhnya, berulang untuk setiap percobaan. Diukur 13 Sep 2026
        // dengan app.url sepanjang 3.000 aksara: 40 putaran, $body terkunci
        // pada '…', panjang muatan mandek di 3.061 byte.
        while (strlen($payload) > self::MAX_PAYLOAD_BYTES && mb_strlen($body) > 1) {
            $body = rtrim(mb_substr($body, 0, max(0, (int) (mb_strlen($body) * 0.8) - 1))).'…';
            $payload = $build($body);
        }

        // Isinya sudah habis dan muatannya MASIH kelewat besar: yang kelewat
        // panjang adalah judul atau tautannya, dan memotong isi lagi tidak
        // akan menolong. Satu baris `failed` yang bisa dibaca jauh lebih baik
        // daripada pekerja yang menggantung.
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new DeliveryRejectedException(
                'Muatan pemberitahuan ini tetap melewati '.self::MAX_PAYLOAD_BYTES.' byte setelah isinya dipotong '
                .'habis: judul atau tautannya sendiri sudah kelewat panjang. Periksa APP_URL di .env — tautan '
                .'notifikasi dibentuk dari sana.',
            );
        }

        return $payload;
    }

    /**
     * Langganan milik baris ini, dibaca SEGAR. Perangkat yang dicabut orangnya
     * (atau dihapus karena 410) antara baris ditulis dan job berjalan membuat
     * barisnya `skipped` dengan kalimat yang mengatakannya — bukan `failed`,
     * karena tidak ada yang gagal: sasarannya yang sudah tidak ada.
     */
    private function subscriptionOf(NotificationDelivery $delivery): PushSubscription
    {
        $id = $delivery->push_subscription_id;
        $ownerId = $delivery->notification?->user_id;

        // DICARI DI DALAM LINGKUP PEMILIK BARIS (putaran verifikasi: B-1).
        // Langganan push milik PERAMBAN, bukan akun: di komputer yang dipakai
        // bergantian, peramban memulangkan endpoint yang SAMA untuk siapa pun
        // yang sedang masuk, dan menekan "Aktifkan" MEMINDAHKAN barisnya ke
        // orang kedua — id barisnya tidak berubah. Sebuah baris kotak keluar
        // milik orang pertama yang masih `queued` (di jam tenang ia bisa
        // menunggu sampai 8 jam) akan, tanpa lingkup ini, mengirim judul dan
        // isi pemberitahuannya ke layar orang kedua — dan mencatat `sent`
        // atas nama orang pertama. Diukur 13 Sep 2026 di pohon ini: baris itu
        // benar-benar terkirim dan benar-benar tercatat `sent`.
        //
        // Jalur "Kirim ulang" sudah memakai lingkup yang sama
        // (NotificationService::retry); jalur yang benar-benar MENGIRIM tidak
        // punya — itu asimetri, bukan keputusan.
        $subscription = $id === null || $ownerId === null
            ? null
            : PushSubscription::query()->where('user_id', $ownerId)->find($id);

        if ($subscription === null) {
            throw new DeliverySkippedException(
                'Perangkat tujuan baris ini sudah tidak terdaftar atas nama penerimanya (dicabut pemiliknya, dihapus '
                .'karena langganannya kedaluwarsa, atau didaftarkan ulang oleh pengguna lain di peramban yang sama); '
                .'tidak ada yang bisa dikirimi.',
            );
        }

        return $subscription;
    }

    /**
     * Kalimat yang masuk kolom "Galat / alasan" — disaring sebelum
     * meninggalkan kelas ini, DAN endpoint-nya ikut disamarkan.
     *
     * Pesan Guzzle memuat URL permintaan lengkap, jadi tanpa penyamaran
     * endpoint 188 karakter itu mendarat apa adanya di
     * core_notification_deliveries.error — yang digambar sebagai
     * "Galat / alasan" bagi SETIAP pemegang core.update, ikut ke setiap
     * cadangan, dan ikut ke setiap tangkapan layar yang dikirim orang saat
     * minta bantuan (putaran verifikasi: A-6/B-4). Endpoint push adalah
     * KAPABILITAS — POST push/rotate memakainya sebagai satu-satunya
     * kredensial — jadi ia diperlakukan sebagai kredensial di sini juga.
     * Yang tersisa di kalimat, LABEL perangkatnya, sudah menjawab "perangkat
     * mana yang gagal".
     */
    private function describe(string $reason, ?int $status, string $label, string $endpoint): string
    {
        $text = sprintf(
            'Layanan push %s untuk perangkat «%s»%s',
            $status === null ? 'tidak terjangkau' : "menjawab HTTP {$status}",
            $label,
            trim($reason) === '' ? '' : ': '.trim($reason),
        );

        return ProviderErrorScrubber::webPush($text, $endpoint);
    }

    /** Satu baris, tanpa baris baru/tab, dipotong pada batasnya. */
    private static function flatten(string $text, int $limit): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($flat) <= $limit ? $flat : rtrim(mb_substr($flat, 0, $limit - 1)).'…';
    }
}
