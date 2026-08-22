<?php

namespace Tests\Unit\HrPayroll;

use Modules\HrPayroll\Enums\PtkpStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;

/**
 * PTKP status semantics: the annual exemption (PMK 101/PMK.010/2016) and the
 * TER category mapping (PMK 168/2023 Pasal 2 ayat 3).
 */
class PtkpStatusTest extends ErpTestCase
{
    /**
     * PMK 101/2016: base 54.000.000, +4.500.000 if married,
     * +4.500.000 per dependent (max 3 dependents).
     *
     * @return array<string, array{PtkpStatus, float}>
     */
    public static function annualPtkpProvider(): array
    {
        return [
            // 54.000.000
            'TK/0' => [PtkpStatus::TK0, 54_000_000.0],
            // 54.000.000 + 1 x 4.500.000 = 58.500.000
            'TK/1' => [PtkpStatus::TK1, 58_500_000.0],
            // 54.000.000 + 2 x 4.500.000 = 63.000.000
            'TK/2' => [PtkpStatus::TK2, 63_000_000.0],
            // 54.000.000 + 3 x 4.500.000 = 67.500.000
            'TK/3' => [PtkpStatus::TK3, 67_500_000.0],
            // 54.000.000 + 4.500.000 (kawin) = 58.500.000
            'K/0' => [PtkpStatus::K0, 58_500_000.0],
            // 54.000.000 + 4.500.000 (kawin) + 1 x 4.500.000 = 63.000.000
            'K/1' => [PtkpStatus::K1, 63_000_000.0],
            // 54.000.000 + 4.500.000 (kawin) + 2 x 4.500.000 = 67.500.000
            'K/2' => [PtkpStatus::K2, 67_500_000.0],
            // 54.000.000 + 4.500.000 (kawin) + 3 x 4.500.000 = 72.000.000
            'K/3' => [PtkpStatus::K3, 72_000_000.0],
        ];
    }

    #[DataProvider('annualPtkpProvider')]
    public function test_annual_ptkp_follows_pmk_101_2016(PtkpStatus $status, float $expected): void
    {
        $this->assertSame($expected, $status->annualPtkp());
    }

    public function test_ter_category_a_is_exactly_tk0_tk1_and_k0(): void
    {
        $this->assertSame(['TK/0', 'TK/1', 'K/0'], $this->statusesInCategory('A'));
    }

    public function test_ter_category_b_is_exactly_tk2_tk3_k1_and_k2(): void
    {
        $this->assertSame(['TK/2', 'TK/3', 'K/1', 'K/2'], $this->statusesInCategory('B'));
    }

    public function test_ter_category_c_is_exactly_k3(): void
    {
        $this->assertSame(['K/3'], $this->statusesInCategory('C'));
    }

    public function test_the_three_ter_categories_partition_every_ptkp_status(): void
    {
        // 3 + 4 + 1 = 8 = every case of the enum, no status left unmapped.
        $covered = count($this->statusesInCategory('A'))
            + count($this->statusesInCategory('B'))
            + count($this->statusesInCategory('C'));

        $this->assertSame(count(PtkpStatus::cases()), $covered);
        $this->assertSame(8, $covered);
    }

    public function test_only_the_k_series_counts_as_married(): void
    {
        $married = array_values(array_map(
            static fn (PtkpStatus $status): string => $status->value,
            array_filter(PtkpStatus::cases(), static fn (PtkpStatus $status): bool => $status->isMarried()),
        ));

        $this->assertSame(['K/0', 'K/1', 'K/2', 'K/3'], $married);
    }

    public function test_dependent_count_is_the_digit_of_the_status(): void
    {
        foreach (PtkpStatus::cases() as $status) {
            $this->assertSame(
                (int) substr($status->value, -1),
                $status->dependents(),
                "Dependent count of [{$status->value}] does not match its label.",
            );
        }

        // Statute caps the deductible dependents at 3.
        $this->assertSame(3, max(array_map(
            static fn (PtkpStatus $status): int => $status->dependents(),
            PtkpStatus::cases(),
        )));
    }

    /**
     * @return array<int, string>
     */
    private function statusesInCategory(string $category): array
    {
        return array_values(array_map(
            static fn (PtkpStatus $status): string => $status->value,
            array_filter(
                PtkpStatus::cases(),
                static fn (PtkpStatus $status): bool => $status->terCategory() === $category,
            ),
        ));
    }
}
