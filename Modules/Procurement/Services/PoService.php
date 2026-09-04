<?php

namespace Modules\Procurement\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderItem;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Support\DirectorApproval;

class PoService
{
    /**
     * Approval goes through the service so the director gate runs at all: the
     * controller used to call $po->approve() directly, which is how
     * PO/2026/II/0001 (Rp 232.545.000, above the Rp 100 juta threshold) got
     * approved by a non-director while flagged "Perlu persetujuan direktur".
     * Maker-checker still runs inside Approvable::approve, so a director who
     * submitted the PO remains refused.
     */
    public function approve(PurchaseOrder $po, User $by, ?string $note = null): PurchaseOrder
    {
        // Kriteria #4 (P2): PO yang lahir dari RFQ tidak boleh disetujui sebelum
        // keputusan pemenang (award) untuk vendornya disetujui. Inert untuk PO
        // biasa (rfq_id null).
        app(AwardDecisionService::class)->assertApprovedAward($po, $po->rfq_id, (int) $po->vendor_id);

        DirectorApproval::assertMayApprove(
            $po,
            $by,
            (float) $po->total,
            PurchaseOrder::directorApprovalThreshold(),
        );

        return $po->approve($by, $note);
    }

    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $items = Arr::pull($data, 'items', []);

            $vendor = Vendor::query()->findOrFail($data['vendor_id']);

            // Gate prakualifikasi (temuan #35): PO tidak lahir untuk vendor
            // nonaktif / dokumen wajib kedaluwarsa — kecuali override
            // beralasan.
            $reason = trim((string) Arr::pull($data, 'qualification_override_reason', ''));
            $overridden = app(VendorQualificationService::class)
                ->assertQualified($vendor, $reason === '' ? null : $reason);

            // Alasan PO tanpa PR (T3.8, ANALISIS-PROSES E3) — kontrak yang
            // sama dengan override di bawah: tercatat hanya bila PO memang
            // lahir tanpa purchase_requisition_id. Alasan yang terlanjur
            // diketik untuk PO yang punya PR bukan jejak pembelian langsung.
            $prBypassReason = trim((string) Arr::pull($data, 'pr_bypass_reason', ''));

            $po = new PurchaseOrder(Arr::except($data, ['code', 'status']));
            // BUKAN mass-assignment: alasan hanya tercatat saat gate
            // benar-benar mengembalikan blokir yang dilewati. Dulu alasan
            // yang diketik untuk vendor SEHAT ikut tersimpan — jejak audit
            // yang menuduh vendor sehat bermasalah.
            $po->qualification_override_reason = $overridden !== [] ? $reason : null;
            $po->pr_bypass_reason = $po->purchase_requisition_id === null && $prBypassReason !== '' ? $prBypassReason : null;
            $po->status = DocumentStatus::Draft;
            $po->payment_term_days = $data['payment_term_days'] ?? $vendor->payment_term_days;
            $po->save(); // HasDocumentNumber fills the PO code

            $this->syncItems($po, $items);
            $this->recalcTotals($po);

