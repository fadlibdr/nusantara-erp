<?php

namespace Modules\Procurement\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\RfqItem;
use Modules\Procurement\Models\RfqQuote;
use Modules\Procurement\Models\Vendor;

/**
 * Temuan #34 tahap 3 — RFQ: lembar banding penawaran vendor.
 *
 * Alur: RFQ lahir dari PR disetujui (baris tersalin, tautan anggaran ikut)
 * atau berdiri sendiri; vendor diundang; staf pengadaan MENGETIKKAN harga
 * yang diterimanya per vendor per baris (tanpa portal, tanpa email — sengaja
 * kurus); pemenang dipilih per baris atau satu vendor sekaligus; "Buat PO
 * dari RFQ" membawa harga pemenang ke PO draf tanpa pengetikan ulang.
 *
 * RFQ TIDAK menutup diri saat PO lahir: pemenang boleh terbelah ke beberapa
 * vendor, dan setiap vendor pemenang butuh PO-nya sendiri. Menutup adalah
 * keputusan staf (close()) setelah semua PO-nya lahir.
 */
class RfqService
{
    public function __construct(private readonly PoService $poService) {}

    public function create(array $data): Rfq
    {
        return DB::transaction(function () use ($data): Rfq {
            $items = Arr::pull($data, 'items', []);
            $vendorIds = Arr::pull($data, 'vendor_ids', []);

            $pr = null;

            if (! empty($data['purchase_requisition_id'])) {
                $pr = PurchaseRequisition::query()->findOrFail((int) $data['purchase_requisition_id']);

                // Kontrak yang sama dengan PoService::createFromPr: harga
                // dicarikan untuk kebutuhan yang sudah disetujui — RFQ atas
                // PR draf akan membandingkan harga barang yang daftarnya
                // masih bisa berubah besok pagi.
                if ($pr->status !== DocumentStatus::Approved) {
                    throw new LogicException("PR {$pr->code} harus disetujui sebelum dibuatkan RFQ.");
                }
            }

            $rfq = new Rfq(Arr::except($data, ['code', 'status']));
            $rfq->project_id ??= $pr?->project_id;
            $rfq->status = DocumentStatus::Draft;
            $rfq->save(); // HasDocumentNumber mengisi kode RFQ

            if ($pr !== null) {
                // Baris PR disalin apa adanya; baris eksplisit diabaikan agar
                // lembar banding dan PR tidak diam-diam berbeda isi.
                $lineNo = 0;

                foreach ($pr->items as $line) {
                    $rfq->items()->create([
                        'line_no' => ++$lineNo,
                        'item_id' => $line->item_id,
                        'boq_item_id' => $line->boq_item_id, // tautan anggaran hidup terus sampai PO
                        'description' => $line->description ?? '-',
                        'qty' => round((float) $line->qty, 3),
                        'unit' => $line->unit,
                    ]);
                }
            } else {
                if ($items === []) {
                    // Cermin required_without di RfqStoreRequest, untuk jalur
                    // non-HTTP: lembar banding tanpa baris tidak membandingkan
                    // apa-apa.
                    throw new LogicException('RFQ mandiri butuh minimal satu baris barang.');
                }

                $this->syncItems($rfq, $items);
            }

            $this->syncVendors($rfq, $vendorIds);

            return $rfq->load('items', 'vendors.vendor');
        });
    }

    public function update(Rfq $rfq, array $data): Rfq
    {
        $this->assertEditable($rfq);

        return DB::transaction(function () use ($rfq, $data): Rfq {
            $items = Arr::pull($data, 'items');
            $vendorIds = Arr::pull($data, 'vendor_ids');

            $rfq->fill(Arr::except($data, ['code', 'status', 'purchase_requisition_id']));
            $rfq->save();

            if (is_array($items)) {
                // Bukti banding harga: begitu ada PO menunjuk lembar ini,
                // barisnya tidak boleh ditulis ulang — delete() sudah menolak,
                // dan update yang menulis ulang baris adalah penghapusan bukti
                // lewat pintu samping. Judul/catatan tetap boleh.
                if ($this->lineSetChanges($rfq, $items) && $rfq->purchaseOrders()->withTrashed()->exists()) {
                    throw new LogicException(
                        "RFQ {$rfq->code} sudah menjadi dasar harga sebuah PO; barisnya tidak dapat diubah lagi — "
                        .'perubahan barang berarti lembar banding baru.'
                    );
                }

                $this->syncItems($rfq, $items);
            }

            if (is_array($vendorIds)) {
                $this->syncVendors($rfq, $vendorIds);
            }

            return $rfq->load('items.quotes', 'vendors.vendor');
        });
    }

