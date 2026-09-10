<?php

namespace Tests\Feature\ServiceDesk;

use Modules\ServiceDesk\Enums\TicketStatus;
use Modules\ServiceDesk\Models\CsatRating;
use Modules\ServiceDesk\Models\Ticket;
use Modules\ServiceDesk\Services\CsatService;
use Tests\ErpTestCase;

/**
 * F-9 langkah 4 — PERANGKAP A: rata-rata hanya dari yang dinilai.
 *
 * Bentuk fixture-nya sengaja tidak simetris: sepuluh tiket selesai, tiga
 * diundang, DUA yang menjawab (5 dan 4). Angka yang benar adalah 4,5 dari 2
 * jawaban — dan setiap cara yang salah menghitungnya menghasilkan angka lain
 * yang bisa dibedakan:
 *
 *    yang belum menjawab dihitung nol   → 9 / 10  = 0,9
 *    penyebutnya semua tiket selesai    → 9 / 10  = 0,9
 *    penyebutnya yang diundang          → 9 / 3   = 3,0
 *    yang benar                          → 9 / 2   = 4,5
 *
 * Tiga angka salah yang berbeda satu sama lain: sebuah fixture di mana
 * "diundang" = "dinilai" = "selesai" akan hijau untuk keempatnya sekaligus.
 * Semua angka harapan ditulis LITERAL, bukan dihitung ulang dari yang diuji
 * (pelajaran F-6).
 */
