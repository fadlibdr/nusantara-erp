<?php

namespace Tests\Unit\Core;

use Modules\Core\Support\Money;
use Tests\ErpTestCase;

/**
 * Rupiah presentation: "Rp " prefix, dot as thousands separator and comma as
 * the decimal separator (Indonesian convention, the opposite of en-US).
 */
class MoneyTest extends ErpTestCase
{
    public function test_it_formats_with_indonesian_separators(): void
    {
        $this->assertSame('Rp 48.500.000,00', Money::format(48_500_000));
    }

    public function test_zero_still_shows_two_decimals(): void
    {
        $this->assertSame('Rp 0,00', Money::format(0));
    }

    public function test_amounts_below_one_thousand_carry_no_separator(): void
    {
        $this->assertSame('Rp 999,00', Money::format(999));
        $this->assertSame('Rp 1.000,00', Money::format(1_000));
    }

    public function test_it_rounds_to_two_decimals(): void
    {
        // 1.234,567 => 1.234,57
        $this->assertSame('Rp 1.234,57', Money::format(1_234.567));
    }

    public function test_it_can_drop_the_decimals(): void
    {
        // 1.234,56 rounded to whole rupiah = 1.235
        $this->assertSame('Rp 1.235', Money::format(1_234.56, false));
        $this->assertSame('Rp 48.500.000', Money::format(48_500_000, false));
    }

    public function test_a_negative_amount_keeps_its_sign_after_the_prefix(): void
    {
        $this->assertSame('Rp -1.500,50', Money::format(-1_500.5));
    }

    public function test_it_accepts_the_decimal_string_a_money_cast_returns(): void
    {
        // decimal:2 casts hand back strings like "1000.50".
        $this->assertSame('Rp 1.000,50', Money::format('1000.50'));
    }

    public function test_a_billion_rupiah_contract_value(): void
    {
        $this->assertSame('Rp 1.234.567.890,12', Money::format(1_234_567_890.12));
    }
}