    public function delete(Rfq $rfq): void
    {
        $this->assertEditable($rfq);

        if ($rfq->purchaseOrders()->withTrashed()->exists()) {
            // PO-nya menunjuk kemari sebagai dasar harga; menghapus lembarnya
            // menghapus bukti banding harga PO tersebut.
            throw new LogicException("RFQ {$rfq->code} sudah melahirkan PO dan tidak dapat dihapus.");
        }

        $rfq->delete();
    }

    /**
     * Staf pengadaan mengetikkan harga yang diterimanya: upsert per
     * (baris, vendor) — mengetik ulang memperbarui sel, bukan menumpuk baris.
     *
     * @param  array<int, array{rfq_item_id: int, vendor_id: int, unit_price: float|int|string, notes?: ?string}>  $quotes
     */
    public function recordQuotes(Rfq $rfq, array $quotes): Rfq
    {
        $this->assertEditable($rfq);

        return DB::transaction(function () use ($rfq, $quotes): Rfq {
            $itemIds = $rfq->items()->pluck('id')->all();
            $invited = $rfq->vendors()->pluck('vendor_id')->all();

            foreach ($quotes as $quote) {
                $itemId = (int) $quote['rfq_item_id'];
                $vendorId = (int) $quote['vendor_id'];

                if (! in_array($itemId, $itemIds, true)) {
                    throw new LogicException("Baris {$itemId} bukan baris RFQ {$rfq->code}.");
                }

                // Pagar undangan: tanpa ini harga "pemenang" bisa berasal dari
                // vendor yang tidak pernah diajak banding — tabulasinya tampak
                // sah padahal bandingnya tidak pernah terjadi.
                if (! in_array($vendorId, $invited, true)) {
                    $vendor = Vendor::query()->find($vendorId);

                    throw new LogicException(sprintf(
                        'Vendor %s tidak diundang pada RFQ %s; tambahkan dulu ke daftar undangan.',
                        $vendor?->name ?? "#{$vendorId}",
                        $rfq->code,
                    ));
                }

                RfqQuote::query()->updateOrCreate(
                    ['rfq_item_id' => $itemId, 'vendor_id' => $vendorId],
                    [
                        'unit_price' => round((float) $quote['unit_price'], 2),
                        'notes' => $quote['notes'] ?? null,
                    ],
                );
            }

            return $rfq->load('items.quotes', 'vendors.vendor');
        });
    }

    /**
     * Pemenang per baris ($rfqItemId terisi) atau satu vendor untuk seluruh
     * lembar. Pilihan baru menggantikan pilihan lama pada baris yang sama —
     * satu baris satu pemenang.
     */
    public function chooseWinner(Rfq $rfq, int $vendorId, ?int $rfqItemId = null): Rfq
    {
        $this->assertEditable($rfq);

        return DB::transaction(function () use ($rfq, $vendorId, $rfqItemId): Rfq {
            if (! $rfq->vendors()->where('vendor_id', $vendorId)->exists()) {
                throw new LogicException("Vendor #{$vendorId} tidak diundang pada RFQ {$rfq->code}.");
            }

            /** @var Collection<int, RfqItem> $lines */
            $lines = $rfqItemId !== null
                ? $rfq->items()->whereKey($rfqItemId)->get()
                : $rfq->items()->get();

            if ($rfqItemId !== null && $lines->isEmpty()) {
                throw new LogicException("Baris {$rfqItemId} bukan baris RFQ {$rfq->code}.");
            }

            // Vendor hanya bisa memenangkan baris yang DIA tawar. Menyebut
            // nomor barisnya membuat penolakan bisa ditindaklanjuti: lengkapi
            // harganya, atau pilih pemenang per baris.
            $unquoted = $lines->filter(
                fn (RfqItem $line): bool => ! $line->quotes()->where('vendor_id', $vendorId)->exists()
            );

            if ($unquoted->isNotEmpty()) {
                throw new LogicException(sprintf(
                    'Vendor belum menawar baris %s pada RFQ %s; lengkapi harganya dulu atau pilih pemenang per baris.',
                    $unquoted->pluck('line_no')->implode(', '),
                    $rfq->code,
                ));
            }

            foreach ($lines as $line) {
                // Satu pemenang per baris: bendera lama diturunkan dulu.
                $line->quotes()->update(['is_winner' => false]);
                $line->quotes()->where('vendor_id', $vendorId)->update(['is_winner' => true]);
            }

            return $rfq->load('items.quotes', 'vendors.vendor');
        });
    }