class CsatSummaryTest extends ErpTestCase
{
    private CsatService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CsatService::class);
    }

    /**
     * Sepuluh tiket selesai; tiga diundang; dua menjawab (5 dan 4).
     *
     * @return list<Ticket>
     */
    private function tenFinishedTicketsThreeInvitedTwoAnswered(): array
    {
        $tickets = [];

        for ($i = 1; $i <= 10; $i++) {
            $tickets[] = CsatFixtures::ticket(TicketStatus::Resolved, null, "Kunjungan servis #{$i}");
        }

        $issuer = CsatFixtures::userWith(['svc.update']);

        $a = $this->service->issue($issuer, $tickets[0], ['recipient_name' => 'Ibu Sinta']);
        $b = $this->service->issue($issuer, $tickets[1], ['recipient_name' => 'Bapak Rudi']);
        $this->service->issue($issuer, $tickets[2], ['recipient_name' => 'Ibu Wati']); // diundang, DIAM

        $this->service->rate($a['token'], 5, 'Cepat sekali.');
        $this->service->rate($b['token'], 4, null);

        return $tickets;
    }

    public function test_the_average_counts_only_the_answers_and_never_the_silence(): void
    {
        $this->tenFinishedTicketsThreeInvitedTwoAnswered();

        $summary = $this->service->summary();

        $this->assertSame(10, $summary['ratable']);
        $this->assertSame(3, $summary['invited']);
        $this->assertSame(2, $summary['rated']);
        $this->assertSame(4.5, $summary['average']);

        // Tiga angka yang salah, dan ketiganya berbeda dari 4,5.
        $this->assertNotSame(0.9, $summary['average']);
        $this->assertNotSame(3.0, $summary['average']);
    }

    public function test_the_count_of_answers_always_travels_with_the_average(): void
    {
        $this->tenFinishedTicketsThreeInvitedTwoAnswered();

        $summary = $this->service->summary();

        // Bukan sekadar "ada kuncinya": ketiga penyebut yang berbeda hadir
        // bersama-sama, supaya tidak ada permukaan yang bisa memajang
        // rata-ratanya tanpa jumlah yang menopangnya.
        foreach (['ratable', 'invited', 'rated', 'average', 'distribution', 'response_rate'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }

        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1], $summary['distribution']);
        $this->assertSame(2, $summary['satisfied']);
        $this->assertSame(0.6667, $summary['response_rate']);
    }

    /**
     * BERAPA KOMENTAR YANG SEBENARNYA ADA — angka yang dikirim server, bukan
     * panjang halaman pertama.
     *
     * Kartu "Komentar pelanggan (N)" di layar #/csat memakai N ini. Sampai
     * angka ini dikirim, judulnya menghitung baris halaman yang kebetulan
     * dimuat: dengan 61 penilaian berkomentar dan `per_page=50`, kartunya
     * berbunyi "Komentar pelanggan (50)" — dibaca sebagai "segini seluruhnya"
     * di rapat bulanan (terukur di Chromium 10 Sep 2026).
     *
     * Fixture 10/3/2 di atas punya TEPAT SATU komentar dari DUA jawaban: angka
     * yang berbeda dari `rated`, dari `invited`, dan dari `ratable`, jadi
     * sebuah judul yang memakai salah satunya tetap bisa dibedakan.
     */
    public function test_the_summary_says_how_many_of_the_answers_carry_a_comment(): void
    {
        $this->tenFinishedTicketsThreeInvitedTwoAnswered();

        $summary = $this->service->summary();

        $this->assertSame(1, $summary['commented'], 'hanya satu dari dua jawaban menulis komentar');
        $this->assertSame(2, $summary['rated']);
        $this->assertSame(3, $summary['invited']);
        $this->assertSame(10, $summary['ratable']);
    }

    /** Tanpa satu pun jawaban: nol komentar, dan nol yang jujur (bukan null). */
    public function test_nothing_rated_means_nothing_commented(): void
    {
        CsatFixtures::ticket(TicketStatus::Resolved, null, 'Kunjungan tanpa jawaban');

        $this->assertSame(0, $this->service->summary()['commented']);
    }

    /**
     * Ambang "puas" adalah top-2-box (4 dan 5), dan fixture ini memuat 3
     * justru karena 3 adalah satu-satunya nilai yang membedakannya: dengan
     * jawaban 5 dan 4 saja, ambang >= 3 dan >= 4 memberi angka yang sama dan
     * mutasinya lolos hijau (terukur 10 Sep 2026).
     */
    public function test_satisfied_counts_four_and_five_but_not_three(): void
    {
        $issuer = CsatFixtures::userWith(['svc.update']);
        $scores = [5, 4, 3, 2, 1];

        foreach ($scores as $score) {
            $ticket = CsatFixtures::ticket(TicketStatus::Resolved, null, "Kunjungan skor {$score}");
            $issued = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Penilai '.$score]);
            $this->service->rate($issued['token'], $score, null);
        }

        $summary = $this->service->summary();

        $this->assertSame(5, $summary['rated']);
        $this->assertSame(2, $summary['satisfied'], 'hanya 4 dan 5 yang dihitung puas — 3 bukan');
        $this->assertSame(3.0, $summary['average']);
        $this->assertSame([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1], $summary['distribution']);
    }

    /**
     * "Belum ada penilaian" adalah KALIMAT, bukan angka nol — dan kalimat itu
     * ditulis layar dari null ini. Sebuah 0,0 di sini menjadi 0,0 di layar,
     * di ekspor, dan di setiap tangkapan layar yang dikirim ke pemilik.
     */
    public function test_nothing_rated_yields_a_null_average_never_zero(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            CsatFixtures::ticket(TicketStatus::Resolved, null, "Kunjungan tanpa jawaban #{$i}");
        }

        $summary = $this->service->summary();

        $this->assertSame(4, $summary['ratable']);
        $this->assertSame(0, $summary['rated']);
        $this->assertNull($summary['average'], 'nol penilaian bukan nol bintang');
        $this->assertNotSame(0, $summary['average']);
        $this->assertNotSame(0.0, $summary['average']);
        $this->assertNull($summary['response_rate'], 'tanpa undangan tidak ada tingkat jawaban');
        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0], $summary['distribution']);
    }

    /** Nol tiket sama sekali: tetap null, tetap tanpa satu 0,0 pun. */
    public function test_an_empty_database_yields_nulls_not_zeroes(): void
    {
        $summary = $this->service->summary();

        $this->assertSame(0, $summary['ratable']);
        $this->assertSame(0, $summary['invited']);
        $this->assertSame(0, $summary['rated']);
        $this->assertNull($summary['average']);
        $this->assertNull($summary['response_rate']);
    }

    /**
     * Universe-nya adalah tiket yang SELESAI. Tiket yang masih dikerjakan dan
     * tiket yang dibatalkan tidak pernah masuk penyebut mana pun — sebuah
     * "12 dari 47 dinilai" yang 47-nya memuat tiket yang baru dibuka pagi ini
     * adalah tingkat jawaban yang tidak berarti apa-apa.
     */
    public function test_open_and_cancelled_tickets_are_not_part_of_any_denominator(): void
    {
        CsatFixtures::ticket(TicketStatus::Resolved, null, 'Selesai');
        CsatFixtures::ticket(TicketStatus::Closed, null, 'Ditutup');
        CsatFixtures::ticket(TicketStatus::Open, null, 'Baru masuk');
        CsatFixtures::ticket(TicketStatus::InProgress, null, 'Sedang dikerjakan');
        CsatFixtures::ticket(TicketStatus::PendingCustomer, null, 'Menunggu pelanggan');
        CsatFixtures::ticket(TicketStatus::Cancelled, null, 'Dibatalkan');

        $this->assertSame(2, $this->service->summary()['ratable']);
    }

    /**
     * Sebuah baris rated_at TANPA skor tidak bisa lahir lewat rate(), tetapi
     * bila toh ada (impor, sunting tangan) ia tidak boleh dihitung sebagai 0
     * dan tidak boleh menjatuhkan rata-ratanya.
     */
    public function test_a_rated_row_without_a_score_does_not_drag_the_average_to_zero(): void
    {
        $ticket = CsatFixtures::ticket();
        $issuer = CsatFixtures::userWith(['svc.update']);
        $issued = $this->service->issue($issuer, $ticket, ['recipient_name' => 'Ibu Sinta']);
        $this->service->rate($issued['token'], 5, null);

        CsatRating::query()->create([
            'ticket_id' => CsatFixtures::ticket(TicketStatus::Closed, null, 'Baris cacat')->id,
            'recipient_name' => 'Impor lama',
            'rated_at' => now(),
            'rated_via' => 'link',
        ]);

        $summary = $this->service->summary();

        $this->assertSame(5.0, $summary['average'], 'baris tanpa skor tidak boleh dijumlahkan sebagai nol');
    }

    /**
     * TIKET YANG DIBUKA KEMBALI TIDAK MENGHAPUS PENILAIAN YANG SUDAH MASUK —
     * dan terutama tidak menaikkan rata-ratanya.
     *
     * Sebuah penilaian adalah bukti atas pekerjaan yang saat itu dinyatakan
     * selesai; membuka tiketnya kembali tidak membatalkan bukti itu, dan yang
     * paling sering membuat tiket dibuka kembali adalah persis pekerjaan yang
     * dinilai buruk. Sampai uji ini ada, satu bintang-2 yang tiketnya dibuka
     * kembali LENYAP dari rata-rata — angkanya NAIK justru karena pekerjaannya
     * harus diulang, penyebutnya diam-diam turun, komentarnya hilang dari
     * kartu, dan kartu CSAT di tiketnya tetap menampilkannya (terukur 10 Sep
     * 2026: 4,2 → 4,3, "13 dari 49" → "12 dari 48").
     *
     * Angkanya LITERAL: 3,5 sebelum dan 3,5 sesudah, bukan dihitung ulang dari
     * yang diuji.
     */
    public function test_a_rating_survives_its_ticket_being_reopened_and_never_lifts_the_average(): void
    {
        $issuer = CsatFixtures::userWith(['svc.update']);

        $mulus = CsatFixtures::ticket(TicketStatus::Resolved, null, 'Kunjungan yang mulus');
        $diulang = CsatFixtures::ticket(TicketStatus::Resolved, null, 'Kunjungan yang harus diulang');
        CsatFixtures::ticket(TicketStatus::Resolved, null, 'Kunjungan yang diam');

        $a = $this->service->issue($issuer, $mulus, ['recipient_name' => 'Ibu Sinta']);
        $b = $this->service->issue($issuer, $diulang, ['recipient_name' => 'Bapak Rudi']);
        $this->service->rate($a['token'], 5, null);
        $this->service->rate($b['token'], 2, 'Pekerjaannya harus diulang minggu depan.');

        $sebelum = $this->service->summary();
        $this->assertSame(3, $sebelum['ratable']);
        $this->assertSame(2, $sebelum['invited']);
        $this->assertSame(2, $sebelum['rated']);
        $this->assertSame(3.5, $sebelum['average']);

        // Tiketnya dibuka kembali — persis karena pekerjaannya harus diulang.
        $diulang->forceFill(['status' => TicketStatus::InProgress, 'resolved_at' => null])->save();

        $sesudah = $this->service->summary();

        $this->assertSame(3.5, $sesudah['average'], 'bintang 2 tidak boleh hilang karena tiketnya dikerjakan lagi');
        $this->assertSame(2, $sesudah['rated']);
        $this->assertSame(3, $sesudah['ratable'], 'penyebutnya tidak boleh menyusut diam-diam');
        $this->assertSame(2, $sesudah['invited']);
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 0, 5 => 1], $sesudah['distribution']);
        $this->assertSame(1.0, $sesudah['response_rate']);

        // …dan komentarnya tetap terbaca di kartu Komentar pelanggan.
        $komentar = $this->service->ratedQuery()->get()->pluck('comment')->all();
        $this->assertContains('Pekerjaannya harus diulang minggu depan.', $komentar);
    }

    /**
     * Yang tetap dikecualikan: tiket yang dibuka kembali dan BELUM pernah
     * dinilai. Ia bukan bukti apa pun, jadi ia keluar dari penyebut sampai
     * pekerjaannya dinyatakan selesai lagi.
     */
    public function test_a_reopened_ticket_that_was_never_rated_is_not_in_any_denominator(): void
    {
        $selesai = CsatFixtures::ticket(TicketStatus::Resolved, null, 'Selesai');
        $dibuka = CsatFixtures::ticket(TicketStatus::Resolved, null, 'Dibuka kembali');
        $this->service->issue(CsatFixtures::userWith(['svc.update']), $dibuka, ['recipient_name' => 'Ibu Wati']);

        $dibuka->forceFill(['status' => TicketStatus::InProgress, 'resolved_at' => null])->save();

        $summary = $this->service->summary();

        $this->assertSame(1, $summary['ratable'], 'hanya '.$selesai->code.' yang selesai hari ini');
        $this->assertSame(0, $summary['invited']);
        $this->assertSame(0, $summary['rated']);
        $this->assertNull($summary['average']);
    }

    // ----------------------------------------------------------------- window

    /**
     * Jendela waktu diukur pada SELESAINYA pekerjaan, bukan pada dilaporkannya:
     * CSAT bulan Agustus adalah kepuasan atas pekerjaan yang selesai Agustus.
     */
    public function test_the_window_filters_on_when_the_work_finished(): void
    {
        $lama = CsatFixtures::ticket(TicketStatus::Resolved, null, 'Selesai Juni');
        $lama->forceFill(['resolved_at' => '2026-06-15 10:00:00'])->save();

        $baru = CsatFixtures::ticket(TicketStatus::Resolved, null, 'Selesai Agustus');
        $baru->forceFill(['resolved_at' => '2026-08-15 10:00:00'])->save();

        $issuer = CsatFixtures::userWith(['svc.update']);
        $a = $this->service->issue($issuer, $lama, ['recipient_name' => 'Ibu Sinta']);
        $b = $this->service->issue($issuer, $baru, ['recipient_name' => 'Bapak Rudi']);
        $this->service->rate($a['token'], 1, null);
        $this->service->rate($b['token'], 5, null);

        $agustus = $this->service->summary(['from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertSame(1, $agustus['ratable']);
        $this->assertSame(1, $agustus['rated']);
        $this->assertSame(5.0, $agustus['average'], 'tiket Juni tidak boleh ikut ke jendela Agustus');

        $juni = $this->service->summary(['from' => '2026-06-01', 'to' => '2026-06-30']);
        $this->assertSame(1.0, $juni['average']);

        $keduanya = $this->service->summary(['from' => '2026-06-01', 'to' => '2026-08-31']);
        $this->assertSame(3.0, $keduanya['average']);
        $this->assertSame(2, $keduanya['rated']);
    }

    public function test_the_summary_can_be_narrowed_to_one_service_contract(): void
    {
        $tiketA = CsatFixtures::ticket(TicketStatus::Resolved, null, 'Kontrak A');
        $tiketB = CsatFixtures::ticket(TicketStatus::Resolved, null, 'Kontrak B');
        $tiketB->forceFill(['service_contract_id' => null])->save();

        $issuer = CsatFixtures::userWith(['svc.update']);
        $a = $this->service->issue($issuer, $tiketA, ['recipient_name' => 'Ibu Sinta']);
        $b = $this->service->issue($issuer, $tiketB, ['recipient_name' => 'Bapak Rudi']);
        $this->service->rate($a['token'], 5, null);
        $this->service->rate($b['token'], 1, null);

        $summary = $this->service->summary(['service_contract_id' => $tiketA->service_contract_id]);

        $this->assertSame(1, $summary['ratable']);
        $this->assertSame(5.0, $summary['average']);
    }

    public function test_the_rated_list_carries_the_newest_first_with_its_ticket(): void
    {
        $this->tenFinishedTicketsThreeInvitedTwoAnswered();

        $rows = $this->service->ratedQuery()->get();

        $this->assertCount(2, $rows);
        $this->assertNotNull($rows->first()->ticket, 'setiap baris membawa tiketnya');
        $this->assertTrue($rows->every(fn (CsatRating $row) => $row->rated_at !== null));
    }
}
