<?php

namespace Modules\Quality\Enums;

/**
 * P6: jenis sebuah template checklist. 'quality' adalah pustaka titik henti
 * mutu Q1..Q31 (P1-QC); '5r' adalah patroli housekeeping 5R (Ringkas, Rapi,
 * Resik, Rawat, Rajin) yang menaiki mesin inspeksi yang sama — sebuah
 * checklist 5R terisi ADALAH qc_inspections biasa atas template ber-jenis ini.
 */
enum TemplateKind: string
{
    case Quality = 'quality';
    case FiveR = '5r';

    public function label(): string
    {
        return match ($this) {
            self::Quality => 'Checklist mutu',
            self::FiveR => 'Checklist 5R',
        };
    }
}