    /**
     * PO draf berisi baris-baris kemenangan SATU vendor, pada harga penawaran
     * pemenangnya — tanpa pengetikan ulang. Bila pemenang terbelah ke
     * beberapa vendor, panggil sekali per vendor.
     */
    public function createPo(Rfq $rfq, array $data): PurchaseOrder
    {
        $this->assertEditable($rfq);

        return DB::transaction(function () use ($rfq, $data): PurchaseOrder {
            $winners = RfqQuote::query()
                ->where('is_winner', true)
                ->whereIn('rfq_item_id', $rfq->items()->pluck('id'))
                ->get();

            if ($winners->isEmpty()) {
                throw new LogicException("RFQ {$rfq->code} belum punya pemenang; pilih pemenang dulu.");
            }

            $winningVendorIds = $winners->pluck('vendor_id')->unique()->values();
            $vendorId = isset($data['vendor_id']) ? (int) $data['vendor_id'] : null;

            if ($vendorId === null) {
                if ($winningVendorIds->count() > 1) {
                    throw new LogicException(sprintf(
                        'Pemenang RFQ %s terbelah ke %d vendor; sebutkan vendor_id — satu PO per vendor pemenang.',
                        $rfq->code,
                        $winningVendorIds->count(),
                    ));
                }

                $vendorId = (int) $winningVendorIds->first();
            }

            $vendorWins = $winners->where('vendor_id', $vendorId)->keyBy('rfq_item_id');

            if ($vendorWins->isEmpty()) {
                throw new LogicException("Vendor #{$vendorId} tidak memenangkan satu baris pun pada RFQ {$rfq->code}.");
            }

            $vendor = Vendor::query()->findOrFail($vendorId);

            // Gate prakualifikasi (temuan #35) — kontrak yang sama dengan
            // PoService::createFromPr: alasan tersimpan hanya bila gate
            // benar-benar mengembalikan blokir yang dilewati.
            $reason = trim((string) ($data['qualification_override_reason'] ?? ''));
            $overridden = app(VendorQualificationService::class)
                ->assertQualified($vendor, $reason === '' ? null : $reason);

            $po = new PurchaseOrder([
                'vendor_id' => $vendor->id,
                'purchase_requisition_id' => $rfq->purchase_requisition_id,
                'project_id' => $rfq->project_id,
                'warehouse_id' => $rfq->purchaseRequisition?->warehouse_id,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'payment_term_days' => $data['payment_term_days'] ?? $vendor->payment_term_days,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'delivery_address' => $data['delivery_address'] ?? null,
                'notes' => $data['notes'] ?? "Dibuat dari {$rfq->code} (harga pemenang banding).",
                'qualification_override_reason' => $overridden !== [] ? $reason : null,
            ]);
            $po->rfq_id = $rfq->id; // jejak dasar harga; sengaja di luar mass-assignment create()
            $po->status = DocumentStatus::Draft;
            $po->save();

            $lineNo = 0;

            foreach ($rfq->items as $line) {
                /** @var RfqQuote|null $win */
                $win = $vendorWins->get($line->id);

                if ($win === null) {
                    continue; // baris ini dimenangkan vendor lain (atau belum diputus)
                }

                $qty = round((float) $line->qty, 3);
                $unitPrice = round((float) $win->unit_price, 2);

                $po->items()->create([
                    'line_no' => ++$lineNo,
                    'item_id' => $line->item_id,
                    'boq_item_id' => $line->boq_item_id, // kendali harga #34 tetap hidup di PO ini
                    'description' => $line->description,
                    'qty' => $qty,
                    'unit' => $line->unit,
                    'unit_price' => $unitPrice,
                    'amount' => round($qty * $unitPrice, 2),
                ]);
            }

            $this->poService->recalcTotals($po);

            return $po->load('items', 'vendor');
        });
    }

