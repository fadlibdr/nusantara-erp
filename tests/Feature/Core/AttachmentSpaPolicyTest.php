<?php

namespace Tests\Feature\Core;

use Modules\Core\Models\Attachment;
use Modules\Core\Services\AttachmentService;
use Tests\ErpTestCase;

/**
 * The attachment policy exists twice: once in AttachmentService, where it is
 * enforced, and once in the SPA, where it decides what the file picker offers
 * and what the size hint promises. There is no build step to share it, so this
 * reads the JavaScript and fails when the two drift.
 *
 * Drift is not cosmetic. An extension missing from the accept list is a type
 * the server takes but the picker greys out on every device; a stale size
 * constant either promises what the API will refuse or refuses client-side
 * what the API would take; and a transport threshold above the JSON route's
 * ceiling would base64 a file straight into a 413.
 */
class AttachmentSpaPolicyTest extends ErpTestCase
{
    public function test_the_file_picker_offers_exactly_what_the_service_allows(): void
    {
        $source = $this->spa('views/attachments.js');

        $this->assertSame(
            1,
            preg_match_all("/accept: '([^']+)'/", $source, $matches),
            'Expected exactly one accept list in attachments.js; the picker input has moved or multiplied.',
        );

        $js = explode(',', $matches[1][0]);
        sort($js);

        $php = array_map(static fn (string $extension) => '.'.$extension, array_keys(AttachmentService::ALLOWED));
        sort($php);

        $this->assertSame(
            $php,
            $js,
            "The attachments.js accept list has drifted from AttachmentService::ALLOWED.\n"
            .'Only in PHP: '.implode(', ', array_diff($php, $js))."\n"
            .'Only in JS:  '.implode(', ', array_diff($js, $php)),
        );
    }

    public function test_the_javascript_size_limits_mirror_the_service(): void
    {
        $source = $this->spa('views/attachments.js');

        $this->assertSame(
            1,
            preg_match('/const MAX_BYTES = (\d+) \* 1024 \* 1024;/', $source, $matches),
            'MAX_BYTES could not be found in attachments.js in the N * 1024 * 1024 form this test reads.',
        );
        $this->assertSame(AttachmentService::MAX_BYTES, (int) $matches[1] * 1024 * 1024);

        $this->assertSame(
            1,
            preg_match('/const SIZE_LIMITS = \{(.*?)\};/s', $source, $matches),
            'SIZE_LIMITS could not be found in attachments.js; the per-extension caps are no longer mirrored.',
        );

        preg_match_all('/(\w+): (\d+) \* 1024 \* 1024/', $matches[1], $entries, PREG_SET_ORDER);

        $js = [];
        foreach ($entries as $entry) {
            $js[$entry[1]] = (int) $entry[2] * 1024 * 1024;
        }
        ksort($js);

        $php = AttachmentService::SIZE_LIMITS;
        ksort($php);

        $this->assertSame(
            $php,
            $js,
            'The attachments.js SIZE_LIMITS have drifted from AttachmentService::SIZE_LIMITS.',
        );
    }

    /**
     * uploadFile() sends anything over this threshold as multipart. It must
     * equal the service's MAX_BYTES: lower wastes the JSON route every
     * deployment already accepts, higher base64s a file past the JSON route's
     * 7 000 000-char ceiling — the arithmetic at AttachmentService::MAX_BYTES.
     */
    public function test_the_transport_threshold_in_api_js_is_the_json_route_ceiling(): void
    {
        $source = $this->spa('api.js');

        $this->assertSame(
            1,
            preg_match('/const JSON_UPLOAD_MAX_BYTES = (\d+) \* 1024 \* 1024;/', $source, $matches),
            'JSON_UPLOAD_MAX_BYTES could not be found in api.js; uploadFile() no longer picks its transport where this test can see it.',
        );
        $this->assertSame(AttachmentService::MAX_BYTES, (int) $matches[1] * 1024 * 1024);
    }

    /**
     * Empat keadaan masa berlaku (F-8), dan kartu harus mengenali keempatnya.
     *
     * TIGA punya cabangnya sendiri (`validity.state === '…'`); yang keempat,
     * 'berlaku', SENGAJA tidak — ia cabang bawaan, dan cabang bawaan itulah
     * yang juga menampung keadaan baru yang belum dikenal versi klien ini.
     * Apa yang digambar cabang itu dipaku uji berikutnya.
     *
     * Dicocokkan terhadap KODE, bukan terhadap berkasnya: versi lama uji ini
     * mencari substring "'berlaku'" di seluruh attachments.js dan hijau karena
     * satu-satunya kemunculannya adalah sebuah KOMENTAR — mengedit komentar
     * itu memerahkannya sementara mengubah perilaku yang dijanjikannya tidak.
     */
    public function test_the_card_handles_every_validity_state_the_model_can_answer(): void
    {
        $code = $this->code('views/attachments.js');

        foreach ([
            Attachment::VALIDITY_NONE,
            Attachment::VALIDITY_NEAR,
            Attachment::VALIDITY_EXPIRED,
        ] as $state) {
            $this->assertStringContainsString(
                "validity.state === '{$state}'",
                $code,
                "Kartu lampiran tidak punya cabang untuk keadaan '{$state}'.",
            );
        }

        $this->assertStringNotContainsString(
            "validity.state === '".Attachment::VALIDITY_OK."'",
            $code,
            "'".Attachment::VALIDITY_OK."' adalah cabang BAWAAN kartu, bukan cabang bernama. "
            .'Bila ia kini punya cabangnya sendiri, uji cabang bawaan di bawah menjaga keadaan '
            .'yang salah — perbarui keduanya bersama.',
        );
    }

