<?php

namespace Tests\Feature\Core;

use Modules\Core\Jobs\DeliverWebhook;
use Modules\Core\Support\WebhookSignature;
use Tests\ErpTestCase;

/**
 * SATU RESEP, TIGA PERMUKAAN — DAN SEBUAH UJI YANG BENAR-BENAR MEMBACA KETIGANYA
 * (V-OPENAPI-7, V-webhook-5).
 *
 * `WebhookSignature` sendiri mengatakan resep tanda tangannya "sama di
 * `docs/PANDUAN-ADMINISTRATOR.md` §5.14 dan di `docs/api/openapi.json`, dan
 * `WebhookSignatureTest` memaku ketiganya sama". Berkas dengan nama itu tidak
 * pernah ada: yang ada hanya dua uji yang masing-masing memaku literalnya
 * SENDIRI-SENDIRI terhadap kelasnya, dan tidak satu pun uji di repositori ini
 * membaca PANDUAN. Pembaca berikutnya yang mengubah `TOLERANCE` dari 300 ke 600
 * percaya ada yang akan memerah bila runbook-nya tidak ikut diperbarui —
 * tidak ada, dan penerima webhook di perusahaan lain memakai jendela yang
 * salah dari runbook yang basi (pelajaran 6).
 *
 * Uji ini adalah berkas itu. Ia membuka kedua dokumen, memotong bagian yang
 * menjanjikan resepnya, dan menuntut angka serta kalimat di dalamnya sama
 * dengan konstanta yang benar-benar dipakai mengirim.
 */
class WebhookSignatureTest extends ErpTestCase
{
    private const PANDUAN = 'docs/PANDUAN-ADMINISTRATOR.md';

    private const OPENAPI = 'docs/api/openapi.json';

    /** Bagian runbook yang menjanjikan resepnya, dari judulnya sampai judul berikutnya. */
    private function runbook(): string
    {
        $path = base_path(self::PANDUAN);

        $this->assertFileExists($path);

        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $out = [];
        $inside = false;

        foreach ($lines as $line) {
            if (! $inside) {
                $inside = str_starts_with($line, '### 5.14 Webhook keluar');
            } elseif (str_starts_with($line, '### 5.15') || str_starts_with($line, '## 6.')) {
                break;
            }

            if ($inside) {
                $out[] = $line;
            }
        }

        $text = implode("\n", $out);

        // SYARAT INI HARUS BISA GAGAL: sebuah §5.14 yang dihapus atau diberi
        // judul lain memulangkan string kosong, dan setiap asersi di bawahnya
        // akan lewat dengan diam bila tidak diperiksa di sini.
        $this->assertGreaterThan(2000, strlen($text), '§5.14 PANDUAN-ADMINISTRATOR tidak ditemukan atau kosong');

        return $text;
    }

    /** @return array<string, mixed> */
    private function openapiSignature(): array
    {
        $path = base_path(self::OPENAPI);

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['x-webhook']['tanda_tangan'] ?? null);

        return $decoded['x-webhook']['tanda_tangan'];
    }

    /**
     * JENDELA WAKTU: satu angka, tiga tempat.
     *
     * Angkanya ditulis literal di sini dengan tangan — sebuah uji yang membaca
     * 300 dari `WebhookSignature::TOLERANCE` lalu mencarinya di dokumen akan
     * tetap hijau ketika seseorang mengetik 600 di kedua tempat sekaligus,
     * padahal penerima yang sudah memasang 300 detik ada di luar sana.
     */
    public function test_the_tolerance_window_is_three_hundred_seconds_on_every_surface(): void
    {
        $this->assertSame(300, WebhookSignature::TOLERANCE);
        $this->assertSame(300, $this->openapiSignature()['jendela_detik']);
        $this->assertStringContainsString('|sekarang − t| > 300 detik', $this->runbook());
    }

    /** HEADER, ALGORITMA, DAN APA YANG DITANDATANGANI. */
    public function test_the_header_and_the_signed_value_are_the_same_on_every_surface(): void
    {
        $runbook = $this->runbook();
        $document = $this->openapiSignature();

        $this->assertSame('X-Nusantara-Signature', WebhookSignature::HEADER);
        $this->assertSame('X-Nusantara-Signature', $document['header']);
        $this->assertStringContainsString('X-Nusantara-Signature: t=', $runbook);

        $this->assertSame('sha256', WebhookSignature::ALGORITHM);
        $this->assertSame('sha256', $document['algoritma']);
        $this->assertStringContainsString("hash_hmac('sha256', t + '.' + badan_mentah, rahasia_langganan)", $runbook);

        $this->assertStringContainsString('<t>.<badan mentah>', $document['yang_ditandatangani']);
        $this->assertStringContainsString('stempel waktunya IKUT ditandatangani', $document['yang_ditandatangani']);
        $this->assertStringContainsString('ikut ditandatangani', $runbook);

        $this->assertSame('hash_equals(), bukan ===', $document['bandingkan']);
        $this->assertStringContainsString('`hash_equals()`', $runbook);

        $this->assertSame('X-Nusantara-Event', WebhookSignature::EVENT_HEADER);
        $this->assertStringContainsString('X-Nusantara-Event', $document['id_peristiwa']);
        $this->assertStringContainsString('X-Nusantara-Event', $runbook);
    }

    /**
     * V-webhook-5: BENTUK RAHASIANYA, yang tidak bisa ditebak dari nilainya.
     *
     * Sebuah verifikator penerima yang ditulis hanya dari §5.14 gagal pada
     * satu baris dan hanya satu: rahasia 64 karakter [0-9a-f] yang di-decode
     * sebagai hex menghasilkan tanda tangan yang tidak pernah cocok, tanpa
     * satu pun pesan yang menunjuk ke sebabnya.
     */
    public function test_the_form_of_the_secret_is_stated_on_every_surface(): void
    {
        $sentence = WebhookSignature::SECRET_FORM;

        $this->assertStringContainsString('64 karakter heksadesimal', $sentence);
        $this->assertStringContainsString('APA ADANYA', $sentence);
        $this->assertStringContainsString('bukan di-decode dari hex', $sentence);

        $this->assertSame($sentence, $this->openapiSignature()['rahasia']);
        $this->assertStringContainsString($sentence, $this->runbook());

        // Dan rahasia yang benar-benar dibuat memang berbentuk itu.
        $secret = WebhookSignature::newSecret();
        $this->assertSame(64, strlen($secret));
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $secret));
    }

    /**
     * JADWAL PERCOBAAN: dokumen menjanjikan lima percobaan dengan jeda rumah,
     * dan §5.14 menyuruh penerima menolak stempel yang lebih tua dari 300
     * detik. Kedua kalimat itu hanya bisa berdiri bersama karena tanda
     * tangannya dihitung per PERCOBAAN (V-webhook-1) — kalau tidak, percobaan
     * ke-3 sampai ke-5 selalu di luar jendela yang dokumen ini perintahkan.
     */
    public function test_the_retry_schedule_is_the_same_on_every_surface(): void
    {
        $this->assertSame('5, dengan jeda 60/300/900/3600 detik', $this->openapiSignature()['percobaan']);
        $this->assertStringContainsString('**60 / 300 / 900 / 3600 detik**', $this->runbook());
        $this->assertSame([60, 300, 900, 3600], DeliverWebhook::BACKOFF);
        $this->assertSame(5, DeliverWebhook::TRIES);
    }
}
