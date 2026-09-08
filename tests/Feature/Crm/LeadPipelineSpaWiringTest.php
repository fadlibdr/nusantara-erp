<?php

namespace Tests\Feature\Crm;

use Tests\ErpTestCase;

/**
 * Papan pipeline di SPA (F-3 / T3.5 & T3.6) — empat janji yang bisa dilanggar
 * tanpa satu galat pun di layar.
 *
 *  1. PERPINDAHAN LEWAT `runAction`, pintu yang sama dengan tombol dokumen
 *     (aturan P1-G). Yang bisa dipaku uji teks adalah bahwa keenam aksi papan
 *     menunjuk endpoint tahap dan membawa tahap tujuannya di `body`.
 *  2. KOLOM MENANG/KALAH DIPETAKAN. Sebuah papan yang tidak memetakannya akan
 *     menolak dengan kalimat generiknya sendiri ("tidak ada aksi yang
 *     memindahkan dokumen ke kolom itu") — terdengar seperti aplikasi rusak,
 *     bukan seperti aturan bisnis. Yang dipetakan menghasilkan penolakan
 *     SERVER, yang menyebut penawaran mana yang harus ditandai.
 *  3. AKSI PAPAN TIDAK MUNCUL DI BILAH DOKUMEN (`boardOnly`), termasuk dua yang
 *     memang selalu ditolak.
 *  4. KARTUNYA MENYEBUT PEMILIK, dan formulir prospek tidak lagi memuat isian
 *     status maupun tanggal tindak lanjut — keduanya ditolak server, jadi
 *     isian yang tertinggal akan menggagalkan setiap Simpan.
 */
class LeadPipelineSpaWiringTest extends ErpTestCase
{
    private function schema(): string
    {
        return (string) file_get_contents(public_path('app/js/schema.js'));
    }

    private function leadBlock(): string
    {
        $source = $this->schema();
        $at = strpos($source, "  'crm/leads': {");
        $this->assertNotFalse($at, 'entri crm/leads tidak terbaca di schema.js');

        return substr($source, $at, (int) strpos($source, "\n  },", $at) - $at);
    }

    public function test_every_lane_has_a_move_action_carrying_its_status(): void
    {
        $block = $this->leadBlock();

        foreach (['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'] as $status) {
            $this->assertStringContainsString("key: 'to-{$status}'", $block,
                "papan pipeline tidak punya aksi untuk kolom {$status}");
            $this->assertStringContainsString("body: { status: '{$status}' }", $block,
                "aksi kolom {$status} tidak membawa tahap tujuannya");
        }

        $this->assertStringContainsString("api: 'crm/pipeline/board'", $block,
            'papan tidak membaca rute papannya sendiri — satu halaman daftar akan mengosongkan kolom yang paling dikerjakan');
        $this->assertStringContainsString("card: { fields: ['owner_user_name', 'activity_note'] }", $block,
            'kartu papan tidak menyebut pemiliknya');
    }

    /** Semua aksi perpindahan menunjuk endpoint tahap — bukan PUT status. */
    public function test_the_moves_point_at_the_pipeline_endpoint(): void
    {
        $block = $this->leadBlock();

        $this->assertSame(7, substr_count($block, "path: '{id}/pipeline'"),
            'enam perpindahan papan + satu tombol "Ubah Tahap" — semuanya lewat satu pintu');
        /* Isian tahap hanya boleh muncul saat MEMBUAT: PUT prospek menolak
           `status` (422), jadi sebuah isian yang ikut terkirim pada Ubah akan
           menggagalkan setiap Simpan — dan pilihannya tanpa Menang/Kalah. */
        $this->assertStringContainsString("key: 'status', label: 'Tahap awal', type: 'select', default: 'new', createOnly: true", $block);
        $this->assertStringNotContainsString("enum: 'leadStatus', default: 'new'", $block,
            'pemilih tahap awal masih menawarkan Menang/Kalah, yang ditolak server');
        $this->assertStringNotContainsString("{ key: 'next_follow_up_at', label: 'Follow-up berikutnya'", $block,
            'formulir prospek masih punya isian tanggal tindak lanjut, yang kini turunan dan ditolak server');
    }

