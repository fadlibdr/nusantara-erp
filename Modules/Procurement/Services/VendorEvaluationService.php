<?php

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\NotificationService;
use Modules\Core\Support\Money;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorEvaluation;

class VendorEvaluationService
{
    /**
     * Evaluasi tanpa skor kirim mengambilnya dari bukti, bukan dari perasaan:
     * riwayat GRN vs tanggal janji PO (deliverySnapshot). Vendor tanpa
     * riwayat tetap wajib diisi manual — 422, bukan angka karangan.
     */
    public function create(array $data): VendorEvaluation
    {
        if (! isset($data['delivery_score'])) {
            $snapshot = $this->deliverySnapshot((int) $data['vendor_id']);

            if ($snapshot === null) {
                throw ValidationException::withMessages([
                    'delivery_score' => 'Skor kirim tidak bisa dihitung otomatis — belum ada GRN terposting '
                        .'untuk PO ber-tanggal-janji vendor ini. Isi skor kirim manual.',
                ]);
            }

            $data['delivery_score'] = $snapshot['suggested_score'];
            // Jejak dasar penilaian menempel di evaluasinya, supaya angka
            // otomatis tetap bisa dipertanyakan setahun kemudian.
            $provenance = "Skor kirim {$snapshot['suggested_score']} dihitung otomatis: "
                ."{$snapshot['on_time']} dari {$snapshot['considered']} GRN tepat waktu ({$snapshot['on_time_pct']}%).";
            $data['notes'] = trim(($data['notes'] ?? '')."\n".$provenance);
        }

        return DB::transaction(function () use ($data): VendorEvaluation {
            $evaluation = new VendorEvaluation($data);
            $evaluation->total_score = $this->totalScore($data);
            $evaluation->save();

            $this->refreshVendorRating($evaluation->vendor);

            return $evaluation->load('vendor');
        });
    }

    public function update(VendorEvaluation $evaluation, array $data): VendorEvaluation
    {
        return DB::transaction(function () use ($evaluation, $data): VendorEvaluation {
            $evaluation->fill($data);
            $evaluation->total_score = $this->totalScore($evaluation->only([
                'quality_score', 'delivery_score', 'price_score', 'service_score',
            ]));
            $evaluation->save();

            $this->refreshVendorRating($evaluation->vendor);

            return $evaluation->load('vendor');
        });
    }

    public function delete(VendorEvaluation $evaluation): void
    {
        DB::transaction(function () use ($evaluation): void {
            $vendor = $evaluation->vendor;
            $evaluation->delete();

            $this->refreshVendorRating($vendor);
        });
    }

    /**
     * Equal-weight average of the four criteria, on the same 1-5 scale.
     */
    public function totalScore(array $scores): float
    {
        $sum = (int) ($scores['quality_score'] ?? 0)
            + (int) ($scores['delivery_score'] ?? 0)
            + (int) ($scores['price_score'] ?? 0)
            + (int) ($scores['service_score'] ?? 0);

        return round($sum / 4, 2);
    }

    /**
     * Vendor rating = rolling average of every evaluation's total_score,
     * kept to one decimal (matches the rating column precision).
     */
    public function refreshVendorRating(?Vendor $vendor): void
    {
        if ($vendor === null) {
            return;
        }

        $average = $vendor->evaluations()->avg('total_score');

        $vendor->forceFill([
            'rating' => $average !== null ? round((float) $average, 1) : null,
        ])->save();
    }

