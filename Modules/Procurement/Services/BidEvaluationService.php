<?php

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\BidEvaluation;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Support\BidWeights;

/**
 * Tabulasi penilaian penawaran berbobot (sistem nilai DAN 4.8) — P2.
 *
 * Memperluas RfqService: prc_rfq_quotes menyimpan HARGA per baris; kelas ini
 * menyimpan SKOR per vendor. Skor harga TIDAK diinput — dihitung dari rasio
 * penawaran vendor terhadap RAB. Empat aspek lain (mutu, waktu, keuangan, k3)
 * diinput 0–100. Total berbobot & peringkat dihitung atas seluruh vendor RFQ
 * sekaligus supaya peringkat selalu konsisten.
 */
class BidEvaluationService
{
    /**
     * Simpan/mutakhirkan penilaian satu atau beberapa vendor pada satu RFQ,
     * lalu peringkatkan ulang seluruh vendor lembar itu.
     *
     * @param  array<int, array{vendor_id:int, rab_amount?:float|int|string|null, offered_amount?:float|int|string|null, mutu_score?:float|int|string, waktu_score?:float|int|string, keuangan_score?:float|int|string, k3_score?:float|int|string, notes?:?string}>  $rows
     */
    public function evaluate(Rfq $rfq, array $rows): Rfq
    {
        if ($rfq->status !== DocumentStatus::Draft) {
            throw new LogicException(
                "RFQ {$rfq->code} berstatus {$rfq->status->value}; penilaian hanya bisa diisi saat lembar masih draf."
            );
        }

        $weights = BidWeights::weights(); // divalidasi (jumlah 100) di sini juga
        $invited = $rfq->vendors()->pluck('vendor_id')->all();

        return DB::transaction(function () use ($rfq, $rows, $weights, $invited): Rfq {
            foreach ($rows as $row) {
                $vendorId = (int) ($row['vendor_id'] ?? 0);

                if (! in_array($vendorId, $invited, true)) {
                    throw new LogicException(
                        "Vendor #{$vendorId} tidak diundang pada RFQ {$rfq->code}; hanya vendor yang diundang bisa dinilai."
                    );
                }

                $offered = isset($row['offered_amount']) && $row['offered_amount'] !== null
                    ? round((float) $row['offered_amount'], 2)
                    : $this->offeredTotal($rfq, $vendorId);

                $rab = isset($row['rab_amount']) && $row['rab_amount'] !== null
                    ? round((float) $row['rab_amount'], 2)
                    : $this->rabTotal($rfq);

                $hargaScore = $this->hargaScore($offered, $rab);

                $aspects = [
                    'harga' => $hargaScore,
                    'mutu' => $this->clampScore($row['mutu_score'] ?? 0),
                    'waktu' => $this->clampScore($row['waktu_score'] ?? 0),
                    'keuangan' => $this->clampScore($row['keuangan_score'] ?? 0),
                    'k3' => $this->clampScore($row['k3_score'] ?? 0),
                ];

                BidEvaluation::query()->updateOrCreate(
                    ['rfq_id' => $rfq->id, 'vendor_id' => $vendorId],
                    [
                        'rab_amount' => $rab,
                        'offered_amount' => $offered,
                        'harga_score' => $hargaScore,
                        'mutu_score' => $aspects['mutu'],
                        'waktu_score' => $aspects['waktu'],
                        'keuangan_score' => $aspects['keuangan'],
                        'k3_score' => $aspects['k3'],
                        'weighted_score' => $this->weightedScore($aspects, $weights),
                        'notes' => $row['notes'] ?? null,
                    ],
                );
            }

            $this->rank($rfq);

            return $rfq->load('bidEvaluations.vendor');
        });
    }