    /**
     * Cabang BAWAAN kartu adalah teks polos, dan itu keputusan, bukan kelalaian.
     *
     * Ke sana jatuh keadaan 'berlaku' — gambar kerja yang berlaku sampai 2028,
     * mayoritas berkas bertanggal di sistem ini — dan juga keadaan apa pun yang
     * dikirim server versi lebih baru. Sebuah badge() di sini menaruh peringatan
     * kuning pada setiap berkas yang sama sekali tidak bermasalah, di setiap
     * kartu lampiran di seluruh aplikasi.
     *
     * Sebelum uji ini, mutasi `return el('span', …)` → `return badge(…, 'amber')`
     * LOLOS HIJAU di seluruh gerbang phpunit.
     */
    public function test_the_default_validity_branch_is_plain_text_not_a_badge(): void
    {
        $body = $this->functionBody('views/attachments.js', 'validityNode');

        $this->assertGreaterThan(
            0,
            preg_match_all('/return ([^;]+);/', $body, $matches),
            'validityNode() tidak lagi punya satu pun return yang terbaca uji ini.',
        );

        // Cabang bawaan adalah return TERAKHIR: setiap keadaan bernama pulang
        // lebih dulu dari cabangnya sendiri.
        $last = (string) end($matches[1]);

        $this->assertStringNotContainsString('badge(', $last,
            'Cabang bawaan kartu lampiran digambar sebagai lencana — itu peringatan pada setiap '
            .'berkas yang masih berlaku, dan pada setiap keadaan yang belum dikenal klien ini.');
        $this->assertStringContainsString('sampai', $last,
            'Cabang bawaan tidak lagi menuliskan tanggal berlakunya.');
    }

    /**
     * DUA PINTU TULIS, dan keduanya bisa dicabut tanpa satu pun uji memerah.
     *
     * Seluruh isi F-8 di sisi klien adalah dua benda: tombol "Masa berlaku"
     * per baris (di dalam cabang canEdit, izin yang sama dengan servernya) dan
     * kotak tanggal di kotak unggah yang nilainya benar-benar ikut naik.
     * Menghapus tombolnya, atau mengganti `validUntil.value` menjadi literal
     * `null` sehingga kotaknya menjadi hiasan, LOLOS HIJAU di seluruh gerbang
     * phpunit sebelum uji ini — pemakai mengetik tanggal, menekan unggah, dan
     * barisnya berbunyi "Tanpa masa berlaku" tanpa satu pun pesan.
     */
    public function test_the_row_offers_the_expiry_door_to_whoever_may_edit(): void
    {
        $body = $this->functionBody('views/attachments.js', 'attachmentRow');

        $this->assertMatchesRegularExpression(
            "/canEdit\s*\?\s*button\('Masa berlaku'/s",
            $body,
            'Baris lampiran tidak lagi menawarkan tombol "Masa berlaku" di dalam cabang canEdit.',
        );
        $this->assertStringContainsString('expiryModal(attachment', $body,
            'Tombol "Masa berlaku" tidak lagi membuka dialognya.');
    }

    public function test_the_uploader_actually_sends_what_the_date_box_holds(): void
    {
        $body = $this->functionBody('views/attachments.js', 'uploader');

        $this->assertMatchesRegularExpression(
            '/valid_until:\s*validUntil\.value\s*\|\|\s*null/',
            $body,
            'Kotak "Masa berlaku (opsional)" tidak lagi ikut ke api.uploadFile() — ia menjadi hiasan, '
            .'dan berkas yang diunggah dengan tanggal tersimpan tanpa tanggal.',
        );
    }