    /**
     * Arsipkan lembar yang bandingnya selesai: sel harga dan pemenangnya beku.
     */
    public function close(Rfq $rfq): Rfq
    {
        $this->assertEditable($rfq);

        $rfq->forceFill(['status' => DocumentStatus::Closed])->save();

        return $rfq;
    }

    /**
     * Baris yang DIPERTAHANKAN mempertahankan sel harganya. Ubah generik
     * selalu mengirim items, dan sync hapus-semua-buat-ulang membuat FK
     * cascade menghanguskan seluruh matriks penawaran hanya karena judulnya
     * diedit. Maka: baris ber-id yang masih ada di payload di-update di
     * tempat, baris baru dibuat, dan HANYA baris yang benar-benar hilang
     * dihapus (cascade sel harganya memang benar untuk baris yang hilang —
     * tabulasi lama atas barang baru adalah perbandingan yang bohong).
     */
    private function syncItems(Rfq $rfq, array $items): void
    {
        $keptIds = array_values(array_filter(array_map(
            static fn (array $item): ?int => isset($item['id']) ? (int) $item['id'] : null,
            $items,
        )));

        $rfq->items()->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))->delete();

        $existing = $rfq->items()->get()->keyBy('id');
        $lineNo = 0;

        foreach ($items as $item) {
            $attributes = [
                'line_no' => ++$lineNo,
                'item_id' => $item['item_id'] ?? null,
                'boq_item_id' => $item['boq_item_id'] ?? null,
                'description' => $item['description'],
                'qty' => round((float) ($item['qty'] ?? 1), 3),
                'unit' => $item['unit'] ?? null,
            ];

            $current = isset($item['id']) ? $existing->get((int) $item['id']) : null;

            if ($current !== null) {
                // boq_item_id yang tidak dikirim payload tidak boleh menghapus
                // tautan yang tersimpan — form generik tidak membawanya.
                if (! array_key_exists('boq_item_id', $item)) {
                    $attributes['boq_item_id'] = $current->boq_item_id;
                }

                $current->fill($attributes)->save();

                continue;
            }

            $rfq->items()->create($attributes);
        }
    }

    /**
     * Apakah payload items benar-benar mengubah HIMPUNAN baris (barang, qty),
     * bukan sekadar mengirim ulang yang sudah ada? Pembeda antara "Ubah judul"
     * (boleh kapan pun) dan "tulis ulang bukti" (ditolak begitu ada PO).
     */
    private function lineSetChanges(Rfq $rfq, array $items): bool
    {
        $stored = $rfq->items()->orderBy('line_no')->get()
            ->map(fn (RfqItem $line): array => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'description' => $line->description,
                'qty' => round((float) $line->qty, 3),
            ])->values()->all();

        $incoming = array_values(array_map(static fn (array $item): array => [
            'id' => isset($item['id']) ? (int) $item['id'] : null,
            'item_id' => $item['item_id'] ?? null,
            'description' => $item['description'] ?? '',
            'qty' => round((float) ($item['qty'] ?? 1), 3),
        ], $items));

        return $stored != $incoming;
    }

    private function syncVendors(Rfq $rfq, array $vendorIds): void
    {
        $vendorIds = array_values(array_unique(array_map('intval', $vendorIds)));

        // Mencabut undangan mencabut sel harganya: harga vendor yang tidak
        // lagi diundang tidak boleh diam-diam tetap ikut dibandingkan.
        $removed = $rfq->vendors()->whereNotIn('vendor_id', $vendorIds)->pluck('vendor_id');

        if ($removed->isNotEmpty()) {
            RfqQuote::query()
                ->whereIn('rfq_item_id', $rfq->items()->pluck('id'))
                ->whereIn('vendor_id', $removed)
                ->delete();

            $rfq->vendors()->whereIn('vendor_id', $removed)->delete();
        }

        foreach ($vendorIds as $vendorId) {
            Vendor::query()->findOrFail($vendorId); // undangan untuk vendor yang ada

            $rfq->vendors()->firstOrCreate(['vendor_id' => $vendorId]);
        }
    }

    private function assertEditable(Rfq $rfq): void
    {
        if ($rfq->status !== DocumentStatus::Draft) {
            throw new LogicException(
                "RFQ {$rfq->code} berstatus {$rfq->status->value} dan tidak dapat diubah lagi."
            );
        }
    }
}
