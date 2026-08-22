<?php

namespace Modules\Crm\Services;

use Illuminate\Support\Carbon;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Quotation;

/**
 * Analitik win-rate tender (temuan #78).
 *
 * won_at, lost_at dan lost_reason dicatat QuotationService sejak awal, dan
 * tidak satu layar pun mengagregasinya: manajemen tidak bisa menjawab 'berapa
 * persen tender kita menang, dan kenapa kita kalah' padahal datanya lengkap.
 * Laporan ini murni MEMBACA — tidak ada tabel baru, tidak ada kolom baru.
 *
 * Dua keputusan bentuk yang perlu alasan tertulis:
 *
 *  - KUARTAL DIAMBIL DARI TANGGAL KEPUTUSAN (won_at / lost_at), bukan tanggal
 *    penawaran dibuat. Pertanyaan manajemen adalah "kuartal lalu kita menang
 *    berapa" — penawaran yang menggantung dua kuartal dihitung saat nasibnya
 *    diputuskan, bukan saat dokumennya diketik, atau kuartal lama akan
 *    berubah angkanya setiap kali penawaran lama akhirnya diputuskan.
 *
 *  - NILAI MEMAKAI DPP, bukan total: markWon() membawa dpp menjadi nilai
 *    kontrak, jadi 'nilai menang' di sini dan nilai kontrak di layar kontrak
 *    adalah angka yang sama — dua layar yang menyebut angka berbeda untuk
 *    kemenangan yang sama akan mengubur kepercayaan pada keduanya.
 *
 * Agregasi berjalan di PHP, bukan SQL GROUP BY: fungsi kuartal berbeda antara
 * SQLite (suite/live) dan MySQL, dan populasi penawaran per instalasi adalah
 * ratusan, bukan jutaan.
 */
class PipelineReportService
{
    public function report(): array
    {
        $quotations = Quotation::query()->get(['dpp', 'status', 'won_at', 'lost_at', 'lost_reason']);

        $decided = $quotations->filter(
            fn (Quotation $quotation): bool => $quotation->won_at !== null || $quotation->lost_at !== null,
        );
        // Penawaran yang DITOLAK INTERNAL (maker-checker menolak dokumennya)
        // tidak pernah sampai ke meja tender: bukan menang, bukan kalah, dan
        // bukan 'masih berjalan'. Tanpa pengecualian ini ia terhitung
        // undecided dan menggelembungkan pipeline hidup dengan kertas mati —
        // nilainya dilaporkan terpisah supaya tidak menghilang diam-diam.
        $rejected = $quotations->filter(
            fn (Quotation $quotation): bool => $quotation->won_at === null
                && $quotation->lost_at === null
                && $quotation->status === DocumentStatus::Rejected,
        );
        $undecided = $quotations->reject(
            fn (Quotation $quotation): bool => $quotation->won_at !== null
                || $quotation->lost_at !== null
                || $quotation->status === DocumentStatus::Rejected,
        );

        $quarters = [];
        $reasons = [];
        $totals = [
            'won_count' => 0, 'lost_count' => 0,
            'won_value' => 0.0, 'lost_value' => 0.0,
        ];

        foreach ($decided as $quotation) {
            // markWon()/markLost() enforce mutual exclusivity, so won_at set
            // means won; a row carrying both would be corrupt data and reads
            // as won here rather than silently vanishing from both columns.
            $won = $quotation->won_at !== null;

            /** @var Carbon $decidedAt */
            $decidedAt = $won ? $quotation->won_at : $quotation->lost_at;
            $key = $decidedAt->year.'-Q'.$decidedAt->quarter;
            $value = round((float) $quotation->dpp, 2);

            $quarters[$key] ??= [
                'quarter' => $key,
                'won_count' => 0, 'lost_count' => 0,
                'won_value' => 0.0, 'lost_value' => 0.0,
            ];

            $side = $won ? 'won' : 'lost';
            $quarters[$key][$side.'_count']++;
            $quarters[$key][$side.'_value'] = round($quarters[$key][$side.'_value'] + $value, 2);
            $totals[$side.'_count']++;
            $totals[$side.'_value'] = round($totals[$side.'_value'] + $value, 2);

            if (! $won) {
                // Baris lama tanpa alasan tetap dihitung, berlabel jujur —
                // membuangnya justru menyembunyikan catatan paling ceroboh
                // dari peringkat yang dibuat untuk menemukannya.
                $reason = trim((string) $quotation->lost_reason) ?: 'Tidak dicatat';

                $reasons[$reason] ??= ['reason' => $reason, 'count' => 0, 'value' => 0.0];
                $reasons[$reason]['count']++;
                $reasons[$reason]['value'] = round($reasons[$reason]['value'] + $value, 2);
            }
        }

        // '2025-Q4' < '2026-Q1' by plain string order — the key is built for it.
        ksort($quarters);

        foreach ($quarters as &$quarter) {
            $quarter['win_rate'] = $this->rate($quarter['won_count'], $quarter['lost_count']);
        }
        unset($quarter);

        // Alasan terbanyak dulu; nilai memecah seri supaya urutannya stabil
        // antara dua pemanggilan.
        usort($reasons, fn (array $a, array $b): int => [$b['count'], $b['value']] <=> [$a['count'], $a['value']]);

        return [
            'quarters' => array_values($quarters),
            'lose_reasons' => array_values($reasons),
            'totals' => $totals + [
                'win_rate' => $this->rate($totals['won_count'], $totals['lost_count']),
                'undecided_count' => $undecided->count(),
                'undecided_value' => round((float) $undecided->sum('dpp'), 2),
                'rejected_count' => $rejected->count(),
                'rejected_value' => round((float) $rejected->sum('dpp'), 2),
            ],
        ];
    }

    /**
     * Win rate in percent, one decimal — null when nothing was decided,
     * because "0%" claims we lost everything and an empty pipeline lost
     * nothing.
     */
    private function rate(int $won, int $lost): ?float
    {
        $decided = $won + $lost;

        return $decided === 0 ? null : round($won / $decided * 100, 1);
    }
}