    /**
     * Permukaan BACA lampiran yang KEDUA: strip "Foto lapangan".
     *
     * captureCard memanggil core/attachments TANPA menyaring jenis berkas, jadi
     * polis atau sertifikat yang dilampirkan lewat kartu Lampiran dokumen yang
     * SAMA (projects/daily-reports, servicedesk/tickets) ikut tampil di sana.
     * Sebuah berkas yang berbunyi "Kedaluwarsa 06 Sep 2026 · 4 hari lalu" di
     * kartu tidak boleh diam di layar yang justru dibuka teknisi di lapangan —
     * itu aturan yang sama ditegakkan di satu permukaan dan bocor di permukaan
     * lain yang setara.
     *
     * Satu sumber, bukan aturan kedua: strip memanggil validityNode() milik
     * kartu, hanya dengan keadaan NORMAL disembunyikan (setiap foto lapangan
     * ada di keadaan itu).
     */
    public function test_the_field_photo_strip_reads_the_same_validity_the_card_draws(): void
    {
        $code = $this->code('views/lapangan.js');

        $this->assertMatchesRegularExpression(
            "/import \{[^}]*validityNode[^}]*\} from '\.\/attachments\.js';/",
            $code,
            'lapangan.js tidak lagi memakai validityNode() milik kartu — dua salinan aturan yang sama '
            .'akan berbeda dalam enam bulan.',
        );
        $this->assertStringContainsString('validityNode(attachment, { hideWhenNone: true })', $code,
            'Strip Foto lapangan tidak lagi menggambar keadaan masa berlaku berkasnya.');
        // Dituntut sebagai PEMANGGILAN, bukan sebagai substring: "validityLine(
        // attachment)" juga muncul di baris deklarasi fungsinya, jadi mencabut
        // pemasangannya dari photoStrip() akan lolos hijau.
        $this->assertMatchesRegularExpression(
            "/\n\s+validityLine\(attachment\),/",
            $code,
            'Baris keadaan masa berlaku tidak dipasang di baris strip Foto lapangan.',
        );
    }

    /**
     * Dialog masa berlaku MEMBACA jendela peringatannya, tidak menyalinnya.
     *
     * `validity.lead_days` ikut di setiap baris lampiran justru supaya kalimat
     * bantuan dialognya tidak perlu menyalin angkanya — itu yang dijanjikan
     * docblock Attachment::getValidityAttribute. Sebelum ini kalimatnya literal
     * "30 hari sebelumnya" dan payload-nya tidak pernah dibaca: mengubah
     * jendelanya meninggalkan dialog yang berbohong kepada pemakai sementara
     * lencana kuning menyala di ambang yang lain.
     */
    public function test_the_expiry_dialog_reads_the_warning_window_it_was_sent(): void
    {
        $body = $this->functionBody('views/attachments.js', 'expiryModal');

        $this->assertStringContainsString('validity.lead_days', $body,
            'Dialog masa berlaku tidak membaca jendela peringatan yang dikirim server.');
        $this->assertDoesNotMatchRegularExpression('/\d+ hari sebelumnya/', $body,
            'Kalimat bantuan dialog menyalin jendela peringatan sebagai angka — ia akan tetap '
            .'menyebut angka lama pada hari jendelanya diubah.');
    }

    /**
     * "Tanpa masa berlaku" adalah KEADAAN NORMAL sebuah lampiran, bukan
     * peringatan — dan hampir setiap baris core_attachments ada di keadaan itu.
     * Cabangnya harus mengembalikan teks biasa; sebuah badge() di sana menaruh
     * satu lencana pada setiap foto lapangan di seluruh aplikasi.
     */
    public function test_the_no_expiry_state_is_written_as_plain_text_not_a_badge(): void
    {
        $source = $this->code('views/attachments.js');

        $this->assertSame(
            1,
            preg_match(
                "/if \(validity\.state === '".Attachment::VALIDITY_NONE."'\) \{\s*\n\s*return ([^;]+);/",
                $source,
                $matches,
            ),
            'Cabang "tanpa masa berlaku" tidak ditemukan di attachments.js; uji ini tidak lagi menjaga apa pun.',
        );

        $this->assertStringNotContainsString('badge(', $matches[1],
            'Keadaan normal sebuah lampiran digambar sebagai lencana — itu peringatan pada setiap foto lapangan.');
        $this->assertStringContainsString('Tanpa masa berlaku', $matches[1]);
    }

    private function spa(string $file): string
    {
        return (string) file_get_contents(public_path('app/js/'.$file));
    }

    /**
     * Berkas SPA tanpa komentarnya.
     *
     * Uji yang mencari sebuah pola di seluruh berkas hijau ketika polanya cuma
     * ada di dalam komentar yang menjanjikannya — dan komentar tidak menjaga
     * apa pun. Blok komentar dan baris yang seluruhnya komentar dilucuti.
     */
    private function code(string $file): string
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $this->spa($file));

        return (string) preg_replace('#^\s*//[^\n]*$#m', '', $source);
    }

    /** Badan satu fungsi tingkat atas, tanpa komentar. */
    private function functionBody(string $file, string $function): string
    {
        $this->assertSame(
            1,
            preg_match('/function '.preg_quote($function, '/').'\([^)]*\) \{(.*?)\n\}/s', $this->code($file), $matches),
            "Fungsi {$function}() tidak ditemukan di {$file}; uji ini tidak lagi menjaga apa pun.",
        );

        return $matches[1];
    }
}
