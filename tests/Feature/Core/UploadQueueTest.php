<?php

namespace Tests\Feature\Core;

use Modules\Core\Services\AttachmentService;
use Tests\ErpTestCase;

/**
 * Antrean kirim T2.9 — kemajuan unggah per butir dan antrean kirim ulang.
 *
 * Sejak F-4 antreannya tinggal di js/uploadqueue.js dan dipakai DUA layar
 * (Lapangan dan Absensi Saya). Uji ini pindah bersamanya: pakunya menempel
 * pada antreannya, bukan pada satu layar yang kebetulan dulu memilikinya.
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
 *  - uploadqueue.js mengirim lewat api.upload(), bukan api.post(): tanpa itu
 *    bilahnya diam di 0 %;
 *  - awalan kunci antrean berbeda dari awalan draf drafts.js dan keduanya
 *    tidak saling mengawali: listDrafts() memindai localStorage per awalan,
 *    dan butir 1,4 juta karakter base64 akan ditawarkan sebagai "Pulihkan";
 *  - MAX_BYTES uploadqueue.js = AttachmentService::MAX_BYTES — attachments.js
 *    sudah diawasi AttachmentSpaPolicyTest, antreannya memegang salinannya
 *    sendiri (pagar sebelum membaca 12 MP jadi base64) tanpa pengawas;
 *  - dan sejak F-4: SATU salinan pagar itu, bukan satu per layar. Layar yang
 *    mendeklarasikan MAX_BYTES-nya sendiri adalah layar yang suatu hari
 *    memakai batas yang berbeda dari servernya.
 */
