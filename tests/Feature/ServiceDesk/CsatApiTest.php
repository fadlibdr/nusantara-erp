<?php

namespace Tests\Feature\ServiceDesk;

use Modules\ServiceDesk\Enums\TicketStatus;
use Modules\ServiceDesk\Models\CsatRating;
use Modules\ServiceDesk\Services\CsatService;
use Tests\ErpTestCase;

/**
 * F-9 langkah 5 — sisi ber-sesi: menerbitkan, mencabut, membaca, meringkas.
 */
class CsatApiTest extends ErpTestCase
{
    private CsatService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CsatService::class);
    }

    public function test_issuing_returns_the_plaintext_url_exactly_once(): void
    {
        $ticket = CsatFixtures::ticket();
        $user = CsatFixtures::userWith(['svc.view', 'svc.update']);

        $response = $this->actingAs($user)
            ->postJson("/api/servicedesk/tickets/{$ticket->id}/csat", [
                'recipient_name' => 'Ibu Sinta Dewi',
                'recipient_email' => 'sinta@rsmedika.example',
            ])
            ->assertCreated();

        $url = $response->json('data.url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/penilaian/', $url);

        $token = substr($url, strrpos($url, '/') + 1);
        $this->assertNotNull($this->service->findByToken($token));

        // …dan tidak ada jalan membacanya lagi: tidak di daftar, tidak di
        // baris mana pun yang menyeberangi kawat.
        $list = $this->actingAs($user)->getJson("/api/servicedesk/tickets/{$ticket->id}/csat")->assertOk();

        $this->assertStringNotContainsString($token, $list->getContent());
        $this->assertStringNotContainsString(hash('sha256', $token), $list->getContent());
        $this->assertArrayNotHasKey('token_hash', $list->json('data.0'));
    }

    public function test_the_list_says_why_no_link_can_be_issued(): void
    {
        $ticket = CsatFixtures::ticket(TicketStatus::InProgress);
        $user = CsatFixtures::userWith(['svc.view', 'svc.update']);

        $meta = $this->actingAs($user)
            ->getJson("/api/servicedesk/tickets/{$ticket->id}/csat")
            ->assertOk()
            ->json('meta');

        $this->assertFalse($meta['ratable']);
        $this->assertSame('in_progress', $meta['ticket_status']);
        $this->assertSame(['resolved', 'closed'], $meta['ratable_statuses']);
        $this->assertFalse($meta['already_rated']);
    }

    /**
     * MASA BERLAKUNYA DIKIRIM SERVER, tidak dipegang dua pihak.
     *
     * Dialog penerbitan menulis "Kosongkan untuk N hari" dan medan `days`-nya
     * berbatas; sampai meta ini ada, kedua angka itu adalah salinan yang
     * dipegang `views/csat.js` sendiri — mengubah `DEFAULT_VALIDITY_DAYS`
     * memerahkan TEPAT SATU uji dan membiarkan dialognya berbohong dengan
     * suite tetap hijau (terukur 10 Sep 2026).
     *
     * Angkanya LITERAL di sini, bukan dibaca dari konstanta yang diuji.
     */
    public function test_the_list_meta_hands_the_screen_the_validity_window(): void
    {
        $ticket = CsatFixtures::ticket();

        $meta = $this->actingAs(CsatFixtures::userWith(['svc.view', 'svc.update']))
            ->getJson("/api/servicedesk/tickets/{$ticket->id}/csat")
            ->assertOk()
            ->json('meta');

        $this->assertSame(14, $meta['default_validity_days']);
    }

    /**
     * MASA BERLAKU PUNYA PLAFON, bukan hanya lantai.
     *
     * Dari empat sifat yang dijanjikan tautan ini — di-hash, tampil sekali,
     * KEDALUWARSA, bisa dicabut — yang ketiga bisa dilucuti dari luar lewat
     * satu medan formulir selama aturannya hanya `after:now`: terukur 10 Sep
     * 2026, `expires_at = 9999-12-31` diterima HTTP 201 dan kartu tiketnya
     * menulis "berlaku s/d 31 Des 9999".
     *
     * Angkanya LITERAL di sini (90 hari), bukan dibaca dari konstantanya.
     */
    public function test_a_link_cannot_be_issued_to_outlive_the_work_it_asks_about(): void
    {
        $ticket = CsatFixtures::ticket();
        $user = CsatFixtures::userWith(['svc.view', 'svc.update']);

        foreach (['9999-12-31 23:59:00', '2099-01-01 00:00:00', now()->addDays(120)->format('Y-m-d H:i:s')] as $abadi) {
            $this->actingAs($user)
                ->postJson("/api/servicedesk/tickets/{$ticket->id}/csat", [
                    'recipient_name' => 'Abadi',
                    'expires_at' => $abadi,
                ])
                ->assertStatus(422)
                ->assertJsonPath('errors.expires_at.0', fn ($m) => str_contains((string) $m, '90 hari'));
        }

        $this->assertSame(0, CsatRating::query()->count(), 'tidak satu undangan pun terbit');

        // …dan yang di dalam plafonnya tetap terbit.
        $this->actingAs($user)
            ->postJson("/api/servicedesk/tickets/{$ticket->id}/csat", [
                'recipient_name' => 'Ibu Sinta Dewi',
                'expires_at' => now()->addDays(80)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated();
    }

    /** Plafonnya juga dikirim ke layar, supaya medannya berbatas di dua sisi. */
    public function test_the_list_meta_hands_the_screen_the_validity_ceiling(): void
    {
        $ticket = CsatFixtures::ticket();

        $meta = $this->actingAs(CsatFixtures::userWith(['svc.view', 'svc.update']))
            ->getJson("/api/servicedesk/tickets/{$ticket->id}/csat")
            ->assertOk()
            ->json('meta');

        $this->assertSame(90, $meta['max_validity_days']);
    }

    public function test_issuing_for_an_unfinished_ticket_is_refused_with_a_sentence(): void
    {
        $ticket = CsatFixtures::ticket(TicketStatus::InProgress);

        $this->actingAs(CsatFixtures::userWith(['svc.view', 'svc.update']))
            ->postJson("/api/servicedesk/tickets/{$ticket->id}/csat", ['recipient_name' => 'Ibu Sinta'])
            ->assertStatus(422)
            ->assertJsonPath('errors.ticket_id.0', fn ($m) => str_contains((string) $m, 'sudah selesai'));
    }

    public function test_revoking_needs_update_and_a_rated_link_cannot_be_revoked(): void
    {
        $user = CsatFixtures::userWith(['svc.view', 'svc.update']);
        $issued = $this->service->issue($user, CsatFixtures::ticket(), ['recipient_name' => 'Ibu Sinta']);

        $this->actingAs(CsatFixtures::userWith(['svc.view'], null, 'pembaca@test.local'))
            ->postJson("/api/servicedesk/csat/{$issued['rating']->id}/revoke")
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson("/api/servicedesk/csat/{$issued['rating']->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.state', 'revoked');

        $used = $this->service->issue($user, CsatFixtures::ticket(), ['recipient_name' => 'Bapak Rudi']);
        $this->service->rate($used['token'], 5, null);

        $this->actingAs($user)
            ->postJson("/api/servicedesk/csat/{$used['rating']->id}/revoke")
            ->assertStatus(422);
    }

    public function test_issuing_needs_update_not_merely_a_session(): void
    {
        $ticket = CsatFixtures::ticket();

        $this->actingAs(CsatFixtures::userWith(['svc.view'], null, 'pembaca@test.local'))
            ->postJson("/api/servicedesk/tickets/{$ticket->id}/csat", ['recipient_name' => 'Ibu Sinta'])
            ->assertForbidden();
    }

    // ------------------------------------------------------- perangkap E

    /**
     * Komentar pelanggan bergerbang svc.view, dan gerbang itu BERLAKU: sebuah
     * sesi tanpa izin itu tidak boleh membacanya — meskipun sesi yang sama
     * boleh membaca tiketnya (rute baca modul ini hanya bersesi).
     */
    public function test_a_session_without_svc_view_cannot_read_the_comment(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.view', 'svc.update']);
        $issued = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);
        $this->service->rate($issued['token'], 1, 'Teknisinya merokok di ruang server.');

        $outsider = CsatFixtures::userWith(['crm.view'], null, 'orangluar@test.local');

        // Ia BOLEH membaca tiketnya — itulah gerbang yang berlaku hari ini…
        $this->actingAs($outsider)->getJson("/api/servicedesk/tickets/{$ticket->id}")->assertOk();

        // …tetapi TIDAK komentarnya.
        $this->actingAs($outsider)->getJson("/api/servicedesk/tickets/{$ticket->id}/csat")->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/servicedesk/csat-summary')->assertForbidden();
    }

    /**
     * SENSUS PERMUKAAN — cacat yang berulang di kampanye ini adalah "aturan
     * yang benar ditegakkan di SATU permukaan tetapi bocor di permukaan lain
     * yang sama". Daftar di bawah adalah SETIAP jawaban yang membawa tiket,
     * dan tidak satu pun boleh membawa komentarnya.
     */
    public function test_no_other_ticket_surface_carries_the_comment(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.view', 'svc.update', 'svc.create', 'core.view']);
        $issued = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);

        $komentar = 'Teknisinya merokok di ruang server dan pergi tanpa pamit.';
        $this->service->rate($issued['token'], 1, $komentar);

        $surfaces = [
            'daftar tiket' => '/api/servicedesk/tickets',
            'detail tiket' => "/api/servicedesk/tickets/{$ticket->id}",
            'tiket lewat SLA' => '/api/servicedesk/tickets-sla-breaches',
            'pencarian global' => '/api/core/search?q='.urlencode($ticket->code),
        ];

        foreach ($surfaces as $label => $url) {
            $body = $this->actingAs($issuer)->getJson($url)->getContent();

            $this->assertStringNotContainsString($komentar, $body, "{$label} membawa komentar CSAT");
            $this->assertStringNotContainsString('merokok', $body, "{$label} membawa komentar CSAT");
        }
    }

    /**
     * …dan tidak satu pun kunci CSAT masuk TicketResource, betapa pun
     * tergodanya orang berikutnya. Satu kunci di sana muncul di daftar, di
     * pemilih (lookup.js), dan di ekspor XLSX sekaligus.
     */
    public function test_the_ticket_resource_carries_no_csat_key_at_all(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.view', 'svc.update']);
        $issued = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);
        $this->service->rate($issued['token'], 3, 'Biasa saja.');

        $row = $this->actingAs($issuer)->getJson("/api/servicedesk/tickets/{$ticket->id}")->json('data');

        foreach (array_keys($row) as $key) {
            $this->assertStringNotContainsStringIgnoringCase('csat', (string) $key,
                "TicketResource membawa kunci CSAT: {$key}");
        }
    }

    /** Cetakan house-form tiket tidak boleh memuat komentar pelanggan. */
    public function test_the_printed_ticket_form_carries_no_comment(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.view', 'svc.update', 'core.view']);
        $issued = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);
        $this->service->rate($issued['token'], 2, 'Datang terlambat tiga jam.');

        $source = file_get_contents(base_path('Modules/ServiceDesk/Services/ServiceDeskFormService.php'));

        $this->assertStringNotContainsString('csat', mb_strtolower((string) $source),
            'ServiceDeskFormService menyentuh CSAT — cetakan adalah permukaan tersendiri');
    }

    /** …dan tabelnya tidak ada di registri Laporan Bebas. */
    public function test_the_csat_table_is_not_a_free_report_source(): void
    {
        $registry = file_get_contents(base_path('Modules/Core/Support/ReportableResources.php'));

        $this->assertStringNotContainsString('svc_csat_ratings', (string) $registry);
    }

    // --------------------------------------------------------------- summary

    public function test_the_summary_endpoint_sends_the_denominators_with_the_average(): void
    {
        $issuer = CsatFixtures::userWith(['svc.view', 'svc.update']);

        for ($i = 1; $i <= 4; $i++) {
            CsatFixtures::ticket(TicketStatus::Resolved, null, "Kunjungan #{$i}");
        }

        $rated = CsatFixtures::ticket(TicketStatus::Resolved, null, 'Kunjungan yang dinilai');
        $issued = $this->service->issue($issuer, $rated, ['recipient_name' => 'Ibu Sinta']);
        $this->service->rate($issued['token'], 4, 'Rapi.');

        $meta = $this->actingAs($issuer)->getJson('/api/servicedesk/csat-summary')->assertOk()->json('meta.summary');

        $this->assertSame(5, $meta['ratable']);
        $this->assertSame(1, $meta['invited']);
        $this->assertSame(1, $meta['rated']);
        // JSON menuliskan 4.0 sebagai 4 — yang dijaga di sini nilainya, dan
        // terutama bahwa ia BUKAN null dan bukan 0.
        $this->assertEquals(4.0, $meta['average']);
        $this->assertNotNull($meta['average']);
    }

    public function test_the_summary_endpoint_answers_null_not_zero_when_nothing_is_rated(): void
    {
        CsatFixtures::ticket(TicketStatus::Resolved);

        $meta = $this->actingAs(CsatFixtures::userWith(['svc.view']))
            ->getJson('/api/servicedesk/csat-summary')->assertOk()->json('meta.summary');

        $this->assertNull($meta['average']);
        $this->assertSame(0, $meta['rated']);
        $this->assertNull($meta['response_rate']);
    }

    public function test_the_summary_list_carries_the_comment_for_svc_view_holders(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.view', 'svc.update']);
        $issued = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);
        $this->service->rate($issued['token'], 5, 'Teknisinya sangat membantu.');

        $row = $this->actingAs($issuer)->getJson('/api/servicedesk/csat-summary')->assertOk()->json('data.0');

        $this->assertSame('Teknisinya sangat membantu.', $row['comment']);
        $this->assertSame(5, $row['score']);
        $this->assertSame('Sangat puas', $row['score_label']);
        $this->assertSame($ticket->code, $row['ticket_code']);
        $this->assertSame('rated', $row['state']);
    }

    public function test_a_stale_hash_never_travels_in_any_csat_response(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.view', 'svc.update']);
        $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);

        $hash = CsatRating::query()->value('token_hash');
        $this->assertNotNull($hash);

        foreach (["/api/servicedesk/tickets/{$ticket->id}/csat", '/api/servicedesk/csat-summary'] as $url) {
            $this->assertStringNotContainsString($hash, $this->actingAs($issuer)->getJson($url)->getContent());
        }
    }
}
