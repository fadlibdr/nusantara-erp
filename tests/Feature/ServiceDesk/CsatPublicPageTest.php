<?php

namespace Tests\Feature\ServiceDesk;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\ServiceDesk\Enums\TicketStatus;
use Modules\ServiceDesk\Models\CsatRating;
use Modules\ServiceDesk\Models\Ticket;
use Modules\ServiceDesk\Services\CsatService;
use Tests\ErpTestCase;

/**
 * F-9 langkah 3 — halaman /penilaian/{token}, sisi PUBLIK (tanpa login).
 *
 * Halaman publik adalah permukaan serangan, jadi yang diuji di sini bukan
 * "halamannya tampil" melainkan APA YANG BISA DIPELAJARI orang dari jawabannya:
 *
 *  - tanpa token yang sah hanya ADA SATU jawaban, dan ia byte-identik untuk
 *    setiap tebakan (pelajaran AttachmentController::reachable — dua jawaban
 *    yang berbeda adalah orakel enumerasi);
 *  - dengan token yang sah, sebabnya memang dibedakan (pelanggan yang kita
 *    undang berhak tahu tautannya kedaluwarsa) tetapi ISInya sekurang mungkin:
 *    halaman mati tidak pernah membawa judul tiket, nama penerima, nama
 *    teknisi, atau skor milik tautan lain;
 *  - rutenya berpagar throttle:10,1 dan regex token yang sama ketatnya dengan
 *    preseden /persetujuan/{token}, dan TIDAK berada di grup 'web'.
 */