    /** Aksi papan tidak boleh muncul sebagai tombol di layar dokumen. */
    public function test_board_only_actions_stay_off_the_action_bar(): void
    {
        $block = $this->leadBlock();
        $this->assertSame(6, substr_count($block, 'boardOnly: true'));

        $actions = (string) file_get_contents(public_path('app/js/views/actions.js'));
        $this->assertStringContainsString('.filter((action) => !action.boardOnly)', $actions,
            'actionButtons tidak menyaring aksi khusus papan — enam tombol "Pindahkan ke …" akan muncul di bilah aksi');
        $this->assertStringContainsString('let payload = { ...(action.body || {}) };', $actions,
            'runAction tidak lagi mengirim muatan tetap aksinya — setiap seretan papan akan POST tanpa tahap tujuan');
    }

    /** Dialog alasan mundur SATU deklarasi untuk semua permukaan. */
    public function test_one_backward_reason_dialog_serves_every_surface(): void
    {
        $source = $this->schema();

        $this->assertStringContainsString('const ALASAN_MUNDUR = [{', $source);
        // Tombol dokumen + enam perpindahan papan.
        $this->assertSame(7, substr_count($this->leadBlock(), 'confirmResubmit: ALASAN_MUNDUR'));
        $this->assertStringContainsString('test: /^reason$/', $source,
            'mesin confirm-resubmit tidak lagi mengenali kunci galat `reason` — penolakan mundur menjadi jalan buntu');
    }

    /** Papan membaca sumbernya dari `board.api` bila ada. */
    public function test_the_board_reads_its_own_route(): void
    {
        $board = (string) file_get_contents(public_path('app/js/views/board.js'));

        $this->assertStringContainsString('api.list(board.api || def.api', $board);
        $this->assertStringContainsString('meta.lanes', $board);
        $this->assertStringContainsString('digambar', $board,
            'papan tidak mengatakan berapa kartu yang TIDAK digambar');
    }

    /** Riwayat tahap punya kartunya sendiri, bukan badge JSON di panel Informasi. */
    public function test_the_stage_history_has_its_own_card(): void
    {
        $this->assertStringContainsString("key: 'status_history', label: 'Riwayat Tahap'", $this->leadBlock());
        $this->assertStringContainsString("'status_history',", (string) file_get_contents(public_path('app/js/views/detail.js')));
    }

    /** Rute papan terdaftar di navigasi Penjualan. */
    public function test_the_board_is_reachable_from_the_menu(): void
    {
        $this->assertStringContainsString("{ label: 'Papan Pipeline', route: 'b/crm/leads' },", $this->schema());
    }

    /**
     * TOAST PERPINDAHAN MENYEBUT PROSPEKNYA.
     *
     * actions.js jatuh ke "`${action.label} berhasil.`" untuk kunci yang tidak
     * punya bentuk lampau di PAST, dan tidak satu pun aksi pipeline ada di
     * sana. Terukur di S30 (hasil yang dikomit maupun putaran verifikasi):
     * after_backward.toasts = ["Pindahkan ke Baru berhasil."] — pada papan
     * berisi 31 kartu di satu kolom, kalimat itu tidak mengatakan prospek MANA
     * yang berpindah, sementara server sudah mengirim "LEAD-0003 dipindahkan ke
     * tahap Baru." yang tidak pernah sampai ke layar. Ketujuh aksinya (tombol
     * dokumen + enam kolom) memakai satu kalimat, dan tahapnya dibaca dari
     * jawaban server.
     */
    public function test_every_stage_move_announces_the_lead_and_its_new_stage(): void
    {
        $source = $this->schema();

        $this->assertStringContainsString('const TOAST_TAHAP = (code, result)', $source);
        $this->assertStringContainsString('result.status_label', $source,
            'tahap tujuan ditebak dari tombol, bukan dibaca dari jawaban server');
        $this->assertSame(7, substr_count($this->leadBlock(), 'toast: TOAST_TAHAP'),
            'ada aksi tahap yang masih berbunyi "<label> berhasil." tanpa menyebut prospeknya');

        $actions = (string) file_get_contents(public_path('app/js/views/actions.js'));
        $this->assertStringContainsString('action.toast ? action.toast(code, result)', $actions,
            'kait toast per-aksi hilang dari runAction — kalimat di schema.js tidak akan pernah dipakai');
    }
}
