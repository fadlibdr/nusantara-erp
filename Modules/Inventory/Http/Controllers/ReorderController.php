<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Services\ReorderService;

/**
 * Usulan PR dari kekurangan stok (F-6).
 *
 * DUA ENDPOINT, DAN PERBEDAANNYA ADALAH SELURUH ISINYA: yang pertama MEMBACA
 * dan tidak menyimpan apa pun; yang kedua membuat dokumen DRAF dan berhenti di
 * situ. Tidak ada endpoint ketiga yang mengajukan atau menyetujui, dan itu
 * bukan kelalaian — lihat docblock ReorderService.
 *
 * Gerbang izinnya prc.create, bukan inv.*, dan itu keputusan yang sama dengan
 * "printing is reading in another shape": yang dibuat di sini adalah dokumen
 * Procurement, jadi ia menuntut hak yang sama dengan layar PR sendiri, dari
 * layar mana pun tombolnya ditekan. Pembacaannya tetap terbuka bagi sesi mana
 * pun, sama seperti daftar Inventory lain di sekitarnya — tetapi orang yang
 * tidak boleh membuat PR mendapat daftar kekurangan dan tidak mendapat
 * tombolnya.
 */
class ReorderController extends ApiController
{
    public function __construct(private readonly ReorderService $reorder) {}

    public function proposal(Request $request): JsonResponse
    {
        $warehouseId = $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null;

        return $this->ok($this->reorder->proposal($warehouseId));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // `exists` DI SINI JUGA, sama seperti ReorderRuleStoreRequest.
            // Tanpanya sebuah gudang yang tidak ada menjawab 200 dengan
            // "tidak ada kekurangan yang tersisa" — jawaban yang benar untuk
            // pertanyaan yang salah, dan permukaan saudaranya sudah menolak
            // gudang yang sama sejak paket ini lahir.
            'warehouse_id' => ['nullable', 'integer', Rule::exists('inv_warehouses', 'id')->whereNull('deleted_at')],
            'needed_date' => ['nullable', 'date'],
        ]);

        $warehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;

        $created = $this->reorder->createDraftRequisitions(
            $warehouseId,
            $request->user(),
            $data['needed_date'] ?? null,
        );

        if ($created === []) {
            /*
             * 200 dengan daftar kosong, bukan 422: "tidak ada yang tersisa
             * untuk diusulkan" adalah jawaban yang benar, bukan permintaan
             * yang salah.
             *
             * TETAPI KOSONG PUNYA DUA SEBAB, DAN SATU KALIMAT UNTUK KEDUANYA
             * ADALAH KALIMAT YANG SALAH SETENGAH WAKTU. Dulu setiap hasil
             * kosong berbunyi "semuanya sudah ada di PR terbuka" — termasuk
             * bagi gudang yang tidak punya satu pun kekurangan, yang lalu
             * mengirim orangnya mencari PR yang tidak pernah ada. Servernya
             * memegang angka yang membedakannya: `skipped` adalah jumlah baris
             * yang benar-benar tertutup PR terbuka.
             */
            $skipped = $this->reorder->proposal($warehouseId)['rules']['skipped'];

            return $this->ok([
                'created' => [],
                'message' => $skipped > 0
                    ? 'Tidak ada kekurangan yang tersisa untuk diusulkan — semuanya sudah ada di PR atau PO terbuka.'
                    : 'Tidak ada pasangan gudang × item yang berada di bawah ambangnya. Tidak ada yang perlu diusulkan.',
            ]);
        }

        return $this->created([
            'created' => array_map(fn ($pr): array => [
                'id' => $pr->id,
                'code' => $pr->code,
                'status' => $pr->status->value,
                'status_label' => $pr->status->label(),
                'warehouse_id' => $pr->warehouse_id,
                'line_count' => $pr->items->count(),
            ], $created),
            'message' => count($created) === 1
                ? 'Satu PR draf dibuat. Periksa dan ajukan sendiri dari layar Permintaan Pembelian.'
                : count($created).' PR draf dibuat (satu per gudang). Periksa dan ajukan sendiri dari layar Permintaan Pembelian.',
        ]);
    }
}