class CsatPublicPageTest extends ErpTestCase
{
    private CsatService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CsatService::class);
    }

    /** @return array{0: CsatRating, 1: string, 2: Ticket} */
    private function invite(?Ticket $ticket = null, ?User $by = null, array $data = []): array
    {
        $ticket ??= CsatFixtures::ticket();
        $issued = $this->service->issue(
            $by ?? CsatFixtures::userWith(['svc.update']),
            $ticket,
            $data + ['recipient_name' => 'Ibu Sinta Dewi'],
        );

        return [$issued['rating'], $issued['token'], $ticket];
    }

    // -------------------------------------------------------------- the form

    public function test_a_live_token_shows_the_ticket_and_five_scores(): void
    {
        [, $token, $ticket] = $this->invite();

        $page = $this->get("/penilaian/{$token}")->assertOk();

        $page->assertSee($ticket->code);
        $page->assertSee($ticket->title);
        $page->assertSee('Sangat tidak puas');
        $page->assertSee('Tidak puas');
        $page->assertSee('Cukup');
        $page->assertSee('Puas');
        $page->assertSee('Sangat puas');

        // Lima tombol, satu per skor.
        $this->assertSame(5, substr_count($page->getContent(), 'name="score"'));
    }

    /**
     * Yang TIDAK boleh ada di halaman publik: nama teknisi, deskripsi masalah,
     * catatan penyelesaian, nama pelapor. Pelanggannya sudah tahu siapa yang
     * datang; nama karyawan pada halaman yang bisa diteruskan siapa saja tidak
     * membeli apa pun.
     */
    public function test_the_public_form_never_names_the_technician_or_repeats_the_case_notes(): void
    {
        $technician = CsatFixtures::technician('Joko Susilo');
        $ticket = CsatFixtures::ticket(TicketStatus::Resolved, $technician->id);
        $ticket->forceFill([
            'resolution_notes' => 'Ganti power supply kamera, garansi tiga bulan.',
            'reported_by_name' => 'Satpam Rudi',
        ])->save();

        [, $token] = $this->invite($ticket);

        $body = $this->get("/penilaian/{$token}")->assertOk()->getContent();

        $this->assertStringNotContainsString('Joko Susilo', $body);
        $this->assertStringNotContainsString('Ganti power supply', $body);
        $this->assertStringNotContainsString('Satpam Rudi', $body);
        $this->assertStringNotContainsString('Kamera lobi utama tidak menyala', $body);
    }

    /** Tidak boleh ada janji surel: sistem ini tidak mengirim satu pun. */
    public function test_the_public_page_never_promises_an_email(): void
    {
        [, $token] = $this->invite();

        $body = mb_strtolower($this->get("/penilaian/{$token}")->getContent());

        foreach (['e-mail', 'email', 'surel', 'dikirim ke', 'inbox'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "halaman publik menyebut \"{$needle}\"");
        }
    }

    // ------------------------------------------------------------- unknown

    /**
     * PERANGKAP F. Tanpa token yang sah, setiap tebakan harus mendapat halaman
     * yang SAMA PERSIS — bukan hanya kode status yang sama.
     */
    public function test_every_unknown_token_gets_one_identical_page_that_leaks_nothing(): void
    {
        [, , $ticket] = $this->invite();

        $first = $this->get('/penilaian/'.str_repeat('z', 40));
        $second = $this->get('/penilaian/'.str_repeat('q', 24).'-abc');

        $first->assertNotFound();
        $second->assertNotFound();

        $this->assertSame($first->getContent(), $second->getContent(),
            'dua tebakan berbeda harus menghasilkan byte yang sama');

        $this->assertStringNotContainsString($ticket->code, $first->getContent());
        $this->assertStringNotContainsString($ticket->title, $first->getContent());
        $this->assertStringNotContainsString('Ibu Sinta', $first->getContent());

        // POST juga, bukan hanya GET: sebuah 422 "pilih bintang" pada token tak
        // dikenal akan mengaku bahwa tokennya ada.
        $post = $this->post('/penilaian/'.str_repeat('z', 40), ['score' => 5]);
        $post->assertNotFound();
        $this->assertSame($first->getContent(), $post->getContent());
    }

    public function test_a_token_that_is_not_token_shaped_never_reaches_the_controller(): void
    {
        $this->get('/penilaian/pendek')->assertNotFound();
        $this->get('/penilaian/'.str_repeat('z', 65))->assertNotFound();
        $this->get('/penilaian/token_dengan_garis_bawah_yang_panjang')->assertNotFound();
    }

    // ------------------------------------------------------------ rating it

    public function test_each_score_is_recorded_and_answered_with_a_receipt(): void
    {
        foreach ([1 => 'Sangat tidak puas', 3 => 'Cukup', 5 => 'Sangat puas'] as $score => $label) {
            [$row, $token] = $this->invite();

            $answer = $this->post("/penilaian/{$token}", [
                'score' => $score,
                'comment' => 'Catatan pelanggan untuk skor '.$score,
            ])->assertOk();

            $row->refresh();
            $this->assertSame($score, $row->score?->value);
            $this->assertSame('link', $row->rated_via);
            $this->assertNotNull($row->rated_at);

            $answer->assertSee($label);
            $answer->assertSee("{$score} dari 5");
            $answer->assertSee('Catatan pelanggan untuk skor '.$score);
        }
    }

    public function test_a_post_without_a_score_returns_the_form_with_an_error_and_records_nothing(): void
    {
        [$row, $token] = $this->invite();

        $answer = $this->post("/penilaian/{$token}", ['comment' => 'Lupa memilih bintang.']);

        $answer->assertStatus(422);
        $answer->assertSee('Pilih dulu salah satu bintang');
        $this->assertSame(5, substr_count($answer->getContent(), 'name="score"'));
        $this->assertNull($row->fresh()->rated_at);
    }

    /**
     * KOMENTAR YANG BUKAN TEKS ditolak di pintu yang sama dengan skornya.
     *
     * `comment[]=a` — dari pemindai, atau dari klien yang rusak — pernah
     * menjadi `(string) [...]` "Array to string conversion" dan halaman
     * "500 Server Error" berbahasa Inggris di satu-satunya layar yang pernah
     * dilihat pelanggan, dengan penilaiannya TIDAK tercatat. Penjagaan ketat
     * yang sudah diberikan kepada `score` diberikan juga kepada tetangganya
     * di baris yang sama.
     */
    public function test_a_comment_that_is_not_a_string_is_refused_instead_of_crashing(): void
    {
        [$row, $token] = $this->invite();

        $answer = $this->post("/penilaian/{$token}", ['score' => 5, 'comment' => ['a', 'b']]);

        $answer->assertStatus(422);
        $answer->assertSee('Komentar Anda tidak terbaca');
        $this->assertSame(5, substr_count($answer->getContent(), 'name="score"'),
            'formulirnya kembali utuh, bukan halaman galat');
        $this->assertNull($row->fresh()->rated_at, 'kiriman yang tidak terbaca tidak mencatat apa pun');
        $this->assertNull($row->fresh()->score);
    }

    /**
     * …dan pada tautan yang SUDAH dipakai, kiriman yang tidak terbaca tetap
     * dijawab struknya — keadaan baris menang atas "isian Anda salah", persis
     * seperti pada POST tanpa skor.
     */
    public function test_an_unreadable_comment_on_a_used_link_is_still_a_receipt(): void
    {
        [$row, $token] = $this->invite();

        $this->post("/penilaian/{$token}", ['score' => 5, 'comment' => 'Cepat.'])->assertOk();

        $answer = $this->post("/penilaian/{$token}", ['score' => 1, 'comment' => ['a']])->assertOk();

        $answer->assertDontSee('name="score"', false);
        $answer->assertSee('5 dari 5');
        $this->assertSame('Cepat.', $row->fresh()->comment);
    }

    /** Skor di luar 1..5 tidak menjadi penilaian dan tidak menjadi 500. */
    public function test_a_score_outside_the_scale_is_refused(): void
    {
        foreach (['0', '6', '-1', 'lima', '4.5'] as $bogus) {
            [$row, $token] = $this->invite();

            $this->post("/penilaian/{$token}", ['score' => $bogus])->assertStatus(422);
            $this->assertNull($row->fresh()->rated_at, "skor {$bogus} seharusnya ditolak");
        }
    }

    public function test_the_second_click_sees_its_own_receipt_never_the_form_again(): void
    {
        [$row, $token] = $this->invite();

        $this->post("/penilaian/{$token}", ['score' => 5, 'comment' => 'Cepat sekali.'])->assertOk();
        $ratedAt = $row->refresh()->rated_at;

        $second = $this->post("/penilaian/{$token}", ['score' => 1, 'comment' => 'Berubah pikiran.'])->assertOk();

        $row->refresh();
        $this->assertSame(5, $row->score?->value, 'penilaian pertama yang berlaku');
        $this->assertEquals($ratedAt, $row->rated_at);
        $this->assertSame('Cepat sekali.', $row->comment);

        $second->assertSee('5 dari 5');
        $second->assertDontSee('name="score"', false);

        $this->get("/penilaian/{$token}")->assertOk()->assertDontSee('name="score"', false);
    }

    /**
     * POST TANPA SKOR pada tautan yang SUDAH dipakai harus tetap struk.
     *
     * Cabang "pilih dulu bintangnya" adalah jalan masuk kedua ke halaman ini,
     * dan ia punya daftar keadaan matinya sendiri. Sampai uji ini ada, daftar
     * itu memeriksa dicabut/kedaluwarsa/dinilai-lewat-tautan-lain tetapi TIDAK
     * memeriksa "tautan ini sendiri sudah dipakai" — sehingga satu POST kosong
     * mengembalikan FORMULIR berikut lima tombolnya pada tautan terpakai,
     * membatalkan janji "tidak pernah formulir lagi" lewat pintu belakang.
     */
    public function test_a_post_without_a_score_on_a_used_link_is_still_a_receipt(): void
    {
        [$row, $token] = $this->invite();

        $this->post("/penilaian/{$token}", ['score' => 5, 'comment' => 'Cepat.'])->assertOk();

        $answer = $this->post("/penilaian/{$token}", ['comment' => 'Lupa memilih.'])->assertOk();

        $answer->assertDontSee('name="score"', false);
        $answer->assertSee('5 dari 5');
        $this->assertSame('Cepat.', $row->fresh()->comment, 'komentar pertama tidak boleh tertimpa');
    }

    /**
     * Tautan untuk tiket yang DIBUKA KEMBALI SESUDAH DITUTUP: pertanyaannya
     * dijawab enum, bukan halaman ini. `TicketStatus::Closed` tidak punya satu
     * transisi keluar pun, jadi satu-satunya pembukaan kembali yang bisa
     * terjadi hari ini adalah dari `Resolved` — dan itulah yang ditangani uji
     * di atas. Paku ini ada supaya penambahan `Closed => [InProgress]` kelak
     * memerahkan uji CSAT, bukan diam-diam menghidupkan jalur yang belum
     * pernah dipikirkan.
     */
    public function test_a_closed_ticket_has_no_way_back_which_is_why_only_resolved_can_reopen(): void
    {
        $this->assertSame([], TicketStatus::Closed->allowedTransitions());
        $this->assertSame([], TicketStatus::Cancelled->allowedTransitions());

        $this->assertContains(TicketStatus::InProgress, TicketStatus::Resolved->allowedTransitions());
    }

    /**
     * STRUK UNTUK TIKET YANG SUDAH DIHAPUS tetap menyebut nomor tiketnya.
     *
     * stateFor() memeriksa isRated() SEBELUM terminalFor(), dan hanya
     * terminalFor() yang memeriksa apakah tiketnya masih ada — jadi sampai
     * baris ini ada, struk untuk tiket yang dihapus-lunak berkepala
     * "Tiket —" lalu menutup dengan "hubungi kami dan sebutkan nomor tiket
     * di atas": halaman yang menyuruh pelanggan menyebutkan nomor yang baru
     * saja ia tolak cetak (terukur 10 Sep 2026). Pemegang tautan ini SUDAH
     * pernah melihat kode itu — ia yang menilainya — jadi mencetaknya kembali
     * tidak membuka apa pun yang belum ia punya.
     */
    public function test_a_receipt_for_a_deleted_ticket_does_not_ask_for_a_number_it_hides(): void
    {
        [, $token, $ticket] = $this->invite();

        $this->post("/penilaian/{$token}", ['score' => 4, 'comment' => 'Bagus, rapi.'])->assertOk();

        $ticket->delete();

        $page = $this->get("/penilaian/{$token}")->assertOk();

        $page->assertSee($ticket->code);
        $page->assertSee('sebutkan nomor tiket di atas');
        $this->assertStringNotContainsString('>—<', $page->getContent(), 'kepalanya tidak boleh "Tiket —"');

        // …dan tetap tidak membawa apa pun yang bukan milik pemegangnya.
        $this->assertStringNotContainsString($ticket->title, $page->getContent());
    }

    /**
     * Asimetrinya DISENGAJA: hanya struk yang membaca tiket terhapus.
     * Pemegang tautan yang belum menilai belum pernah melihat apa pun, jadi
     * tautannya tetap dijawab 410 tanpa kode tiketnya.
     */
    public function test_an_unrated_link_on_a_deleted_ticket_is_still_a_bare_410(): void
    {
        $ticket = CsatFixtures::ticket();
        [, $token] = $this->invite($ticket);

        $ticket->delete();

        $page = $this->get("/penilaian/{$token}")->assertStatus(410);

        $page->assertSee('sudah tidak ada di sistem');
        $this->assertStringNotContainsString($ticket->code, $page->getContent());
        $this->assertStringNotContainsString($ticket->title, $page->getContent());
    }

    /**
     * STEMPEL STRUK MEMBACA AMBANGNYA DARI ENUM, tidak menulis ulang `>= 4`.
     *
     * Sampai baris ini ada, blade-nya berbunyi `$nilai >= 4 ? 'baik' : ...` —
     * jadi menggeser `CsatScore::isSatisfied()` (kenop yang §9 perlakukan
     * sebagai bisa dibalik) mengubah ubin "Puas" di ringkasan sementara
     * stempel pelanggan tetap memakai ambang lama: satu penilaian 3 bintang
     * "puas" di ringkasan dan oranye "cukup" di halaman pelanggannya, dengan
     * suite tetap hijau.
     *
     * Kelasnya dipaku LITERAL: 5 dan 4 baik, 3 cukup, 2 dan 1 buruk.
     */
    public function test_the_receipt_stamp_reads_the_satisfied_threshold_from_the_enum(): void
    {
        foreach ([5 => 'baik', 4 => 'baik', 3 => 'cukup', 2 => 'buruk', 1 => 'buruk'] as $score => $kelas) {
            [, $token] = $this->invite();

            $body = $this->post("/penilaian/{$token}", ['score' => $score])->assertOk()->getContent();

            $this->assertStringContainsString('class="stempel '.$kelas.'"', $body,
                "skor {$score} seharusnya berstempel {$kelas}");
        }
    }

    // ------------------------------------------------------------- terminals

    public function test_a_revoked_link_is_gone_and_leaks_neither_the_recipient_nor_the_title(): void
    {
        $issuer = CsatFixtures::userWith(['svc.update']);
        [$row, $token, $ticket] = $this->invite(null, $issuer);

        $this->service->revoke($row, $issuer);

        $page = $this->get("/penilaian/{$token}")->assertStatus(410);

        $page->assertSee($ticket->code);
        $page->assertSee('dicabut');
        $this->assertStringNotContainsString('Ibu Sinta', $page->getContent());
        $this->assertStringNotContainsString($ticket->title, $page->getContent());
        $this->assertStringNotContainsString('name="score"', $page->getContent());
    }

    public function test_an_expired_link_is_gone_at_the_exact_boundary(): void
    {
        [$row, $token] = $this->invite();

        $this->travelTo($row->expires_at);

        $this->get("/penilaian/{$token}")->assertStatus(410)->assertSee('kedaluwarsa');
        $this->post("/penilaian/{$token}", ['score' => 5])->assertStatus(410);

        $this->assertNull($row->fresh()->rated_at, 'expires_at = sekarang harus SUDAH menolak');

        $this->travelBack();
    }

    /**
     * Undangan KEDUA pada tiket yang sudah dinilai: halaman mati yang TIDAK
     * menunjukkan skor orang lain. Pemegang tautan ini bukan yang menulisnya.
     */
    public function test_a_second_invitation_never_shows_the_first_persons_score(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.update']);

        [, $first] = $this->invite($ticket, $issuer);
        [, $second] = $this->invite($ticket, $issuer, ['recipient_name' => 'Bapak Rudi']);

        $this->post("/penilaian/{$first}", ['score' => 1, 'comment' => 'Teknisinya tidak sopan.'])->assertOk();

        $page = $this->get("/penilaian/{$second}")->assertStatus(410);

        $page->assertSee('sudah dinilai lewat tautan lain');
        $this->assertStringNotContainsString('1 dari 5', $page->getContent());
        $this->assertStringNotContainsString('Sangat tidak puas', $page->getContent());
        $this->assertStringNotContainsString('tidak sopan', $page->getContent());
        $this->assertStringNotContainsString('name="score"', $page->getContent());

        $this->post("/penilaian/{$second}", ['score' => 5])->assertStatus(410);
        $this->assertSame(1, CsatRating::query()->whereNotNull('rated_at')->count());
    }

    /** Tiket yang dibuka kembali: 409, dan kalimatnya menjanjikan tautannya kembali. */
    public function test_a_reopened_ticket_answers_409_and_says_the_link_still_holds(): void
    {
        $ticket = CsatFixtures::ticket(TicketStatus::Resolved);
        [$row, $token] = $this->invite($ticket);

        $ticket->forceFill(['status' => TicketStatus::InProgress, 'resolved_at' => null])->save();

        $page = $this->get("/penilaian/{$token}")->assertStatus(409);
        $page->assertSee('dikerjakan kembali');
        $page->assertSee('tetap berlaku');
        $this->assertStringNotContainsString('name="score"', $page->getContent());

        $this->post("/penilaian/{$token}", ['score' => 5])->assertStatus(409);
        $this->assertNull($row->fresh()->rated_at);
        $this->assertNull($row->fresh()->revoked_at, 'tautannya tidak dicabut');

        $ticket->forceFill(['status' => TicketStatus::Resolved, 'resolved_at' => now()])->save();

        $this->get("/penilaian/{$token}")->assertOk()->assertSee('name="score"', false);
    }

    // --------------------------------------------------------------- routes

    public function test_both_public_routes_are_throttled_and_shaped_like_the_approval_precedent(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $route = collect(Route::getRoutes())->first(
                fn ($r) => $r->uri() === 'penilaian/{token}' && in_array($method, $r->methods(), true),
            );

            $this->assertNotNull($route, "rute {$method} penilaian/{token} harus ada");
            $this->assertContains('throttle:10,1', $route->middleware());
            $this->assertSame('[A-Za-z0-9-]{20,64}', $route->wheres['token'] ?? null);

            // TANPA grup 'web': tidak ada sesi/cookie/CSRF di halaman tanpa identitas.
            $this->assertNotContains('web', $route->middleware());
            $this->assertNotContains('auth:sanctum', $route->middleware());
        }
    }

    /**
     * Halaman ini tidak boleh membawa satu byte pun aset eksternal: pelanggan
     * membukanya dari ponsel dengan sinyal seadanya, dan aset yang gagal dimuat
     * adalah halaman yang tidak bisa dipakai. Aturan yang sama dengan halaman
     * persetujuan eksternal.
     */
    public function test_the_page_pulls_no_external_asset_and_runs_no_script(): void
    {
        [, $token] = $this->invite();

        $body = $this->get("/penilaian/{$token}")->getContent();

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('<link', $body);
        $this->assertStringNotContainsString('@import', $body);
        $this->assertStringNotContainsString('src=', $body);

        /*
         * Satu-satunya http(s) yang boleh ada adalah action formulirnya
         * sendiri, dan ia harus menunjuk asal aplikasi ini — bukan host lain.
         * Dibuang dulu, lalu sisanya harus BERSIH: sebuah aturan yang hanya
         * menghitung "berapa banyak http" akan lolos begitu yang kedua muncul.
         */
        $action = url('penilaian/'.$token);
        $this->assertStringContainsString('action="'.$action.'"', $body);

        $sisa = str_replace($action, '', $body);
        $this->assertStringNotContainsString('http://', $sisa);
        $this->assertStringNotContainsString('https://', $sisa);
        $this->assertStringNotContainsString('//', str_replace('<!DOCTYPE', '', $sisa));
    }
}
