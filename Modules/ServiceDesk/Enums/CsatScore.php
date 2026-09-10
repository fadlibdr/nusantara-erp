<?php

namespace Modules\ServiceDesk\Enums;

/**
 * Skala kepuasan pelanggan CSAT — 1..5, satu sumber untuk empat permukaan:
 * halaman publik (tombol yang diklik pelanggan), kartu tiket, ringkasan CSAT,
 * dan uji. Label Indonesia karena ia dibaca pelanggan, bukan operator.
 *
 * Lima titik dan bukan sepuluh, dan bukan pula "puas / tidak puas": lima
 * adalah skala CSAT yang lazim, cukup lebar untuk membedakan "cukup" dari
 * "puas", dan cukup sempit untuk diketuk sekali di layar ponsel tanpa
 * menggulir. Rata-ratanya ditulis "x,y dari 5" — SELALU dengan pembaginya,
 * supaya angka 4 tidak pernah terbaca sebagai 4 dari 10.
 */
enum CsatScore: int
{
    case SangatTidakPuas = 1;
    case TidakPuas = 2;
    case Cukup = 3;
    case Puas = 4;
    case SangatPuas = 5;

    public function label(): string
    {
        return match ($this) {
            self::SangatTidakPuas => 'Sangat tidak puas',
            self::TidakPuas => 'Tidak puas',
            self::Cukup => 'Cukup',
            self::Puas => 'Puas',
            self::SangatPuas => 'Sangat puas',
        };
    }

    /**
     * Skor yang dianggap "puas" dalam ringkasan (4 dan 5) — konvensi CSAT
     * top-2-box. Dipakai HANYA untuk melabeli, tidak pernah untuk menggantikan
     * rata-ratanya.
     */
    public function isSatisfied(): bool
    {
        return $this->value >= 4;
    }

    /** @return list<self> */
    public static function ascending(): array
    {
        return [self::SangatTidakPuas, self::TidakPuas, self::Cukup, self::Puas, self::SangatPuas];
    }
}
