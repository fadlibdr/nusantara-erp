<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Models\Item;

/**
 * Satu kode yang dipindai (atau diketik) → item yang dimaksudnya (F-6).
 *
 * =========================== BARCODE GANDA ===========================
 * `inv_items.barcode` NULLABLE DAN TIDAK UNIK hari ini. Dua item yang
 * membawa barcode yang sama bukan kemungkinan teoretis: dua kardus dari
 * pemasok yang sama, satu kolom yang disalin saat menduplikasi kartu item,
 * satu impor master yang mengisi kolom yang salah.
 *
 * Endpoint ini karena itu TIDAK PERNAH memilih yang pertama. Ia mengembalikan
 * SEMUA yang cocok dan menyatakan `ambiguous`, dan layar menampilkan
 * daftarnya supaya orangnya yang memilih. Memilih diam-diam berarti stok
 * masuk ke kartu barang lain, dan tidak ada satu pun pesan yang muncul —
 * kesalahan itu baru terlihat pada opname berikutnya, berbulan kemudian,
 * sebagai dua selisih yang tidak ada penjelasannya.
 *
 * Kolomnya tidak dijadikan UNIQUE di sini dan itu disengaja: data produksi
 * mungkin sudah memuat duplikat, dan sebuah migrasi yang menambahkan UNIQUE
 * akan GAGAL saat deploy alih-alih memberi tahu siapa pun. Yang dikerjakan
 * paket ini adalah membuat duplikatnya TERLIHAT; memutuskan apakah kolom itu
 * harus unik adalah keputusan pemilik, dan ia tercatat di laporan paket.
 * =====================================================================
 *
 * COCOK PERSIS, BUKAN "like". Sebuah pemindaian adalah pembacaan mesin: ia
 * tepat atau ia gagal. Pencocokan sebagian akan membuat "ITM-001" menemukan
 * "ITM-0012" dan orang gudang tidak punya cara mengetahuinya. Pencarian
 * sebagian sudah ada tempatnya sendiri, di `GET inventory/items?q=`.
 */
class ItemScanController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
        ]);

        $code = trim($data['code']);

        $items = Item::query()
            ->with('category', 'balances.warehouse')
            ->where(function ($query) use ($code): void {
                $query->where('barcode', $code)->orWhere('code', $code);
            })
            ->orderBy('code')
            ->get();

        return $this->ok([
            'query' => $code,
            'matches' => $items->map(fn (Item $item): array => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
                'barcode' => $item->barcode,
                'category' => $item->category?->name,
                'is_active' => (bool) $item->is_active,
                // Kenapa item INI yang cocok. Satu kode bisa menjadi barcode
                // sebuah item DAN kode item lain sekaligus, dan orang yang
                // memilih di antara keduanya berhak tahu sebabnya.
                'matched_on' => $item->barcode === $code ? 'barcode' : 'code',
                'balances' => $item->balances
                    ->filter(fn ($balance) => $balance->warehouse !== null)
                    ->map(fn ($balance): array => [
                        'warehouse_id' => $balance->warehouse_id,
                        'warehouse_code' => $balance->warehouse->code,
                        'warehouse_name' => $balance->warehouse->name,
                        'qty' => (float) $balance->qty,
                    ])->values(),
            ])->values(),
            'status' => match (true) {
                $items->count() === 0 => 'none',
                $items->count() === 1 => 'one',
                default => 'ambiguous',
            },
            // Kalimatnya datang dari server, satu per keadaan, karena ketiganya
            // adalah keadaan yang BERBEDA dan layar yang menyusun kalimatnya
            // sendiri akan menyimpang dari aturan yang benar-benar dijalankan.
            'message' => match (true) {
                $items->count() === 0 => "Tidak ada item dengan barcode atau kode \"{$code}\". "
                    .'Periksa apakah kartu itemnya sudah mencatat barcode ini — kolom Barcode di layar Item.',
                $items->count() === 1 => 'Satu item cocok.',
                default => $items->count().' ITEM memakai kode yang sama ("'.$code.'"). '
                    .'Pilih sendiri yang dimaksud: memilihkan salah satunya akan memasukkan stok ke kartu '
                    .'barang yang keliru, dan kekeliruan itu baru terlihat pada opname berikutnya.',
            },
        ]);
    }
}
