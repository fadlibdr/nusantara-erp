<?php

namespace Modules\Projects\Enums;

/**
 * P6: banding tingkat risiko IBPRP — SATU-SATUNYA tempat ambangnya ditulis.
 *
 * Sumber: matriks 5×5 penilaian risiko SMKK, Permen PUPR 10/2021 (peraturan
 * yang sama yang sudah dikutip register kecelakaan kerja — SafetyIncidentService
 * dan migrasi 000780). Keterangan lampirannya membagi nilai risiko
 * (kemungkinan 1–5 × keparahan 1–5) menjadi TIGA tingkat:
 *
 *   1–4   tingkat risiko kecil
 *   5–12  tingkat risiko sedang
 *   15–25 tingkat risiko besar
 *
 * 13 dan 14 tidak pernah muncul sebagai hasil kali dua bilangan 1–5, jadi
 * banding di bawah menutup celah itu ke 'sedang' tanpa pernah memakainya.
 * Sengaja TIDAK ditambah pita keempat ("ekstrem"): ambang yang tidak
 * dinyatakan peraturan yang dikutip adalah angka karangan di atas kertas.
 */
enum RiskLevel: string
{
    case Kecil = 'kecil';
    case Sedang = 'sedang';
    case Besar = 'besar';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score <= 4 => self::Kecil,
            $score <= 14 => self::Sedang,
            default => self::Besar,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Kecil => 'Risiko kecil',
            self::Sedang => 'Risiko sedang',
            self::Besar => 'Risiko besar',
        };
    }
}
