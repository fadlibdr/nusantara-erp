<?php

namespace Modules\HrPayroll\Enums;

/**
 * PTKP (Penghasilan Tidak Kena Pajak) status of an employee.
 *
 * Stored as the customary "TK/0" style string. Amounts per PMK 101/PMK.010/2016:
 * TK/0 = 54.000.000 / year, +4.500.000 for married status, +4.500.000 per
 * dependent (max 3). TER category mapping per PMK 168/2023 Pasal 2 ayat (3).
 */
enum PtkpStatus: string
{
    case TK0 = 'TK/0';
    case TK1 = 'TK/1';
    case TK2 = 'TK/2';
    case TK3 = 'TK/3';
    case K0 = 'K/0';
    case K1 = 'K/1';
    case K2 = 'K/2';
    case K3 = 'K/3';

    public function label(): string
    {
        $marital = $this->isMarried() ? 'Kawin' : 'Tidak kawin';
        $dependents = $this->dependents() === 0
            ? 'tanpa tanggungan'
            : $this->dependents().' tanggungan';

        return "{$this->value} ({$marital}, {$dependents})";
    }

    public function isMarried(): bool
    {
        return in_array($this, [self::K0, self::K1, self::K2, self::K3], true);
    }

    public function dependents(): int
    {
        return match ($this) {
            self::TK0, self::K0 => 0,
            self::TK1, self::K1 => 1,
            self::TK2, self::K2 => 2,
            self::TK3, self::K3 => 3,
        };
    }

    /**
     * Annual PTKP in rupiah (PMK 101/PMK.010/2016).
     */
    public function annualPtkp(): float
    {
        $base = 54000000.0;

        if ($this->isMarried()) {
            $base += 4500000.0;
        }

        return $base + $this->dependents() * 4500000.0;
    }

    /**
     * TER monthly-rate category per PMK 168/2023:
     * A = TK/0, TK/1, K/0; B = TK/2, TK/3, K/1, K/2; C = K/3.
     */
    public function terCategory(): string
    {
        return match ($this) {
            self::TK0, self::TK1, self::K0 => 'A',
            self::TK2, self::TK3, self::K1, self::K2 => 'B',
            self::K3 => 'C',
        };
    }
}
