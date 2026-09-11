<?php

namespace Tests\Feature\ServiceDesk;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\ServiceDesk\Enums\CsatScore;
use Modules\ServiceDesk\Models\CsatRating;
use Tests\ErpTestCase;

/**
 * F-9 langkah 1 — bentuk penyimpanan CSAT.
 *
 * Yang dipaku di sini bukan "tabelnya ada" melainkan tiga sifat yang membuat
 * atau menggagalkan paket ini:
 *
 *  1. score NULLABLE, dan NULL bukan 0. Sebuah kolom score NOT NULL DEFAULT 0
 *     akan membuat setiap tiket yang diundang tetapi belum menjawab menyeret
 *     rata-rata ke bawah, dan tidak ada satu pun layar yang bisa memperbaikinya
 *     sesudah itu.
 *  2. token_hash UNIK dan panjangnya panjang sha256 — tautan yang bertabrakan
 *     adalah tautan yang membuka tiket orang lain.
 *  3. isExpired() memakai tepi >= : `expires_at = sekarang` sudah kedaluwarsa,
 *     aturan yang sama dengan ExternalApproval (uji tepi P0-F).
 */
class CsatSchemaTest extends ErpTestCase
{
    public function test_the_table_carries_the_token_side_of_the_external_approval_shape(): void
    {
        $this->assertTrue(Schema::hasTable('svc_csat_ratings'));

        foreach ([
            'ticket_id', 'recipient_name', 'recipient_email',
            'token_hash', 'expires_at', 'issued_by', 'revoked_at', 'revoked_by',
            'score', 'comment', 'rated_at', 'rated_via',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('svc_csat_ratings', $column),
                "kolom {$column} hilang dari svc_csat_ratings",
            );
        }
    }

    public function test_an_unrated_row_stores_a_null_score_not_a_zero(): void
    {
        $rating = CsatRating::query()->create([
            'ticket_id' => $this->ticketId(),
            'recipient_name' => 'Ibu Sinta',
            'token_hash' => hash('sha256', 'token-belum-dipakai'),
            'expires_at' => now()->addDays(3),
        ]);

        $fresh = CsatRating::query()->findOrFail($rating->id);

        $this->assertNull($fresh->score, 'tiket yang belum dinilai tidak punya skor — bukan nol bintang');
        $this->assertNull($fresh->rated_at);
        $this->assertFalse($fresh->isRated());
        $this->assertTrue($fresh->isLive());
    }

    public function test_two_rows_cannot_share_a_token_hash(): void
    {
        $hash = hash('sha256', 'token-yang-sama');

        CsatRating::query()->create([
            'ticket_id' => $this->ticketId(),
            'recipient_name' => 'Ibu Sinta',
            'token_hash' => $hash,
        ]);

        $this->expectException(QueryException::class);

        CsatRating::query()->create([
            'ticket_id' => $this->ticketId(),
            'recipient_name' => 'Bapak Rudi',
            'token_hash' => $hash,
        ]);
    }

    /** Baris lembar tanpa token sah berdampingan — NULL ganda lolos unique. */
    public function test_rows_without_a_token_do_not_collide(): void
    {
        CsatRating::query()->create(['ticket_id' => $this->ticketId(), 'recipient_name' => 'A']);
        CsatRating::query()->create(['ticket_id' => $this->ticketId(), 'recipient_name' => 'B']);

        $this->assertSame(2, CsatRating::query()->count());
    }

    public function test_expiry_is_reached_at_the_exact_boundary_not_after_it(): void
    {
        /*
         * WAJIB DIBEKUKAN. Tanpa freezeTime() kedua now() di bawah terpisah
         * beberapa mikrodetik, jadi `expires_at < now()` juga benar dan uji ini
         * hijau untuk `lessThan` MAUPUN `lessThanOrEqualTo` — terukur
         * 10 Sep 2026: mutasi >= menjadi > lolos hijau sebelum baris ini ada.
         * Tepi inilah satu-satunya yang diuji di sini, jadi tepinya harus
         * benar-benar menjadi satu titik waktu.
         */
        // travelTo pada DETIK BULAT, bukan freezeTime(): cast 'datetime'
        // membuang mikrodetik, jadi pada waktu beku ber-mikrodetik nilai yang
        // dibaca ulang SELALU lebih kecil dari now() dan tepi >= tidak pernah
        // benar-benar diuji (terukur 10 Sep 2026: mutasi >= → > lolos hijau
        // dua kali, sekali tanpa pembekuan dan sekali dengan freezeTime()).
        $this->travelTo(Carbon::parse('2026-09-10 09:00:00'));

        $rating = new CsatRating(['expires_at' => now()]);

        $this->assertTrue($rating->isExpired(), 'expires_at = sekarang harus SUDAH kedaluwarsa');

        $rating->expires_at = now()->addSecond();
        $this->assertFalse($rating->isExpired());

        $rating->expires_at = now()->subSecond();
        $this->assertTrue($rating->isExpired());

        $this->travelBack();
    }

    /**
     * Skalanya lima titik, 1..5, dan angkanya dipaku LITERAL di sini.
     * Menyusun harapan dari enum yang diuji membuat uji ini hijau untuk skala
     * apa pun — pelajaran F-6 (dua mutasi lolos hijau).
     */
    public function test_the_scale_is_one_to_five_with_indonesian_labels(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], array_map(
            static fn (CsatScore $score): int => $score->value,
            CsatScore::ascending(),
        ));

        $this->assertSame('Sangat tidak puas', CsatScore::from(1)->label());
        $this->assertSame('Tidak puas', CsatScore::from(2)->label());
        $this->assertSame('Cukup', CsatScore::from(3)->label());
        $this->assertSame('Puas', CsatScore::from(4)->label());
        $this->assertSame('Sangat puas', CsatScore::from(5)->label());

        $this->assertFalse(CsatScore::from(3)->isSatisfied());
        $this->assertTrue(CsatScore::from(4)->isSatisfied());
        $this->assertTrue(CsatScore::from(5)->isSatisfied());
    }

    private function ticketId(): int
    {
        return CsatFixtures::ticket()->id;
    }
}