    /**
     * Rekam jejak pengiriman vendor: setiap GRN TERPOSTING terhadap PO
     * ber-tanggal-janji vendor ini, tepat waktu bila receipt_date <=
     * expected_date (hari-H masih tepat waktu — janji "dikirim 10 Maret"
     * ditepati pada 10 Maret).
     *
     * GRN draf belum jadi bukti kiriman; PO tanpa expected_date tidak bisa
     * dinilai karena janji tanpa tanggal tidak bisa diingkari. NULL bila tidak
     * ada satu pun dasar — jawaban jujur "tidak tahu", bukan skor karangan.
     *
     * Peta persentase → skor 1-5 memakai tangga yang kasar dengan sengaja:
     * >=95% nyaris sempurna (5), >=85% baik (4), >=70% cukup (3), >=50%
     * buruk (2), sisanya 1. Ini SARAN yang bisa ditimpa evaluator — angkanya
     * pindah ke delivery_score biasa dan ikut aturan skor manual.
     *
     * Query polos lintas modul (tanpa model/FK Inventory by design — pola
     * PoService::itemName), dan diam-diam absen bila modul Inventory belum
     * termigrasi.
     *
     * @return array{considered: int, on_time: int, late: int, on_time_pct: float, suggested_score: int}|null
     */
    public function deliverySnapshot(int $vendorId): ?array
    {
        if (! Schema::hasTable('inv_goods_receipts')) {
            return null;
        }

        $rows = DB::table('inv_goods_receipts as grn')
            ->join('prc_purchase_orders as po', 'po.id', '=', 'grn.purchase_order_id')
            ->where('po.vendor_id', $vendorId)
            ->whereNull('po.deleted_at')
            ->whereNotNull('po.expected_date')
            ->where('grn.status', 'posted') // Inventory StockDocumentStatus::Posted
            ->whereNull('grn.deleted_at')
            ->get(['grn.receipt_date', 'po.expected_date']);

        if ($rows->isEmpty()) {
            return null;
        }

        // substr 10: kolom date SQLite bisa tersimpan "2026-03-10" ATAU
        // "2026-03-10 00:00:00" — footgun yang didokumentasikan WatchedDeadlines.
        $onTime = $rows
            ->filter(fn (object $row): bool => substr((string) $row->receipt_date, 0, 10) <= substr((string) $row->expected_date, 0, 10))
            ->count();

        $considered = $rows->count();
        $pct = round($onTime / $considered * 100, 1);

        return [
            'considered' => $considered,
            'on_time' => $onTime,
            'late' => $considered - $onTime,
            'on_time_pct' => $pct,
            'suggested_score' => match (true) {
                $pct >= 95.0 => 5,
                $pct >= 85.0 => 4,
                $pct >= 70.0 => 3,
                $pct >= 50.0 => 2,
                default => 1,
            },
        ];
    }

    /**
     * Ajakan mengisi evaluasi saat PO bernilai besar ditutup (temuan #68:
     * demo punya 1 evaluasi untuk 5 vendor — loop-nya tidak menutup sendiri).
     *
     * "Besar" = total >= erp.procurement.evaluation_threshold. Vendor yang
     * baru dievaluasi 6 bulan terakhir tidak diganggu: prompt yang mengulang
     * pekerjaan selesai adalah noise, dan noise adalah cara inbox berhenti
     * dibaca. Kalimatnya dikembalikan untuk pesan HTTP DAN dikirim sebagai
     * notifikasi ke pemegang prc.create (bukan hanya penutup PO — yang
     * menutup belum tentu yang mengevaluasi); signature per kode PO supaya
     * PO besar berikutnya tetap menyalak walau yang lama belum dibaca.
     */
    public function promptEvaluationIfDue(PurchaseOrder $po): ?string
    {
        $threshold = (float) config('erp.procurement.evaluation_threshold', 100_000_000);

        if ((float) $po->total < $threshold) {
            return null;
        }

        $vendor = $po->vendor;

        if ($vendor === null) {
            return null;
        }

        $recentlyEvaluated = $vendor->evaluations()
            ->where('created_at', '>=', now()->subMonths(6))
            ->exists();

        if ($recentlyEvaluated) {
            return null;
        }

        $prompt = "Nilai {$po->code} (".Money::format((float) $po->total, false).') mencapai ambang evaluasi dan '
            ."{$vendor->name} belum dievaluasi 6 bulan terakhir — isi Evaluasi Vendor.";

        app(NotificationService::class)->system(
            'prc.create',
            'Evaluasi vendor diperlukan',
            $prompt,
            'r/procurement/vendor-evaluations',
            null,
            $po->code,
        );

        return $prompt;
    }
}
