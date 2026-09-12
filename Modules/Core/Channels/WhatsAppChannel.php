<?php

namespace Modules\Core\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Core\Contracts\DeliveryChannel;
use Modules\Core\Exceptions\DeliveryRejectedException;
use Modules\Core\Exceptions\DeliverySkippedException;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Support\NotificationTemplates;
use Modules\Core\Support\PhoneNumber;
use Modules\Core\Support\ProviderErrorScrubber;
use Modules\Core\Support\WhatsAppSetup;
use RuntimeException;

/**
 * Kanal WhatsApp lewat Meta Cloud API LANGSUNG (P-3a, T3a.3) — klien HTTP
 * Laravel, tanpa SDK penyedia (batas paket: nol dependensi Composer baru).
 *
 * HANYA PESAN TEMPLATE. Meta menolak teks bebas di luar jendela 24 jam sejak
 * pesan terakhir dari nomor itu, dan alarm sistem hampir tidak pernah berada
 * di dalam jendela itu. Jadi yang dikirim adalah template yang DISETUJUI
 * Meta atas nama pemilik — namanya dari .env per peristiwa (WhatsAppSetup::
 * templateName) — dengan tiga parameter badan {{1}} judul {{2}} isi {{3}}
 * tautan (NotificationTemplates::whatsappParameters). Notifikasi tanpa
 * template (pengajuan/persetujuan dokumen) tidak dikirim sebagai teks bebas;
 * ia `skipped` dengan kalimat yang mengatakannya.
 *
 * Pemeriksaan konfigurasi berjalan SEBELUM Http:: disentuh: tanpa kredensial
 * tidak ada satu permintaan pun yang keluar dari mesin (uji memaku
 * Http::preventStrayRequests()).
 *
 * Tiga hasil, dibedakan karena akibatnya berbeda bagi orang yang membaca
 * layar Pengiriman Notifikasi:
 *   sent      Meta menjawab 2xx dengan messages[0].id (wamid) → provider_id
 *   rejected  4xx yang tidak akan berubah bila diulang (token, template,
 *             nomor bukan WhatsApp, parameter) → DeliveryRejectedException
 *             → `failed` seketika, satu percobaan
 *   retried   429 / 5xx / jaringan → pengecualian biasa → 5 percobaan
 *
 * Perangkap E: jawaban Meta bisa memuat URL bertoken atau nomor telepon;
 * setiap kalimat yang meninggalkan kelas ini melewati ProviderErrorScrubber.
 * Token tidak pernah masuk pesan pengecualian, kolom error, atau log.
 */
class WhatsAppChannel implements DeliveryChannel
{
    /**
     * Kode galat Meta yang PERMANEN untuk pesan ini (Cloud API error codes):
     *  0/190       token tidak sah / kedaluwarsa
     *  100         parameter tidak sah (template/param tidak cocok)
     *  131026      nomor bukan pengguna WhatsApp / tidak bisa dikirimi
     *  131047      di luar jendela 24 jam (bukan template — tidak akan terjadi di sini, tetapi permanen)
     *  131051      jenis pesan tidak didukung
     *  132000–132016 template: tidak ada, parameter kurang/lebih, format, ditangguhkan
     *  133010      nomor pengirim belum terdaftar
     *
     * @var list<int>
     */
    public const PERMANENT_CODES = [0, 100, 190, 131026, 131047, 131051, 132000, 132001, 132005, 132007, 132012, 132015, 132016, 133010];

    public function name(): string
    {
        return NotificationDelivery::CHANNEL_WHATSAPP;
    }

    public function send(NotificationDelivery $delivery, Notification $notification): ?string
    {
        $skip = WhatsAppSetup::skipReason();
        if ($skip !== null) {
            throw new DeliverySkippedException($skip);
        }

        $templateKey = (string) $notification->template;
        if (! NotificationTemplates::has($templateKey)) {
            throw new DeliverySkippedException('Peristiwa ini tidak punya template WhatsApp; Meta hanya menerima pesan template, jadi tidak dikirim.');
        }

        $templateName = WhatsAppSetup::templateName($templateKey);
        if ($templateName === null) {
            throw new DeliverySkippedException((string) WhatsAppSetup::templateSkipReason($templateKey));
        }

        $to = PhoneNumber::normalize($delivery->recipient);
        if ($to === null) {
            throw new DeliveryRejectedException('Nomor penerima bukan E.164 yang sah; betulkan di Profil › Notifikasi atau Sistem › Pengguna.');
        }

        $url = $notification->link === null
            ? null
            : rtrim((string) config('app.url'), '/').'/app/'.$notification->link;

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => PhoneNumber::digits($to),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => WhatsAppSetup::language()],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(
                        static fn (string $text): array => ['type' => 'text', 'text' => $text],
                        NotificationTemplates::whatsappParameters($notification, $url),
                    ),
                ]],
            ],
        ];

        try {
            $response = Http::withToken((string) WhatsAppSetup::token())
                ->acceptJson()
                ->timeout(WhatsAppSetup::timeoutSeconds())
                ->connectTimeout(5)
                ->post(self::endpoint(), $payload);
        } catch (ConnectionException $e) {
            // Jaringan/DNS/timeout: sementara, diulang pekerja. Pesan klien bisa
            // memuat URL — disaring.
            throw new RuntimeException('WhatsApp (Meta) tidak terjangkau: '.ProviderErrorScrubber::whatsapp($e->getMessage()));
        }

        if ($response->successful()) {
            $id = trim((string) $response->json('messages.0.id'));

            if ($id === '') {
                throw new RuntimeException('Meta menjawab 2xx tanpa messages[0].id (wamid); tanpa pengenal tidak ada bukti pesan diterima.');
            }

            return $id;
        }

        $message = self::describe($response);

        if (self::isPermanent($response)) {
            throw new DeliveryRejectedException($message);
        }

        throw new RuntimeException($message);
    }

    public static function endpoint(): string
    {
        return WhatsAppSetup::apiBase().'/'.WhatsAppSetup::apiVersion().'/'.WhatsAppSetup::phoneNumberId().'/messages';
    }

    private static function isPermanent(Response $response): bool
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            return true;
        }

        if ($status === 429 || $status >= 500) {
            return false;
        }

        $code = $response->json('error.code');

        return is_numeric($code) && in_array((int) $code, self::PERMANENT_CODES, true);
    }

    /** "(190) Error validating access token — …" — disaring sebelum meninggalkan kelas ini. */
    private static function describe(Response $response): string
    {
        $code = $response->json('error.code');
        $title = trim((string) ($response->json('error.message') ?? ''));
        $details = trim((string) ($response->json('error.error_data.details') ?? ''));

        $text = sprintf(
            'WhatsApp (Meta) HTTP %d%s%s%s',
            $response->status(),
            is_numeric($code) ? " ({$code})" : '',
            $title !== '' ? ': '.$title : '',
            $details !== '' ? ' — '.$details : '',
        );

        if ($title === '' && $details === '') {
            $text .= ': '.trim((string) $response->body());
        }

        return ProviderErrorScrubber::whatsapp($text);
    }
}
