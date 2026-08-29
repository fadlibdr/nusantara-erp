<?php

namespace Tests\Unit\Core;

use Modules\Core\Support\ApprovalLevels;
use Tests\ErpTestCase;

/**
 * The n-level approval resolver (P2).
 *
 * The ladder generalises the two-level PO/SPK threshold into a per-amount
 * count of distinct approvers. The boundary reading is load-bearing and must
 * match the historical >= threshold: an amount AT a bracket boundary belongs to
 * the HIGHER bracket, so exactly Rp 100 juta needs two levels, the same way
 * needs_director_approval fired on total >= threshold.
 */
class ApprovalLevelsTest extends ErpTestCase
{
    public function test_below_the_first_boundary_is_a_single_level(): void
    {
        $this->assertSame(1, ApprovalLevels::forAmount('award_decision', 50_000_000));
        $this->assertSame(1, ApprovalLevels::forAmount('award_decision', 99_999_999));
    }

    public function test_at_and_above_100_juta_is_two_levels(): void
    {
        // AT the boundary is the higher bracket — the >= reading PO/SPK use.
        $this->assertSame(2, ApprovalLevels::forAmount('award_decision', 100_000_000));
        $this->assertSame(2, ApprovalLevels::forAmount('award_decision', 500_000_000));
        $this->assertSame(2, ApprovalLevels::forAmount('award_decision', 999_999_999));
    }

    public function test_at_and_above_one_billion_is_three_levels(): void
    {
        $this->assertSame(3, ApprovalLevels::forAmount('award_decision', 1_000_000_000));
        // A Rp 1,5 miliar award needs three distinct approvers.
        $this->assertSame(3, ApprovalLevels::forAmount('award_decision', 1_500_000_000));
        $this->assertSame(3, ApprovalLevels::forAmount('award_decision', 5_000_000_000));
    }

    public function test_an_unknown_ladder_key_is_a_single_level(): void
    {
        // A document type with no ladder keeps the ordinary single-approval life.
        $this->assertSame(1, ApprovalLevels::forAmount('tidak_ada_ladder_ini', 5_000_000_000));
    }

    public function test_a_misordered_ladder_is_sorted_before_it_is_read(): void
    {
        // A config whose brackets are out of order must not shadow one — the
        // resolver sorts by bound ascending (null last).
        config(['erp.approvals.uji_ladder.ladder' => [
            ['to' => null, 'levels' => 3],
            ['to' => 100_000_000, 'levels' => 1],
            ['to' => 1_000_000_000, 'levels' => 2],
        ]]);

        $this->assertSame(1, ApprovalLevels::forAmount('uji_ladder', 50_000_000));
        $this->assertSame(2, ApprovalLevels::forAmount('uji_ladder', 500_000_000));
        $this->assertSame(3, ApprovalLevels::forAmount('uji_ladder', 2_000_000_000));
    }
}
