<?php

namespace Tests\Feature\Core;

use Modules\Core\Services\AttachmentService;
use Tests\ErpTestCase;

/**
 * Lapangan T2.9 — kemajuan unggah per foto dan antrean kirim ulang.
 *
 * ASESMEN-UX §4.3: foto 5 MB lewat JSON base64 di jaringan seluler lokasi
 * 20–40 detik tanpa indikator, dan bila putus fotonya lenyap bersama toast.
 * Diukur 4 Sep 2026 (harness S15, unggahan dicekik 200 kB/s): 70 peristiwa
 * upload.progress untuk foto 1 MB lewat XHR, satu lewat loopback — fetch()
 * tidak punya satu pun. Gerak bilahnya diukur harness, bukan di sini (tidak
 * ada runtime JS di host ini). Yang dipaku di sini adalah empat hal yang bisa
 * hanyut diam-diam tanpa build step:
 *
 *  - api.js memuat TEPAT SATU XMLHttpRequest, dan itu jalur unggahnya —
 *    pengecualian tunggal yang disepakati RECAP T2.9, bukan pintu menuju
 *    klien kedua; fetch tetap mengangkut yang lain;
 *  - lapangan.js mengirim foto lewat api.upload(), bukan api.post(): tanpa
 *    itu bilahnya diam di 0 %;
 *  - awalan kunci antrean berbeda dari awalan draf drafts.js dan keduanya
 *    tidak saling mengawali: listDrafts() memindai localStorage per awalan,
 *    dan butir 1,4 juta karakter base64 akan ditawarkan sebagai "Pulihkan";
 *  - MAX_BYTES lapangan.js = AttachmentService::MAX_BYTES — attachments.js
 *    sudah diawasi AttachmentSpaPolicyTest, lapangan.js memegang salinannya
 *    sendiri (pagar sebelum membaca 12 MP jadi base64) tanpa pengawas.
 */
class LapanganUploadQueueTest extends ErpTestCase
{
    public function test_api_js_has_exactly_one_xmlhttprequest_and_it_reports_upload_progress(): void
    {
        $source = $this->spa('api.js');

        $this->assertSame(
            1,
            substr_count($source, 'new XMLHttpRequest('),
            'api.js harus memuat tepat satu XMLHttpRequest — jalur unggah dengan kemajuan; yang lain tetap fetch.',
        );
        $this->assertStringContainsString("xhr.upload.addEventListener('progress'", $source);
        $this->assertStringContainsString(
            "upload: (path, body, onProgress) => requestWithProgress('POST', path, body, onProgress)",
            $source,
            'api.upload() tidak lagi menuju jalur XHR; kemajuan unggah lapangan.js hilang tanpa galat.',
        );
        $this->assertSame(
            2,
            substr_count($source, 'await fetch('),
            'request() dan requestBlob() adalah dua jalur fetch; jumlah lain berarti transport ketiga menyelinap.',
        );
    }

    public function test_lapangan_sends_photos_through_the_progress_path_and_keeps_the_failed_ones(): void
    {
        $source = $this->spa('views/lapangan.js');

        $this->assertStringContainsString("api.upload('core/attachments'", $source);
        $this->assertStringNotContainsString(
            "api.post('core/attachments'",
            $source,
            'Foto lapangan kembali ke api.post(): tidak ada peristiwa kemajuan di fetch, bilahnya diam di 0 %.',
        );
        $this->assertStringContainsString("button('Kirim ulang'", $source);
        $this->assertStringContainsString('localStorage.setItem(queueKey(item)', $source);
    }

    public function test_the_queue_prefix_and_the_draft_prefix_never_overlap(): void
    {
        $this->assertSame(1, preg_match("/const PREFIX = '([^']+)';/", $this->spa('drafts.js'), $draft));
        $this->assertSame(1, preg_match("/const QUEUE_PREFIX = '([^']+)';/", $this->spa('views/lapangan.js'), $queue));

        $this->assertStringStartsWith('nusantara_erp_', $queue[1]);
        $this->assertFalse(
            str_starts_with($queue[1], $draft[1]) || str_starts_with($draft[1], $queue[1]),
            "Awalan antrean '{$queue[1]}' dan awalan draf '{$draft[1]}' saling mengawali: listDrafts() akan "
            .'menawarkan foto base64 sebagai draf formulir.',
        );
    }

    public function test_the_lapangan_size_gate_mirrors_the_service(): void
    {
        $this->assertSame(
            1,
            preg_match('/const MAX_BYTES = (\d+) \* 1024 \* 1024;/', $this->spa('views/lapangan.js'), $matches),
            'MAX_BYTES tidak ditemukan di lapangan.js dalam bentuk N * 1024 * 1024 yang dibaca uji ini.',
        );
        $this->assertSame(AttachmentService::MAX_BYTES, (int) $matches[1] * 1024 * 1024);
    }

    private function spa(string $file): string
    {
        return (string) file_get_contents(public_path('app/js/'.$file));
    }
}
