<?php

namespace Modules\Core\Support;

/**
 * Apa yang sudah/belum diisi pemilik untuk kanal WhatsApp (P-3a, T3a.3) —
 * dibaca dari config('erp.whatsapp'), yang seluruhnya datang dari .env dan
 * KOSONG di repo.
 *
 * Satu tempat yang menjawab "kanal ini bisa mengirim?" dan "template untuk
 * peristiwa ini sudah disetujui?", dipakai DeliveryGate (skipped dengan
 * sebab), WhatsAppChannel (sebelum menyentuh Http::), webhook (rahasia
 * tanda tangan), dan layar Profil ("0 dari 5 template terisi").
 *
 * Nilai rahasia (token, app secret, verify token) TIDAK PERNAH dipulangkan
 * ke jawaban API mana pun; secrets() hanya dipakai ProviderErrorScrubber
 * untuk menyamarkannya dari teks galat.
 */
final class WhatsAppSetup
{
    public const PROVIDER_META = 'meta';

    public const PROVIDER_QONTAK = 'qontak';

    public const PROVIDERS = [self::PROVIDER_META, self::PROVIDER_QONTAK];

    public const SKIP_UNCONFIGURED = 'Kanal WhatsApp belum dikonfigurasi (WHATSAPP_TOKEN / WHATSAPP_PHONE_NUMBER_ID di .env kosong — prasyarat pemilik, KEPUTUSAN-INTEGRASI.md §4).';

    public static function provider(): string
    {
        return strtolower(trim((string) config('erp.whatsapp.provider', self::PROVIDER_META)));
    }

    public static function token(): ?string
    {
        return self::env('token');
    }

    public static function phoneNumberId(): ?string
    {
        return self::env('phone_number_id');
    }

    public static function appSecret(): ?string
    {
        return self::env('app_secret');
    }

    public static function verifyToken(): ?string
    {
        return self::env('verify_token');
    }

    public static function language(): string
    {
        return self::env('language') ?? 'id';
    }

    public static function apiBase(): string
    {
        return rtrim((string) (self::env('api_base') ?? 'https://graph.facebook.com'), '/');
    }

    public static function apiVersion(): string
    {
        return trim((string) (self::env('api_version') ?? 'v21.0'), '/');
    }

    public static function timeoutSeconds(): int
    {
        return max(3, (int) config('erp.whatsapp.timeout_seconds', 15));
    }

    /** Kredensial untuk penyedia Meta ada semua? (Qontak: pengirimnya belum ditulis — lihat skipReason.) */
    public static function configured(): bool
    {
        return self::provider() === self::PROVIDER_META
            && self::token() !== null
            && self::phoneNumberId() !== null;
    }

    /**
     * Sebab `skipped` karena KONFIGURASI (bukan karena orangnya), atau null
     * bila kanal boleh mencoba. Diperiksa SEBELUM Http:: — tanpa kredensial
     * tidak ada satu permintaan pun yang keluar.
     */
    public static function skipReason(): ?string
    {
        $provider = self::provider();

        if ($provider === self::PROVIDER_QONTAK) {
            return 'Penyedia WhatsApp "qontak" dikenali tetapi pengirimnya belum ditulis — menunggu keputusan pemilik #7 '
                .'dan akses sandbox Qontak (KEPUTUSAN-INTEGRASI.md §5); bentuk API-nya tidak dikarang.';
        }

        if ($provider !== self::PROVIDER_META) {
            return "Penyedia WhatsApp \"{$provider}\" tidak dikenal. Yang tersedia: meta (Cloud API langsung); qontak dikenali "
                .'tetapi belum diimplementasikan; gateway WhatsApp Web (Fonnte dsb.) DITOLAK karena nomornya bisa diblokir Meta.';
        }

        return self::configured() ? null : self::SKIP_UNCONFIGURED;
    }

    /** Nama template yang disetujui Meta untuk peristiwa ini, atau null bila belum diisi. */
    public static function templateName(string $templateKey): ?string
    {
        $name = trim((string) config("erp.whatsapp.templates.{$templateKey}", ''));

        return $name === '' ? null : $name;
    }

    public static function templateSkipReason(string $templateKey): ?string
    {
        if (self::templateName($templateKey) !== null) {
            return null;
        }

        $env = NotificationTemplates::keys()[$templateKey]['whatsapp']['env'] ?? 'WHATSAPP_TEMPLATE_*';

        return "Template WhatsApp untuk peristiwa {$templateKey} belum disetujui Meta / belum diisi di .env ({$env}) — "
            .'Meta hanya menerima pesan template yang disetujui.';
    }

    /**
     * Nilai-nilai yang tidak boleh muncul di teks mana pun yang disimpan.
     *
     * @return list<string>
     */
    public static function secrets(): array
    {
        return array_values(array_filter([self::token(), self::appSecret(), self::verifyToken()]));
    }

    private static function env(string $key): ?string
    {
        $value = trim((string) config("erp.whatsapp.{$key}", ''));

        return $value === '' ? null : $value;
    }
}