            return $po->load('items', 'vendor');
        });
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        $this->assertEditable($po);

        return DB::transaction(function () use ($po, $data): PurchaseOrder {
            $items = Arr::pull($data, 'items');

            // qualification_override_reason ikut dikecualikan: kolom itu
            // hanya boleh dicap oleh gate prakualifikasi (create/submit),
            // bukan lewat edit — edit bebas berarti jejak override bisa
            // ditulis tanpa satu pun blokir yang dilewati.
            $po->fill(Arr::except($data, ['code', 'status', 'closed_at', 'needs_director_approval', 'qualification_override_reason', 'pr_bypass_reason']));

            // pr_bypass_reason mengikuti keadaan PO SESUDAH edit, bukan
            // payload mentah: PO yang kini bertaut ke PR bukan lagi pembelian
            // langsung, jadi alasannya gugur; PO yang tetap tanpa PR memakai
            // alasan yang dikirim (formulir Ubah selalu mengirimnya) atau
            // mempertahankan yang tersimpan bila kuncinya tidak dibawa (T3.8).
            if ($po->purchase_requisition_id !== null) {
                $po->pr_bypass_reason = null;
            } elseif (array_key_exists('pr_bypass_reason', $data)) {
                $prBypassReason = trim((string) ($data['pr_bypass_reason'] ?? ''));
                $po->pr_bypass_reason = $prBypassReason === '' ? null : $prBypassReason;
            }

            $po->save();

            // vendor_id may have changed; drop any loaded relation so the
            // PKP check in recalcTotals() sees the current vendor.
            $po->unsetRelation('vendor');

            if (is_array($items)) {
                $this->syncItems($po, $items); // lines are replaced wholesale
            }

            $this->recalcTotals($po);

            return $po->load('items', 'vendor');
        });
    }

    /**
     * Draft PO out of an approved PR: header context (project, deliver-to
     * warehouse) carries over and every PR line is copied, priced at its
     * estimate until purchasing negotiates the final price.
     */
    public function createFromPr(PurchaseRequisition $pr, array $data): PurchaseOrder
    {
        if ($pr->status !== DocumentStatus::Approved) {
            throw new LogicException("PR {$pr->code} must be approved before a PO can be created from it.");
        }

        if ($pr->items()->doesntExist()) {
            throw new LogicException("PR {$pr->code} has no lines to copy.");
        }

        return DB::transaction(function () use ($pr, $data): PurchaseOrder {
            $vendor = Vendor::query()->findOrFail($data['vendor_id']);

            // Gate prakualifikasi (temuan #35) — cek yang sama dengan create().
            $reason = trim((string) ($data['qualification_override_reason'] ?? ''));
            $overridden = app(VendorQualificationService::class)
                ->assertQualified($vendor, $reason === '' ? null : $reason);

            $po = new PurchaseOrder([
                'vendor_id' => $vendor->id,
                'purchase_requisition_id' => $pr->id,
                'project_id' => $pr->project_id,
                'warehouse_id' => $pr->warehouse_id,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? $pr->needed_date?->toDateString(),
                'payment_term_days' => $data['payment_term_days'] ?? $vendor->payment_term_days,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'delivery_address' => $data['delivery_address'] ?? null,
                'notes' => $data['notes'] ?? "Dibuat dari {$pr->code}",
                // Daftar atribut eksplisit ini dulu MENJATUHKAN alasannya:
                // override "Buat PO" dari PR benar-benar dipakai tapi tanpa
                // jejak. Tercatat hanya saat gate mengembalikan blokir yang
                // dilewati — kontrak yang sama dengan create().
                'qualification_override_reason' => $overridden !== [] ? $reason : null,
            ]);
            $po->status = DocumentStatus::Draft;
            $po->save();

            $lineNo = 0;

            foreach ($pr->items as $line) {
                $qty = round((float) $line->qty, 3);
                $unitPrice = round((float) $line->estimated_price, 2);

                $po->items()->create([
                    'line_no' => ++$lineNo,
                    'item_id' => $line->item_id,
                    // Tautan anggaran ikut menyeberang (temuan #34 tahap 1):
                    // tanpa ini kendali harga tidak tahu baris BOQ mana yang
                    // menjadi pembanding harga negosiasi di bawah.
                    'boq_item_id' => $line->boq_item_id,
                    'description' => $line->description ?? $this->itemName($line->item_id) ?? '-',
                    'qty' => $qty,
                    'unit' => $line->unit,
                    'unit_price' => $unitPrice,
                    'amount' => round($qty * $unitPrice, 2),
                ]);
            }

            $this->recalcTotals($po);

            return $po->load('items', 'vendor');
        });
    }

    /**
     * subtotal = sum(line amounts); dpp = subtotal - discount;
     * ppn = dpp * rate / 100 ONLY when the vendor is PKP (non-PKP vendors
     * cannot issue a faktur pajak, so no input VAT); total = dpp + ppn.
     */
    public function recalcTotals(PurchaseOrder $po): PurchaseOrder
    {
        $vendor = $po->vendor ?? Vendor::query()->find($po->vendor_id);

        $subtotal = round((float) $po->items()->sum('amount'), 2);
        $discount = round(min((float) $po->discount_amount, $subtotal), 2);
        $dpp = round($subtotal - $discount, 2);

        $ppnRate = $vendor?->is_pkp ? Erp::float('tax.ppn_rate', 11.0) : 0.0;
        $ppnAmount = round($dpp * $ppnRate / 100, 2);

        $po->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'dpp' => $dpp,
            'ppn_rate' => $ppnRate,
            'ppn_amount' => $ppnAmount,
            'total' => round($dpp + $ppnAmount, 2),
        ])->save();

        return $po;
    }

    /**
     * Called by the Inventory module when a GRN line is posted against this PO.
     * Increments qty_received and auto-closes the PO once every line is fully
     * received.
     */
    public function registerReceipt(PurchaseOrderItem $poItem, float $qty): PurchaseOrderItem
    {
        if ($qty <= 0) {
            throw new LogicException('Received quantity must be positive.');
        }

        return DB::transaction(function () use ($poItem, $qty): PurchaseOrderItem {
            /** @var PurchaseOrderItem $poItem */
            $poItem = PurchaseOrderItem::query()->whereKey($poItem->id)->lockForUpdate()->firstOrFail();
            $po = $poItem->purchaseOrder;

            if ($po->status !== DocumentStatus::Approved) {
                throw new LogicException("PO {$po->code} is {$po->status->value}; only approved POs can receive goods.");
            }

            $qty = round($qty, 3);

            if ($qty > $poItem->remainingQty()) {
                throw new LogicException(
                    "Receipt of {$qty} exceeds remaining quantity {$poItem->remainingQty()} on PO {$po->code} line {$poItem->line_no}."
                );
            }

            $poItem->qty_received = round((float) $poItem->qty_received + $qty, 3);
            $poItem->save();

            if ($po->isFullyReceived()) {
                $po->forceFill([
                    'status' => DocumentStatus::Closed,
                    'closed_at' => now(),
                ])->save();

                // Temuan #68: penerimaan penuh menutup PO tanpa ada yang
                // menekan "Tutup" — momen evaluasi vendornya tetap harus
                // berbunyi (notifikasi ke pemegang prc.create).
                app(VendorEvaluationService::class)->promptEvaluationIfDue($po);
            }

            return $poItem;
        });
    }

    /**
     * Called by Inventory when a purchase return is posted against a GRN line
     * that was registered here: the mirror registerReceipt() never had. The
     * audit (temuan 38) named the cost of that one-way arithmetic exactly —
     * qty_received only ever grew, so an order whose goods went back to the
     * vendor kept reading fully delivered: ApBillService's completeness gate
     * (qty_received < qty) then approved the bill for goods no longer in the
     * gudang, and the auto-closed PO refused the replacement delivery when it
     * finally arrived.
     *
     * REOPENS ONLY THE AUTOMATIC CLOSE, and isFullyReceived() BEFORE the
     * decrement is the discriminator. A manual close() forgives an undelivered
     * remainder — its lines still read short, so the pre-decrement answer is
     * false and the forgiveness survives the return. The automatic close in
     * registerReceipt() fires exactly when every line reads complete, so
     * complete-then-closed can only be that close, and the return has just
     * falsified its premise: more goods are now owed, and the vendor must be
     * able to deliver them.
     */
    public function unregisterReceipt(PurchaseOrderItem $poItem, float $qty): PurchaseOrderItem
    {
        if ($qty <= 0) {
            throw new LogicException('Returned quantity must be positive.');
        }

        return DB::transaction(function () use ($poItem, $qty): PurchaseOrderItem {
            /** @var PurchaseOrderItem $poItem */
            $poItem = PurchaseOrderItem::query()->whereKey($poItem->id)->lockForUpdate()->firstOrFail();
            $po = $poItem->purchaseOrder;

            // Approved OR closed, wider than registerReceipt() on purpose: the
            // everyday return happens AFTER the last delivery auto-closed the
            // order. A draft or submitted PO never received anything to give
            // back, so a return against it is a data error worth stopping.
            if ($po->status !== DocumentStatus::Approved && $po->status !== DocumentStatus::Closed) {
                throw new LogicException(
                    "PO {$po->code} is {$po->status->value}; only an approved or closed PO can take a return."
                );
            }

            $qty = round($qty, 3);

            // 0.0005 tolerance absorbs decimal(15,3) rounding artifacts, the
            // same one the stock engine applies on the other side.
            if ($qty > round((float) $poItem->qty_received, 3) + 0.0005) {
                throw new LogicException(
                    "Return of {$qty} exceeds received quantity {$poItem->qty_received} on PO {$po->code} line {$poItem->line_no}."
                );
            }

            $wasFullyReceived = $po->isFullyReceived();

            $poItem->qty_received = round((float) $poItem->qty_received - $qty, 3);
            $poItem->save();

            if ($po->status === DocumentStatus::Closed && $wasFullyReceived && ! $po->isFullyReceived()) {
                $po->forceFill([
                    'status' => DocumentStatus::Approved,
                    'closed_at' => null,
                ])->save();
            }

            return $poItem;
        });
    }

    /**
     * Manual close for a PO that will never be fully received
     * (short shipment accepted, remainder cancelled).
     */
    public function close(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== DocumentStatus::Approved) {
            throw new LogicException("PO {$po->code} is {$po->status->value}; only an approved PO can be closed.");
        }

        $po->forceFill([
            'status' => DocumentStatus::Closed,
            'closed_at' => now(),
        ])->save();

        return $po;
    }

    public function delete(PurchaseOrder $po): void
    {
        $this->assertEditable($po);

        $po->delete();
    }

    private function syncItems(PurchaseOrder $po, array $items): void
    {
        // Tautan BOQ tersimpan diwariskan ke baris penggantinya bila payload
        // TIDAK membawa kuncinya: form generik tidak mengirim boq_item_id,
        // sehingga tanpa pewarisan ini satu kali Ubah melucuti gerbang harga —
        // peringatan simpangan tidak pernah menyala lagi justru di PO yang
        // sedang diedit harganya. Payload yang membawa kuncinya (termasuk
        // null eksplisit) tetap menang.
        $storedLinks = $po->items()->pluck('boq_item_id', 'id');

        $po->items()->delete();

        $lineNo = 0;

        foreach ($items as $item) {
            $qty = round((float) ($item['qty'] ?? 1), 3);
            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);

            $boqItemId = array_key_exists('boq_item_id', $item)
                ? $item['boq_item_id']
                : (isset($item['id']) ? $storedLinks->get((int) $item['id']) : null);

            $po->items()->create([
                'line_no' => ++$lineNo,
                'item_id' => $item['item_id'] ?? null,
                'boq_item_id' => $boqItemId,
                'description' => $item['description'],
                'qty' => $qty,
                'unit' => $item['unit'] ?? null,
                'unit_price' => $unitPrice,
                'amount' => round($qty * $unitPrice, 2),
            ]);
        }
    }

    /**
     * Laporan baris PO terbuka — every line on an approved, un-closed PO whose
     * received quantity still trails the ordered one, overdue-first.
     *
     * Until this report the delivery promise died at document level: the only
     * way to learn what had not arrived was opening approved POs one by one,
     * so a late shipment was discovered when the site ran out of material.
     * expected_date was copied from the PR and then displayed, nothing more.
     *
     * Draft/submitted POs are not commitments yet, closed POs are settled
     * history (a manual close forgives the undelivered remainder — that is
     * what closing means), so only approved POs appear. Overdue means the
     * header expected_date has passed; lines whose PO never named one sort
     * last and carry is_overdue = false — a promise without a date cannot be
     * broken, only chased.
     *
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function outstandingLines(?int $vendorId = null, ?int $projectId = null): array
    {
        $today = now()->toDateString();

        $lines = PurchaseOrderItem::query()
            ->join('prc_purchase_orders as po', 'po.id', '=', 'prc_purchase_order_items.purchase_order_id')
            // LEFT: a vendor soft-deleted after approval must not hide the
            // undelivered goods it still owes.
            ->leftJoin('prc_vendors as vendor', 'vendor.id', '=', 'po.vendor_id')
            ->whereNull('po.deleted_at')
            ->where('po.status', DocumentStatus::Approved->value)
            ->whereColumn('prc_purchase_order_items.qty_received', '<', 'prc_purchase_order_items.qty')
            ->when($vendorId !== null, fn ($query) => $query->where('po.vendor_id', $vendorId))
            ->when($projectId !== null, fn ($query) => $query->where('po.project_id', $projectId))
            // Overdue first, then by expected date (dateless promises last),
            // then a stable document order.
            ->orderByRaw(
                'CASE WHEN po.expected_date IS NOT NULL AND po.expected_date < ? THEN 0 ELSE 1 END, '
                .'CASE WHEN po.expected_date IS NULL THEN 1 ELSE 0 END, '
                .'po.expected_date, po.code, prc_purchase_order_items.line_no',
                [$today],
            )
            ->get([
                'prc_purchase_order_items.*',
                'po.code as po_code',
                'po.vendor_id as po_vendor_id',
                'po.project_id as po_project_id',
                'po.order_date as po_order_date',
                'po.expected_date as po_expected_date',
                'vendor.name as vendor_name',
            ]);

        $rows = $lines->map(function (PurchaseOrderItem $line) use ($today): array {
            $expected = $line->getAttribute('po_expected_date');
            $overdueDays = $expected !== null && $expected < $today
                ? Carbon::parse($expected)->diffInDays($today)
                : 0;
            $outstandingQty = round((float) $line->qty - (float) $line->qty_received, 3);

            return [
                'po_id' => (int) $line->purchase_order_id,
                'po_code' => $line->getAttribute('po_code'),
                'vendor_id' => $line->getAttribute('po_vendor_id'),
                'vendor_name' => $line->getAttribute('vendor_name'),
                'project_id' => $line->getAttribute('po_project_id'),
                'order_date' => $line->getAttribute('po_order_date'),
                'expected_date' => $expected,
                'line_no' => (int) $line->line_no,
                'item_id' => $line->item_id,
                'description' => $line->description,
                'unit' => $line->unit,
                'qty' => (float) $line->qty,
                'qty_received' => (float) $line->qty_received,
                'outstanding_qty' => $outstandingQty,
                'unit_price' => (float) $line->unit_price,
                // What is still financially committed but not on site.
                'outstanding_value' => round($outstandingQty * (float) $line->unit_price, 2),
                'is_overdue' => $overdueDays > 0,
                'overdue_days' => (int) $overdueDays,
            ];
        })->values();

        return [
            'summary' => [
                'total_lines' => $rows->count(),
                'overdue_lines' => $rows->where('is_overdue', true)->count(),
                'total_outstanding_value' => round((float) $rows->sum('outstanding_value'), 2),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * PR lines without a description override fall back to the inventory item
     * name. Plain query (no cross-module FK/model dependency by design).
     */
    private function itemName(?int $itemId): ?string
    {
        if ($itemId === null || ! Schema::hasTable('inv_items')) {
            return null;
        }

        return DB::table('inv_items')->where('id', $itemId)->value('name');
    }

    private function assertEditable(PurchaseOrder $po): void
    {
        if (! $po->status->isEditable()) {
            throw new LogicException("PO {$po->code} is {$po->status->value} and can no longer be edited.");
        }
    }
}
