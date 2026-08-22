<?php

namespace Tests\Unit\Core;

use Modules\Core\Support\Terbilang;
use Tests\ErpTestCase;

/**
 * Indonesian number-to-words for the "terbilang" line on kwitansi and invoices.
 * The rules being pinned here: 1 = "satu" but 1.000 = "seribu" (never "satu
 * ribu"), 11 = "sebelas", 12–19 = "<unit> belas", 100 = "seratus" (never "satu
 * ratus"), while juta/miliar/triliun keep the explicit "satu".
 */
class TerbilangTest extends ErpTestCase
{
    public function test_zero_is_nol(): void
    {
        $this->assertSame('Nol rupiah', Terbilang::rupiah(0));
    }

    public function test_a_single_unit_is_satu(): void
    {
        $this->assertSame('Satu rupiah', Terbilang::rupiah(1));
    }

    public function test_eleven_is_the_irregular_sebelas(): void
    {
        $this->assertSame('Sebelas rupiah', Terbilang::rupiah(11));
    }

    public function test_the_teens_are_the_unit_plus_belas(): void
    {
        $this->assertSame('Dua belas rupiah', Terbilang::rupiah(12));
        $this->assertSame('Sembilan belas rupiah', Terbilang::rupiah(19));
    }

    public function test_ten_is_sepuluh_and_twenty_drops_the_trailing_unit(): void
    {
        $this->assertSame('Sepuluh rupiah', Terbilang::rupiah(10));
        // 20 = 2 puluh 0 => the empty unit must be trimmed off.
        $this->assertSame('Dua puluh rupiah', Terbilang::rupiah(20));
        $this->assertSame('Dua puluh satu rupiah', Terbilang::rupiah(21));
    }

    public function test_one_hundred_is_seratus_not_satu_ratus(): void
    {
        $this->assertSame('Seratus rupiah', Terbilang::rupiah(100));
        $this->assertSame('Seratus satu rupiah', Terbilang::rupiah(101));
        // 200 leaves the "seratus" branch and spells the multiplier again.
        $this->assertSame('Dua ratus rupiah', Terbilang::rupiah(200));
        $this->assertSame('Seratus sembilan puluh sembilan rupiah', Terbilang::rupiah(199));
    }

    public function test_one_thousand_is_seribu_not_satu_ribu(): void
    {
        $this->assertSame('Seribu rupiah', Terbilang::rupiah(1_000));
        $this->assertSame('Seribu satu rupiah', Terbilang::rupiah(1_001));
        $this->assertSame('Seribu lima ratus rupiah', Terbilang::rupiah(1_500));
        // 2.000 is above the "seribu" branch, so the multiplier reappears.
        $this->assertSame('Dua ribu rupiah', Terbilang::rupiah(2_000));
        $this->assertSame('Sepuluh ribu rupiah', Terbilang::rupiah(10_000));
        $this->assertSame('Seratus ribu rupiah', Terbilang::rupiah(100_000));
    }

    public function test_the_large_scales_keep_an_explicit_satu(): void
    {
        // The implementation contracts only "se-" for ratus/ribu; juta, miliar
        // and triliun are spelled "satu juta / satu miliar / satu triliun",
        // which is the form used on Indonesian invoices.
        $this->assertSame('Satu juta rupiah', Terbilang::rupiah(1_000_000));
        $this->assertSame('Satu miliar rupiah', Terbilang::rupiah(1_000_000_000));
        $this->assertSame('Satu triliun rupiah', Terbilang::rupiah(1_000_000_000_000));
    }

    public function test_a_realistic_contract_value(): void
    {
        // 48.500.000.000 = 48 miliar + 500 juta
        $this->assertSame(
            'Empat puluh delapan miliar lima ratus juta rupiah',
            Terbilang::rupiah(48_500_000_000),
        );
    }

    public function test_a_realistic_invoice_amount(): void
    {
        // 125.750.000 = 125 juta + 750 ribu
        $this->assertSame(
            'Seratus dua puluh lima juta tujuh ratus lima puluh ribu rupiah',
            Terbilang::rupiah(125_750_000),
        );
    }

    public function test_every_scale_at_once(): void
    {
        // 1.234.567.890.123
        $this->assertSame(
            'Satu triliun dua ratus tiga puluh empat miliar lima ratus enam puluh '
            .'tujuh juta delapan ratus sembilan puluh ribu seratus dua puluh tiga rupiah',
            Terbilang::rupiah(1_234_567_890_123),
        );
    }

    public function test_it_rounds_to_the_nearest_whole_rupiah(): void
    {
        // round() is half-up: 1.000,40 => 1.000 and 1.000,50 => 1.001.
        $this->assertSame('Seribu rupiah', Terbilang::rupiah(1_000.4));
        $this->assertSame('Seribu satu rupiah', Terbilang::rupiah(1_000.5));
    }

    public function test_it_accepts_the_decimal_string_a_money_cast_returns(): void
    {
        // decimal:2 casts hand back strings like "48500000.00".
        $this->assertSame('Empat puluh delapan juta lima ratus ribu rupiah', Terbilang::rupiah('48500000.00'));
    }

    public function test_a_negative_amount_is_prefixed_with_minus(): void
    {
        $this->assertSame('Minus seribu lima ratus rupiah', Terbilang::rupiah(-1_500));
    }

    public function test_make_returns_the_bare_words_without_the_currency_word(): void
    {
        $this->assertSame('nol', Terbilang::make(0));
        $this->assertSame('seribu lima ratus', Terbilang::make(1_500));
        $this->assertSame('sebelas', Terbilang::make(11));
    }
}
