<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Requests\ReorderRuleStoreRequest;
use Modules\Inventory\Http\Requests\ReorderRuleUpdateRequest;
use Modules\Inventory\Http\Resources\ReorderRuleResource;
use Modules\Inventory\Models\ReorderRule;

/**
 * Aturan titik pesan ulang per gudang × item (F-6).
 *
 * Master data biasa dengan izin inv.* yang sudah ada — tetapi baris di sini
 * MENGUBAH ARTI sebuah angka yang dibaca empat permukaan lain (layar Saldo
 * Stok, widget dasbor, ubin launcher lewat registri ModuleCounts, dan usulan
 * PR). Karena itu dua hal berbeda dari CRUD kebanyakan:
 *
 *  - `item` selalu ikut dimuat DENGAN min_stock-nya, supaya daftar aturan bisa
 *    mencetak angka yang digantikan di sebelah angka penggantinya;
 *  - penghapusan benar-benar menghapus (tabelnya tanpa softDeletes, lihat
 *    migrasi 001700), jadi tombol yang dipakai untuk "matikan sementara"
 *    adalah is_active, bukan Hapus.
 */
class ReorderRuleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ReorderRule::query()
            ->with('warehouse', 'item')
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('item_id'), fn ($query) => $query->where('item_id', $request->integer('item_id')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                // Pencarian atas ITEM, bukan atas kolom tabel ini: tabel ini
                // tidak punya satu pun kolom teks yang dicari orang, dan yang
                // dicari orang adalah "aturan untuk semen".
                $query->whereHas('item', function ($item) use ($q): void {
                    $item->withTrashed()
                        ->where(function ($where) use ($q): void {
                            $where->where('code', 'like', "%{$q}%")
                                ->orWhere('name', 'like', "%{$q}%");
                        });
                });
            })
            ->orderBy('warehouse_id')
            ->orderBy('item_id');

        return $this->listing($request, $query, ReorderRuleResource::class,
            sortable: ['reorder_point', 'reorder_qty', 'is_active']);
    }

    public function store(ReorderRuleStoreRequest $request): JsonResponse
    {
        $rule = ReorderRule::query()->create($request->validated());

        return $this->created(ReorderRuleResource::make($rule->load('warehouse', 'item')));
    }

    public function show(ReorderRule $reorderRule): JsonResponse
    {
        return $this->ok(ReorderRuleResource::make($reorderRule->load('warehouse', 'item')));
    }

    public function update(ReorderRuleUpdateRequest $request, ReorderRule $reorderRule): JsonResponse
    {
        $reorderRule->update($request->validated());

        return $this->ok(ReorderRuleResource::make($reorderRule->load('warehouse', 'item')));
    }

    public function destroy(ReorderRule $reorderRule): JsonResponse
    {
        $reorderRule->delete();

        return $this->ok(null, 'Aturan reorder dihapus.');
    }
}