    /**
     * SKOR HARGA dari rasio penawaran terhadap RAB — 0..100.
     *
     * SUMBER. Perpres 16/2018 jo. 12/2021 dan pedoman evaluasi LKPP memakai
     * "sistem nilai" (merit point): nilai harga = (harga pembanding / harga yang
     * dinilai) × bobot. Pembanding baku LKPP adalah penawaran TERENDAH; di sini
     * pembandingnya adalah RAB/HPS (nilai wajar milik pemberi kerja), sebuah
     * pilihan yang DIDOKUMENTASIKAN karena kontraktor swasta menilai kewajaran
     * terhadap anggarannya sendiri, bukan terhadap vendor termurah yang mungkin
     * banting harga di bawah biaya.
     *
     * Rumus: skor = min(100, RAB / penawaran × 100).
     *   penawaran = RAB   -> 100  (tepat di anggaran)
     *   penawaran = 2×RAB -> 50   (dua kali anggaran, separuh nilai)
     *   penawaran < RAB   -> dibatasi 100 (penawaran di bawah anggaran tidak
     *                        boleh menggelembungkan total berbobot di atas
     *                        maksimum aspeknya; kewajaran penawaran sangat rendah
     *                        dinilai lewat aspek "keuangan", bukan di sini).
     *
     * CATATAN JUJUR: tabel bracket rasio→skor "DAN 4.8" milik pemilik tidak
     * dapat diverifikasi dari korpus publik, jadi ini adalah FALLBACK LINEAR yang
     * terdokumentasi (rumus merit-point LKPP terhadap RAB), bukan tabel bracket
     * karangan. Bila tabel DAN 4.8 asli tersedia, ganti isi metode ini saja —
     * pemanggilnya tidak berubah.
     */
    public function hargaScore(float $offered, ?float $rab): float
    {
        if ($rab === null || $rab <= 0.0 || $offered <= 0.0) {
            return 0.0;
        }

        return round(min(100.0, $rab / $offered * 100.0), 2);
    }

    /** Peringkat otomatis atas seluruh vendor RFQ: skor berbobot tertinggi = 1. */
    public function rank(Rfq $rfq): void
    {
        $evaluations = BidEvaluation::query()
            ->where('rfq_id', $rfq->id)
            // Skor tertinggi dulu; seri diputus oleh vendor_id agar deterministik.
            ->orderByDesc('weighted_score')
            ->orderBy('vendor_id')
            ->get();

        $rank = 0;

        foreach ($evaluations as $evaluation) {
            $evaluation->forceFill(['rank' => ++$rank])->save();
        }
    }

    /**
     * @param  array<string, float>  $aspects
     * @param  array<string, float>  $weights
     */
    private function weightedScore(array $aspects, array $weights): float
    {
        $total = 0.0;

        foreach (BidWeights::ASPECTS as $aspect) {
            $total += ($aspects[$aspect] ?? 0.0) * ($weights[$aspect] ?? 0.0) / 100.0;
        }

        return round($total, 2);
    }

    /** Total penawaran vendor ini: harga tercatat × qty, per baris yang ditawar. */
    private function offeredTotal(Rfq $rfq, int $vendorId): float
    {
        $total = 0.0;

        foreach ($rfq->items()->with(['quotes' => fn ($q) => $q->where('vendor_id', $vendorId)])->get() as $line) {
            $quote = $line->quotes->first();

            if ($quote !== null) {
                $total += round((float) $quote->unit_price * (float) $line->qty, 2);
            }
        }

        return round($total, 2);
    }

    /**
     * Nilai RAB pembanding: harga BOQ beku × qty baris, atas baris yang punya
     * tautan boq_item_id. Nol bila tidak ada tautan (evaluator lalu mengisi
     * rab_amount manual).
     */
    private function rabTotal(Rfq $rfq): float
    {
        if (! Schema::hasTable('est_boq_items')) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($rfq->items()->get() as $line) {
            if ($line->boq_item_id === null) {
                continue;
            }

            $unit = DB::table('est_boq_items')->where('id', $line->boq_item_id)->value('unit_price');

            if ($unit !== null) {
                $total += round((float) $unit * (float) $line->qty, 2);
            }
        }

        return round($total, 2);
    }

    private function clampScore(mixed $value): float
    {
        return round(max(0.0, min(100.0, (float) $value)), 2);
    }
}
