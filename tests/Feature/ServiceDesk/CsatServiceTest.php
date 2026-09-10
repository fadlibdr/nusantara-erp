<?php

namespace Tests\Feature\ServiceDesk;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Models\Notification;
use Modules\ServiceDesk\Enums\TicketStatus;
use Modules\ServiceDesk\Models\CsatRating;
use Modules\ServiceDesk\Services\CsatService;
use Tests\ErpTestCase;

/**
 * F-9 langkah 2 — penerbitan undangan CSAT dan pencatatan penilaiannya.
 *
 * Enam aturan CsatService diuji satu per satu, dan setiapnya dengan kasus yang
 * BERBEDA dari kasus yang lolos: sebuah uji yang hanya membuktikan jalan
 * bahagia tidak menjaga apa pun (pelajaran F-4 — empat cacat lolos dari suite
 * hijau sempurna).
 */
class CsatServiceTest extends ErpTestCase
{
    private CsatService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CsatService::class);
    }

    // ---------------------------------------------------------------- issue

    public function test_the_plaintext_token_is_returned_once_and_only_its_hash_is_stored(): void
    {
        $ticket = CsatFixtures::ticket();
        $issued = $this->service->issue(CsatFixtures::userWith(['svc.update']), $ticket, [
            'recipient_name' => 'Ibu Sinta Dewi',
            'recipient_email' => 'sinta@rsmedika.example',
        ]);

        $token = $issued['token'];

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
        $this->assertSame(url('penilaian/'.$token), $issued['url']);

        $row = CsatRating::query()->findOrFail($issued['rating']->id);

        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode($row->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertNotNull($this->service->findByToken($token));
        $this->assertNull($this->service->findByToken(str_repeat('z', 40)));
    }

    /**
     * 14 hari — angkanya LITERAL di sini, bukan dibaca dari konstanta yang
     * diuji. Uji yang menyusun harapannya dari benda yang diujinya hijau untuk
     * nilai apa pun (pelajaran F-6).
     */
    public function test_a_link_lives_fourteen_days_by_default(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 09:00:00'));

        $issued = $this->service->issue(
            CsatFixtures::userWith(['svc.update']),
            CsatFixtures::ticket(),
            ['recipient_name' => 'Ibu Sinta Dewi'],
        );

        $this->assertSame('2026-09-24 09:00:00', $issued['rating']->expires_at?->format('Y-m-d H:i:s'));

        $this->travelBack();
    }

    public function test_only_a_finished_ticket_can_be_invited_to_rate(): void
    {
        $issuer = CsatFixtures::userWith(['svc.update']);

        foreach ([TicketStatus::Resolved, TicketStatus::Closed] as $status) {
            $issued = $this->service->issue($issuer, CsatFixtures::ticket($status), ['recipient_name' => 'Ibu Sinta']);
            $this->assertNotNull($issued['token']);
        }

        foreach ([TicketStatus::Open, TicketStatus::Assigned, TicketStatus::InProgress, TicketStatus::PendingCustomer, TicketStatus::Cancelled] as $status) {
            try {
                $this->service->issue($issuer, CsatFixtures::ticket($status), ['recipient_name' => 'Ibu Sinta']);
                $this->fail("tiket {$status->value} seharusnya tidak bisa diundang menilai");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('sudah selesai', $e->getMessage());
            }
        }
    }

    /**
     * Perangkap integritas paket ini: peran `teknisi` memegang svc.update, dan
     * penerbit melihat token polos tepat sekali.
     */
    public function test_the_rated_technician_cannot_issue_the_link_for_their_own_ticket(): void
    {
        $technician = CsatFixtures::technician();
        $ticket = CsatFixtures::ticket(TicketStatus::Resolved, $technician->id);

        $himself = CsatFixtures::userWith(['svc.update'], $technician->id, 'teknisi@test.local');

        try {
            $this->service->issue($himself, $ticket, ['recipient_name' => 'Ibu Sinta']);
            $this->fail('teknisi yang dinilai tidak boleh menerbitkan tautannya sendiri');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('mengukur dirinya sendiri', $e->getMessage());
        }

        // Rekannya boleh — aturannya tentang ORANG YANG DINILAI, bukan tentang peran.
        $colleague = CsatFixtures::userWith(['svc.update'], CsatFixtures::technician('Made Wirawan')->id, 'rekan@test.local');
        $this->assertNotNull($this->service->issue($colleague, $ticket, ['recipient_name' => 'Ibu Sinta'])['token']);
    }

    // ------------------------------------------------------------ single use

    public function test_a_token_is_single_use_and_the_second_click_is_refused(): void
    {
        $ticket = CsatFixtures::ticket();
        $issued = $this->service->issue(CsatFixtures::userWith(['svc.update']), $ticket, ['recipient_name' => 'Ibu Sinta']);

        $rated = $this->service->rate($issued['token'], 5, 'Teknisinya datang cepat dan rapi.');

        $this->assertSame(5, $rated->score?->value);
        $this->assertSame('link', $rated->rated_via);
        $this->assertNotNull($rated->rated_at);

        try {
            $this->service->rate($issued['token'], 1, 'Berubah pikiran.');
            $this->fail('tautan sekali pakai harus menolak klik kedua');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah digunakan', $e->getMessage());
        }

        $fresh = $issued['rating']->fresh();
        $this->assertSame(5, $fresh->score?->value, 'penilaian pertama yang berlaku');
        $this->assertSame('Teknisinya datang cepat dan rapi.', $fresh->comment);
    }

    /** Dua UNDANGAN pada satu tiket: yang kedua tidak boleh menjadi penilaian kedua. */
    public function test_one_ticket_carries_exactly_one_rating_even_with_two_live_links(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.update']);

        $first = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);
        $second = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Bapak Rudi']);

        $this->service->rate($first['token'], 4, null);

        try {
            $this->service->rate($second['token'], 1, 'Menurut saya buruk.');
            $this->fail('tiket yang sudah dinilai tidak boleh dinilai lagi lewat tautan lain');
        } catch (LogicException $e) {
            $this->assertStringContainsString('satu tiket satu penilaian', $e->getMessage());
        }

        $this->assertSame(1, CsatRating::query()->where('ticket_id', $ticket->id)->whereNotNull('rated_at')->count());
        $this->assertNull($second['rating']->fresh()->score);
    }

    /** …dan tautan BARU pun tidak bisa diterbitkan untuk tiket yang sudah dinilai. */
    public function test_no_new_link_can_be_issued_for_a_ticket_that_is_already_rated(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.update']);

        $issued = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);
        $this->service->rate($issued['token'], 3, null);

        try {
            $this->service->issue($issuer, $ticket, ['recipient_name' => 'Bapak Rudi']);
            $this->fail('tiket yang sudah dinilai tidak menerima undangan baru');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('satu tiket satu penilaian', $e->getMessage());
        }
    }

    public function test_the_race_is_lost_on_the_locked_reread_not_on_the_stale_instance(): void
    {
        $ticket = CsatFixtures::ticket();
        $issued = $this->service->issue(CsatFixtures::userWith(['svc.update']), $ticket, ['recipient_name' => 'Ibu Sinta']);

        $stale = CsatRating::query()->findOrFail($issued['rating']->id);
        $this->service->rate($issued['token'], 5, null);
        $this->assertNull($stale->rated_at, 'salinan yang dipegang pemanggil pertama masih percaya tautannya hidup');

        try {
            $this->service->rate($issued['token'], 1, null);
            $this->fail('penilaian kedua harus ditolak pada baca ulang terkunci');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah digunakan', $e->getMessage());
        }

        $this->assertSame(5, $issued['rating']->fresh()->score?->value);
    }

    // ------------------------------------------------------- revoke & expiry

    public function test_a_revoked_link_cannot_rate_and_a_rated_link_cannot_be_revoked(): void
    {
        $issuer = CsatFixtures::userWith(['svc.update']);

        $revoked = $this->service->issue($issuer, CsatFixtures::ticket(), ['recipient_name' => 'Ibu Sinta']);
        $this->service->revoke($revoked['rating'], $issuer);

        try {
            $this->service->rate($revoked['token'], 5, null);
            $this->fail('tautan yang dicabut tidak boleh menilai');
        } catch (LogicException $e) {
            $this->assertStringContainsString('dicabut', $e->getMessage());
        }

        $used = $this->service->issue($issuer, CsatFixtures::ticket(), ['recipient_name' => 'Bapak Rudi']);
        $this->service->rate($used['token'], 2, null);

        $this->expectException(ValidationException::class);
        $this->service->revoke($used['rating'], $issuer);
    }

    public function test_expiry_is_enforced_at_the_exact_boundary(): void
    {
        $ticket = CsatFixtures::ticket();
        $issued = $this->service->issue(CsatFixtures::userWith(['svc.update']), $ticket, ['recipient_name' => 'Ibu Sinta']);

        $this->travelTo($issued['rating']->expires_at);

        try {
            $this->service->rate($issued['token'], 5, null);
            $this->fail('expires_at = sekarang harus SUDAH menolak');
        } catch (LogicException $e) {
            $this->assertStringContainsString('kedaluwarsa', $e->getMessage());
        }

        $this->travelBack();
    }

    // -------------------------------------------------------- reopened ticket

    /**
     * Tiket yang DIBUKA KEMBALI: tautannya tidak dibunuh, hanya ditidurkan.
     * Penilaian atas pekerjaan yang ternyata belum selesai menjawab pertanyaan
     * yang salah — tetapi mencabut tautannya berarti pelanggan yang sabar
     * kehilangan suaranya untuk selamanya.
     */
    public function test_a_reopened_ticket_suspends_the_link_and_finishing_it_again_revives_it(): void
    {
        $ticket = CsatFixtures::ticket(TicketStatus::Resolved);
        $issued = $this->service->issue(CsatFixtures::userWith(['svc.update']), $ticket, ['recipient_name' => 'Ibu Sinta']);

        $ticket->forceFill(['status' => TicketStatus::InProgress, 'resolved_at' => null])->save();

        try {
            $this->service->rate($issued['token'], 5, null);
            $this->fail('tiket yang dibuka kembali belum bisa dinilai');
        } catch (LogicException $e) {
            $this->assertStringContainsString('dikerjakan kembali', $e->getMessage());
            $this->assertStringContainsString('tetap berlaku', $e->getMessage());
        }

        $this->assertNull($issued['rating']->fresh()->rated_at);
        $this->assertNull($issued['rating']->fresh()->revoked_at, 'tautannya TIDAK dicabut, hanya belum bisa dipakai');

        $ticket->forceFill(['status' => TicketStatus::Resolved, 'resolved_at' => now()])->save();

        $this->assertSame(5, $this->service->rate($issued['token'], 5, null)->score?->value);
    }

    // ------------------------------------------------------------ the comment

    /**
     * Perangkap E, sisi lonceng: badan notifikasi dibaca pemegang svc.update,
     * dan komentarnya tidak boleh ikut ke sana.
     */
    public function test_the_bell_carries_the_score_but_never_the_comment(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.update']);
        $issued = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);

        $komentar = 'Teknisinya merokok di ruang server dan pergi tanpa pamit.';
        $this->service->rate($issued['token'], 1, $komentar);

        $notification = Notification::query()->where('user_id', $issuer->id)->latest('id')->first();

        $this->assertNotNull($notification, 'pemegang svc.update harus diberi tahu');
        $this->assertStringContainsString($ticket->code, $notification->title);
        $this->assertStringContainsString('1 dari 5', $notification->body);
        $this->assertStringNotContainsString($komentar, $notification->body);
        $this->assertStringNotContainsString('merokok', $notification->body);
        $this->assertSame("#/d/servicedesk/tickets/{$ticket->id}", $notification->link);

        /*
         * …DAN BUKAN NAMA KONTAK PELANGGANNYA. Aturan 6 CsatService berbunyi
         * lonceng ini membawa "SKOR dan kode tiketnya saja", dengan alasan
         * yang bisa diperiksa: badan notifikasi dibaca pemegang svc.update,
         * himpunan yang tidak dijamin sama dengan pemegang svc.view. Argumen
         * itu berlaku persis sama untuk `recipient_name`, yang hidup di baris
         * svc_csat_ratings yang SELURUHNYA bergerbang svc.view — tetapi sampai
         * assertion ini ada, hanya `comment` yang dijaga dan namanya lolos
         * tanpa siapa pun memutuskannya (terukur 10 Sep 2026: "…dari Ibu Sari
         * (PIC RS Melati)").
         */
        $this->assertStringNotContainsString('Ibu Sinta', $notification->body,
            'badan lonceng membawa nama kontak pelanggan ke gerbang yang lebih longgar');
        $this->assertStringNotContainsString('Ibu Sinta', (string) $notification->title);
    }

    public function test_an_empty_comment_is_stored_as_nothing_and_a_long_one_is_trimmed(): void
    {
        $issuer = CsatFixtures::userWith(['svc.update']);

        $blank = $this->service->issue($issuer, CsatFixtures::ticket(), ['recipient_name' => 'Ibu Sinta']);
        $this->assertNull($this->service->rate($blank['token'], 5, '   ')->comment);

        $long = $this->service->issue($issuer, CsatFixtures::ticket(), ['recipient_name' => 'Bapak Rudi']);
        $rated = $this->service->rate($long['token'], 4, str_repeat('a', 1500));

        $this->assertSame(1000, mb_strlen((string) $rated->comment));
    }

    /**
     * Tidak ada pintu kedua. Satu-satunya jalan sebuah baris mendapat rated_at
     * adalah rate(), dan ia selalu menulis 'link' — sehingga setiap rata-rata
     * bisa memisahkan penilaian pelanggan dari penilaian yang diketik orang
     * lain, kalau pintu itu kelak dibuka.
     */
    public function test_every_rating_this_system_can_write_comes_through_the_link(): void
    {
        $issuer = CsatFixtures::userWith(['svc.update']);
        $issued = $this->service->issue($issuer, CsatFixtures::ticket(), ['recipient_name' => 'Ibu Sinta']);
        $this->service->rate($issued['token'], 3, null);

        $this->assertSame(
            ['link'],
            CsatRating::query()->whereNotNull('rated_at')->pluck('rated_via')->unique()->values()->all(),
        );

        $source = file_get_contents(base_path('Modules/ServiceDesk/Services/CsatService.php'));
        $this->assertSame(
            1,
            substr_count((string) $source, "'rated_at' => now()"),
            'hanya SATU tempat di service ini yang boleh menstempel rated_at',
        );
    }
}