class UploadQueueTest extends ErpTestCase
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
            "requestWithProgress('POST', path, body, onProgress, raw)",
            $source,
            'api.upload() tidak lagi menuju jalur XHR; kemajuan unggah hilang tanpa galat.',
        );
        // raw:true di antrean — tanpa amplopnya, `message` server hilang dan
        // toast absensi hanya bisa berkata "terkirim" tentang absen 8 km di
        // luar lokasi (harness S31, 8 Sep 2026).
        $this->assertStringContainsString('{ raw: true }', $this->spa('uploadqueue.js'));
        $this->assertSame(
            2,
            substr_count($source, 'await fetch('),
            'request() dan requestBlob() adalah dua jalur fetch; jumlah lain berarti transport ketiga menyelinap.',
        );
    }

    public function test_the_queue_sends_through_the_progress_path_and_keeps_the_failed_ones(): void
    {
        $source = $this->spa('uploadqueue.js');

        $this->assertStringContainsString('api.upload(item.endpoint', $source);
        // Kode saja: docblock berkas ini MENCERITAKAN api.post() sebagai jalur
        // yang ditinggalkan, dan larangan yang ikut membaca komentar akan
        // melarang orang menjelaskan kenapa larangannya ada.
        $this->assertStringNotContainsString(
            'api.post(',
            $this->spaCode('uploadqueue.js'),
            'Antrean kembali ke api.post(): tidak ada peristiwa kemajuan di fetch, bilahnya diam di 0 %.',
        );
        $this->assertStringContainsString("button('Kirim ulang'", $source);
        $this->assertStringContainsString('localStorage.setItem(queueKey(item)', $source);
    }

    /**
     * Foto lapangan tetap mendarat di core/attachments, dan absen di pintu
     * absen — satu antrean, dua tujuan, dan tidak satu pun layar yang menyusun
     * permintaannya sendiri di luar antrean.
     */
    public function test_both_screens_hand_their_work_to_the_queue(): void
    {
        $lapangan = $this->spa('views/lapangan.js');
        $absensi = $this->spa('views/absensisaya.js');

        $this->assertStringContainsString("endpoint: 'core/attachments'", $lapangan);
        $this->assertStringContainsString("check_in: 'hr/attendances/me/clock-in'", $absensi);
        $this->assertStringContainsString("check_out: 'hr/attendances/me/clock-out'", $absensi);

        foreach (['views/lapangan.js', 'views/absensisaya.js'] as $view) {
            $source = $this->spa($view);

            $this->assertStringNotContainsString(
                'const QUEUE_PREFIX',
                $source,
                $view.' mendeklarasikan awalan antreannya sendiri: dua antrean di localStorage yang '
                .'sama, dan butir yang satu tidak akan pernah terlihat oleh yang lain.',
            );
            $this->assertStringNotContainsString(
                'const MAX_BYTES',
                $source,
                $view.' memegang salinan batas ukurannya sendiri; salinan kedua adalah salinan yang '
                .'suatu hari berbeda dari servernya.',
            );
        }
    }

    public function test_the_queue_prefix_and_the_draft_prefix_never_overlap(): void
    {
        $this->assertSame(1, preg_match("/const PREFIX = '([^']+)';/", $this->spa('drafts.js'), $draft));
        $this->assertSame(1, preg_match("/const QUEUE_PREFIX = '([^']+)';/", $this->spa('uploadqueue.js'), $queue));

        $this->assertStringStartsWith('nusantara_erp_', $queue[1]);
        $this->assertFalse(
            str_starts_with($queue[1], $draft[1]) || str_starts_with($draft[1], $queue[1]),
            "Awalan antrean '{$queue[1]}' dan awalan draf '{$draft[1]}' saling mengawali: listDrafts() akan "
            .'menawarkan foto base64 sebagai draf formulir.',
        );
    }

    public function test_the_size_gate_mirrors_the_service(): void
    {
        $this->assertSame(
            1,
            preg_match('/export const MAX_BYTES = (\d+) \* 1024 \* 1024;/', $this->spa('uploadqueue.js'), $matches),
            'MAX_BYTES tidak ditemukan di uploadqueue.js dalam bentuk N * 1024 * 1024 yang dibaca uji ini.',
        );
        $this->assertSame(AttachmentService::MAX_BYTES, (int) $matches[1] * 1024 * 1024);
    }

    /**
     * Butir yang ditulis versi SEBELUM F-4 tidak punya `kind`. Tanpa pemulihan,
     * setiap foto yang sedang mengantre di ponsel orang saat rilis mendarat
     * lenyap dari layar DAN tetap memakan kuota selamanya — butir yang tidak
     * pernah masuk readQueue() tidak pernah sampai ke forget().
     */
    public function test_a_queue_item_from_the_previous_release_is_still_recognised(): void
    {
        $source = $this->spa('uploadqueue.js');

        $this->assertStringContainsString("item.kind = 'attachment';", $source);
        $this->assertStringContainsString('localStorage.removeItem(key)', $source, 'Butir dari versi LEBIH BARU harus dibuang, bukan dilewati diam-diam.');
    }

    /**
     * Batas waktu MILIK KITA di samping milik peramban: menurut spesifikasi
     * Geolocation, penghitung `timeout` baru berjalan sesudah izin diberikan,
     * jadi permintaan izin yang tidak dijawab menggantung selamanya dan butir
     * absensinya berhenti di 'locating' tanpa "Kirim ulang" maupun "Buang".
     */
    public function test_the_position_lookup_cannot_hang_forever(): void
    {
        $source = $this->spa('uploadqueue.js');

        $this->assertStringContainsString('setTimeout(() => answer(null), GEO_TIMEOUT_MS)', $source);
    }

    /**
     * Foto yang ditolak mengorbankan FOTONYA, bukan absennya. Kamera ponsel
     * modern rutin melewati 5 MB, jadi ini jalur yang sering, bukan jarang.
     */
    public function test_an_oversize_selfie_still_sends_the_punch(): void
    {
        $source = $this->spa('views/absensisaya.js');

        $this->assertStringContainsString('absensi tetap dikirim TANPA foto', $source);
        $this->assertStringNotContainsString(
            "melebihi batas 5 MB.`));\n      return;",
            $source,
            'Cabang foto kebesaran kembali membatalkan absensinya.',
        );
    }

    /**
     * Panel koreksi hanya mengirim kunci jam yang BENAR-BENAR berubah, dan
     * membaca nilai awalnya dari server (zona aplikasi), bukan dari peramban.
     */
    public function test_the_correction_panel_leaves_untouched_clocks_alone(): void
    {
        $source = $this->spa('views/absensi.js');

        $this->assertStringContainsString('clockAtOpen', $source);
        $this->assertStringContainsString('row.check_in.at_input', $source);
        $this->assertStringNotContainsString(
            'fmt.toDateTimeInput(row.check_in.at)',
            $source,
            'Kotak jam kembali dirender dengan zona peramban; pengawas WITA menggeser setiap absen satu jam.',
        );
    }

    private function spa(string $file): string
    {
        return (string) file_get_contents(public_path('app/js/'.$file));
    }

    /**
     * Berkas yang sama tanpa komentarnya.
     *
     * Sengaja konservatif: blok /* … *\/ dibuang, dan baris yang DIMULAI
     * dengan // atau * dibuang. Tidak menyentuh // di tengah baris, supaya
     * sebuah string berisi "https://…" tidak ikut terpotong dan membuat
     * larangan di atasnya lolos untuk alasan yang salah.
     */
    private function spaCode(string $file): string
    {
        $source = preg_replace('#/\*.*?\*/#s', '', $this->spa($file)) ?? '';

        return implode("\n", array_filter(
            explode("\n", $source),
            fn (string $line) => ! preg_match('#^\s*(//|\*)#', $line),
        ));
    }
}
