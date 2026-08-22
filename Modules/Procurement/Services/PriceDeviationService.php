<?php

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Money;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderItem;

/**
 * Temuan #34 tahap 2 — kendali harga: harga PO vs harga BOQ yang dibekukan.
 *
 * Harga satuan BOQ dibekukan ketika penawarannya dimenangkan; itulah janji
 * marjin proyek. Harga PO adalah kenyataan pasarnya. Sebelum gate ini kedua
 * angka tidak pernah bertemu: pembelian 15-20% di atas harga RAB lolos tanpa
 * satu pun mata membandingkannya, dan selisihnya baru kelihatan berbulan-bulan
 * kemudian di laporan profitabilitas — saat uangnya sudah keluar.
 *
 * PERINGATAN, BUKAN BLOKIR (resepnya menyebut "peringatan"): eskalasi harga
 * itu nyata dan membeli tetap harus bisa. Yang diminta adalah pengakuan
 * eksplisit — pola confirm-resubmit temuan #72: 422 pada items.N.unit_price
 * sampai payload membawa confirm_price_deviation, dan pesannya sendiri
 * menyebut keempat angkanya (harga PO, harga BOQ, penyimpangan, ambang) agar
 * yang dikonfirmasi adalah angka, bukan kalimat kosong.
 *
 * Hanya penyimpangan KE ATAS yang diperingatkan: membeli lebih murah dari RAB
 * bukan bahaya marjin. Harga nol yang mencurigakan punya penjaganya sendiri di
 * penerimaan (GRN confirm_zero_cost, temuan #72), dan sisi rupiah total dijaga
 * gate anggaran (#33) — gate ini khusus mengurus harga satuan.
 */
class PriceDeviationService
{
    /**
     * @throws ValidationException satu pesan per baris yang menyimpang
     */
    public function assertConfirmedIfDeviant(PurchaseOrder $po, bool $confirmed): void
    {
        if ($confirmed) {
            // Pengakuan datang dari dialog SPA yang menampilkan pesan-pesan di
            // bawah apa adanya; jejak persetujuannya adalah baris `submitted`
            // di core_approvals atas nama pengaju yang mengonfirmasi.
            return;
        }

        // Modul Estimation boleh absen; tanpa tabelnya tidak ada pembanding.
        if (! Schema::hasTable('est_boq_items')) {
            return;
        }

        $lines = $po->items()->whereNotNull('boq_item_id')->get();

        if ($lines->isEmpty()) {
            return;
        }

        $threshold = (float) config('erp.procurement.price_warning_pct', 10);

        $boqPrices = DB::table('est_boq_items')
            ->whereIn('id', $lines->pluck('boq_item_id')->all())
            ->pluck('unit_price', 'id');

        $messages = [];

        foreach ($lines as $line) {
            /** @var PurchaseOrderItem $line */
            $boqPrice = round((float) ($boqPrices[$line->boq_item_id] ?? 0), 2);

            // Baris BOQ tanpa harga (atau sudah terhapus): persentase tak
            // terdefinisi, tidak ada janji harga yang bisa dilanggar.
            if ($boqPrice <= 0) {
                continue;
            }

            $deviationPct = round(((float) $line->unit_price - $boqPrice) / $boqPrice * 100, 2);

            if ($deviationPct <= $threshold) {
                continue;
            }

            // Kunci items.N.unit_price adalah kontrak confirmResubmit SPA
            // (pola GRN harga 0); N memakai line_no supaya pesan dan layar
            // menyebut baris yang sama.
            $messages["items.{$line->line_no}.unit_price"] = sprintf(
                'Baris %d "%s": harga PO %s di atas harga BOQ beku %s (+%s%%, ambang %s%%). '
                .'Ajukan ulang dengan konfirmasi bila harga ini memang hasil negosiasi terbaik.',
                (int) $line->line_no,
                $line->description,
                Money::format((float) $line->unit_price, false),
                Money::format($boqPrice, false),
                self::pct($deviationPct),
                self::pct($threshold),
            );
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * "15", bukan "15,00" — angka yang diteriakkan dialog konfirmasi harus
     * enak dibaca; dua desimal hanya tampil bila memang ada.
     */
    private static function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
