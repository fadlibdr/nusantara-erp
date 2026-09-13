<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * MUATAN PERISTIWA — STABIL, BERVERSI, DAN SENGAJA KURUS (P-3d).
 *
 * Bentuknya:
 *
 *     {
 *       "version": 1,
 *       "id": "1f0a…",                       uuid, sama di kelima percobaan
 *       "event": "document.approved",
 *       "occurred_at": "2026-09-12T17:05:00+07:00",
 *       "data": {
 *         "document_type": "finance/ar-invoices",
 *         "document_id": 42,
 *         "document_code": "INV/2026/09/0042",
 *         "status": "approved",
 *         "actor": { "id": 3, "name": "Budi Santoso" },
 *         "note": "Setuju, lampiran lengkap."
 *       }
 *     }
 *
 * KURUS DENGAN SENGAJA — ia PENUNJUK, bukan salinan dokumen. Yang dikirim
 * hanya apa yang dibutuhkan penerima untuk tahu APA yang berubah dan MANA
 * dokumennya; nilai rupiah, nama pelanggan, baris, dan lampiran TIDAK ikut.
 * Tiga alasan, dan masing-masing cukup sendirian:
 *
 *  1. URL penerima milik orang lain. Sebuah muatan gemuk adalah salinan
 *     data keuangan perusahaan yang keluar setiap kali sebuah dokumen
 *     berpindah status, ke server yang tidak dikelola siapa pun di sini.
 *  2. Penerima yang butuh detail memanggil balik API dengan TOKENNYA
 *     SENDIRI — dan pada saat itu ability tokennya berlaku. Muatan yang
 *     membawa detail akan memberikan data yang tokennya belum tentu boleh
 *     membacanya, lewat pintu belakang.
 *  3. Bentuk yang kurus adalah bentuk yang tidak berubah. Setiap kolom yang
 *     ikut adalah janji yang harus dipegang versi berikutnya.
 *
 * `version` naik HANYA bila arti sebuah kolom berubah atau sebuah kolom
 * hilang; menambah kolom baru tidak menaikkannya — penerima yang benar
 * mengabaikan kolom yang tidak dikenalnya.
 */
final class WebhookPayload
{
    public const VERSION = 1;

    public const EVENT_PREFIX = 'document.';

    /** Peristiwa yang bisa dilanggan — sama persis dengan aksi DocumentTransitioned. */
    public const EVENTS = ['document.submitted', 'document.approved', 'document.rejected'];

    public static function eventFor(string $action): string
    {
        return self::EVENT_PREFIX.$action;
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(Model $document, string $action, ?User $actor, ?string $note, string $eventId): array
    {
        return [
            'version' => self::VERSION,
            'id' => $eventId,
            'event' => self::eventFor($action),
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'document_type' => AttachableDocuments::slugForClass($document::class) ?? $document->getMorphClass(),
                'document_id' => (int) $document->getKey(),
                'document_code' => self::codeOf($document),
                'status' => $action,
                'actor' => $actor === null ? null : ['id' => (int) $actor->getKey(), 'name' => (string) $actor->name],
                // Catatan penolakan diketik manusia dan bisa panjang; dipotong
                // supaya satu dokumen tidak pernah menghasilkan muatan raksasa.
                'note' => $note === null ? null : Str::limit(trim($note), 500),
            ],
        ];
    }

    /**
     * SATU-SATUNYA tempat muatan menjadi byte.
     *
     * `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE` disebut di sini dan di
     * dokumen: tanpa keduanya sebuah slug seperti `finance/ar-invoices`
     * dikirim sebagai `finance\/ar-invoices`, yang sah tetapi mengejutkan
     * siapa pun yang membandingkan string. Yang penting bukan pilihannya,
     * melainkan bahwa hanya ADA satu — tanda tangan hanya bisa diperiksa
     * ulang terhadap byte yang sama persis (perangkap D).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function newEventId(): string
    {
        return (string) Str::uuid();
    }

    private static function codeOf(Model $document): ?string
    {
        $code = $document->getAttribute('code');

        return is_string($code) && $code !== '' ? Str::limit($code, 40, '') : null;
    }
}
