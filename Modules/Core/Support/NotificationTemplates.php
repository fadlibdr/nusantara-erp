<?php

namespace Modules\Core\Support;

use Illuminate\Support\Str;
use Modules\Core\Models\Notification;

/**
 * Registri template per peristiwa untuk kanal LUAR (P-3a, T3a.1).
 *
 * Dalam selera WatchedDeadlines / UserPreferences: SATU daftar deklaratif.
 * Lima peristiwa operasional ROADMAP-HASHMICRO P-3a, masing-masing dengan:
 *
 *   label     nama Indonesia untuk laporan dan layar
 *   mail      bentuk surelnya: awalan subjek, kalimat pembuka, label tombol
 *   whatsapp  NAMA VARIABEL .env yang membawa nama template yang DISETUJUI
 *             Meta — kosong di repo, diisi pemilik (KEPUTUSAN-INTEGRASI §4.2)
 *
 * PERISTIWA YANG TIDAK ADA DI SINI MEMAKAI TEMPLATE UMUM, DAN ITU DIKATAKAN:
 * document.submitted / approved / rejected, pengingat persetujuan yang belum
 * eskalasi, alarm tutup buku, kalender fiskal, dan `system()` mana pun yang
 * tidak menyebut kunci — forNotification() memulangkan null, MailChannel
 * memakai ApprovalNotificationMail (bentuk surel P-0b), dan WhatsApp
 * `skipped` "peristiwa ini tidak punya template WhatsApp". Tidak ada tebakan
 * template dari judul.
 *
 * KONTRAK PLACEHOLDER WHATSAPP (tiga, tetap, untuk kelima template):
 *   {{1}} judul notifikasi   {{2}} isi   {{3}} tautan (atau "-")
 * Pemilik mengajukan kelima template ke Meta dengan tiga placeholder itu;
 * whatsappParameters() adalah satu-satunya tempat urutannya ditulis.
 */
final class NotificationTemplates
{
    public const DEADLINE_DUE = 'deadline.due';

    public const APPROVAL_ESCALATED = 'approval.escalated';

    public const AR_DUNNING = 'ar.dunning';

    public const BACKUP_STALE = 'backup.stale';

    public const SCHEDULER_DOWN = 'scheduler.down';

    /** Urutan tetap: laporan, layar Profil, dan DEPLOYMENT.md membacanya. */
    public const KEYS = [
        self::DEADLINE_DUE,
        self::APPROVAL_ESCALATED,
        self::AR_DUNNING,
        self::BACKUP_STALE,
        self::SCHEDULER_DOWN,
    ];

    /**
     * Meta membatasi parameter teks: tanpa baris baru/tab, tanpa 4+ spasi
     * berurutan, dan panjang total pesan ≤ 1024 karakter. Isi dipotong di sini
     * supaya template yang disetujui tidak ditolak saat dikirim.
     */
    public const WHATSAPP_PARAM_MAX = 400;

    /**
     * @return array<string, array{label: string, mail: array{subject_prefix: string, intro: string, cta: string}, whatsapp: array{env: string}}>
     */
    public static function keys(): array
    {
        return [
            self::DEADLINE_DUE => [
                'label' => 'Tenggat mendekat / lewat',
                'mail' => [
                    'subject_prefix' => '[Tenggat]',
                    'intro' => 'Pengawas tenggat pagi ini menemukan tanggal yang mendekat atau sudah lewat.',
                    'cta' => 'Buka daftar tenggat',
                ],
                'whatsapp' => ['env' => 'WHATSAPP_TEMPLATE_DEADLINE_DUE'],
            ],
            self::APPROVAL_ESCALATED => [
                'label' => 'Eskalasi persetujuan',
                'mail' => [
                    'subject_prefix' => '[Eskalasi]',
                    'intro' => 'Sebuah dokumen menunggu persetujuan lebih lama dari dua kali batas yang ditetapkan, dan dieskalasi kepada Anda.',
                    'cta' => 'Buka dokumen',
                ],
                'whatsapp' => ['env' => 'WHATSAPP_TEMPLATE_APPROVAL_ESCALATED'],
            ],
            self::AR_DUNNING => [
                'label' => 'Penagihan piutang',
                'mail' => [
                    'subject_prefix' => '[Penagihan]',
                    'intro' => 'Invoice pelanggan yang sudah disetujui melewati tanggal jatuh temponya dan belum lunas.',
                    'cta' => 'Buka invoice pelanggan',
                ],
                'whatsapp' => ['env' => 'WHATSAPP_TEMPLATE_AR_DUNNING'],
            ],
            self::BACKUP_STALE => [
                'label' => 'Cadangan basi / gagal',
                'mail' => [
                    'subject_prefix' => '[Cadangan]',
                    'intro' => 'Pengawas cadangan melaporkan keadaan yang perlu tindakan di server.',
                    'cta' => 'Buka aplikasi',
                ],
                'whatsapp' => ['env' => 'WHATSAPP_TEMPLATE_BACKUP_STALE'],
            ],
            self::SCHEDULER_DOWN => [
                'label' => 'Penjadwal tidak berjalan',
                'mail' => [
                    'subject_prefix' => '[Penjadwal]',
                    'intro' => 'Detak jantung penjadwal erp1 berhenti; perintah terjadwal tidak berjalan sampai unitnya hidup lagi.',
                    'cta' => 'Buka aplikasi',
                ],
                'whatsapp' => ['env' => 'WHATSAPP_TEMPLATE_SCHEDULER_DOWN'],
            ],
        ];
    }

    public static function has(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::keys());
    }

    /**
     * Template milik notifikasi ini, atau NULL = TEMPLATE UMUM. Null di sini
     * adalah keputusan yang disengaja dan dibaca dua kanal: surel memakai
     * ApprovalNotificationMail, WhatsApp melewati dengan kalimatnya.
     *
     * @return array{key: string, label: string, mail: array{subject_prefix: string, intro: string, cta: string}, whatsapp: array{env: string}}|null
     */
    public static function forNotification(Notification $notification): ?array
    {
        $key = $notification->template;

        if (! self::has($key)) {
            return null;
        }

        return ['key' => $key] + self::keys()[$key];
    }

    /**
     * Tiga parameter badan template WhatsApp, dalam urutan kontrak di atas.
     *
     * @return list<string>
     */
    public static function whatsappParameters(Notification $notification, ?string $url): array
    {
        return [
            self::whatsappParam((string) $notification->title),
            self::whatsappParam((string) $notification->body),
            $url === null || trim($url) === '' ? '-' : trim($url),
        ];
    }

    private static function whatsappParam(string $text): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $flat === '' ? '-' : Str::limit($flat, self::WHATSAPP_PARAM_MAX, '…');
    }

    /**
     * Kunci untuk temuan erp:deadline-watch: invoice pelanggan yang LEWAT
     * jatuh tempo adalah penagihan (ar.dunning); tanggal lain yang mendekat
     * atau lewat adalah deadline.due; temuan TANPA_TANGGAL (data yang hilang,
     * bukan tenggat) tidak punya template — umum.
     */
    public static function forDeadlineFinding(string $watcherKey, string $tier): ?string
    {
        if ($tier === WatchedDeadlines::TANPA_TANGGAL) {
            return null;
        }

        if ($watcherKey === 'ar_invoice_due' && $tier === WatchedDeadlines::LEWAT) {
            return self::AR_DUNNING;
        }

        return self::DEADLINE_DUE;
    }
}
