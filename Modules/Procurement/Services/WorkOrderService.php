<?php

namespace Modules\Procurement\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Assets\Enums\RateBasis;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Procurement\Enums\VendorType;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\WorkOrder;

/**
 * PPK — perintah kerja alat sewa & jasa berbasis periode (P5, deviasi 3.5).
 *
 * Bentuknya dicetak dari LaborContractService (SP3): gate prakualifikasi
 * vendor yang sama (P0-E — untuk vendor rental/supplier gate menagih dokumen
 * wajib yang kedaluwarsa dan status aktif; K3L/pakta hanya menjerat vendor
 * yang mengirim pekerja ke site, lihat VendorType::sendsWorkersToSite),
 * snapshot PPN saat dibuat, nilai = Σ amount baris, approve lewat service.
 *
 * VENDOR: rental ATAU supplier — roadmap menyebut "vendor rental/jasa", dan
 * pemasok jasa terdaftar sebagai supplier hari ini (tidak ada vendor_type
 * "jasa"). Mandor dan subkontraktor DITOLAK: keduanya punya pintunya sendiri
 * (SP3 dan SPK) dengan pajak dan plafonnya masing-masing, dan membiarkan
 * mereka masuk lewat PPK berarti dua pintu untuk satu komitmen.
 *
 * Baris per_jam WAJIB menunjuk alat (asset_id): jam tagihannya dibaca dari
 * register hour-meter alat itu, dan baris tanpa alat tidak punya meter untuk
 * dibaca — ditolak saat menyusun, bukan diam-diam ditagih nol nanti.
 */
class WorkOrderService
{
    /**
     * Approve lewat service — alasan yang sama dengan SubcontractService:
     * controller yang memanggil ->approve() trait langsung adalah cara efek
     * samping dan gerbang masa depan terlewat. Maker-checker tetap berjalan
     * di dalam Approvable::approve.
     */
    public function approve(WorkOrder $workOrder, User $by, ?string $note = null): WorkOrder
    {
        return $workOrder->approve($by, $note);
    }

    public function create(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data): WorkOrder {
            $items = Arr::pull($data, 'items', []);

            $vendor = $this->rentalOrSupplierOrFail((int) $data['vendor_id']);

            $reason = trim((string) Arr::pull($data, 'qualification_override_reason', ''));
            $overridden = app(VendorQualificationService::class)
                ->assertQualified($vendor, $reason === '' ? null : $reason);

            $workOrder = new WorkOrder(Arr::except($data, ['code', 'status']));
            $workOrder->qualification_override_reason = $overridden !== [] ? $reason : null;
            $workOrder->status = DocumentStatus::Draft;
            // Non-PKP tidak bisa menerbitkan faktur pajak: tanpa PPN — aturan
            // milik master vendor, bukan tebakan modul ini (pola SPK/SP3).
            $workOrder->ppn_rate = $vendor->is_pkp ? Erp::float('tax.ppn_rate', 11.0) : 0.0;
            $workOrder->save(); // HasDocumentNumber mengisi kode PPK

            $this->syncItems($workOrder, $items);
            $this->recalcValue($workOrder);

            return $workOrder->load('items.asset', 'vendor');
        });
    }

    public function update(WorkOrder $workOrder, array $data): WorkOrder
    {
        $this->assertEditable($workOrder);

        return DB::transaction(function () use ($workOrder, $data): WorkOrder {
            $items = Arr::pull($data, 'items');

            // qualification_override_reason dikecualikan — hanya gate yang
            // boleh mencapnya (alasan yang sama dengan SubcontractService).
            $workOrder->fill(Arr::except(
                $data,
                ['code', 'status', 'ppn_rate', 'qualification_override_reason'],
            ));

            if (array_key_exists('vendor_id', $data)) {
                $vendor = $this->rentalOrSupplierOrFail((int) $data['vendor_id']);
                $workOrder->ppn_rate = $vendor->is_pkp ? Erp::float('tax.ppn_rate', 11.0) : 0.0;
                $workOrder->unsetRelation('vendor');
            }

            $workOrder->save();

            if (is_array($items)) {
                $this->syncItems($workOrder, $items); // baris diganti seutuhnya
            }

            $this->recalcValue($workOrder);

            return $workOrder->load('items.asset', 'vendor');
        });
    }

    public function delete(WorkOrder $workOrder): void
    {
        $this->assertEditable($workOrder);

        if ($workOrder->billings()->withTrashed()->exists()) {
            throw new LogicException(
                "PPK {$workOrder->code} sudah memiliki tagihan periode dan tidak dapat dihapus."
            );
        }

        $workOrder->delete();
    }

    /** Nilai PPK (DPP komitmen) selalu = Σ amount barisnya. */
    public function recalcValue(WorkOrder $workOrder): WorkOrder
    {
        $workOrder->forceFill([
            'value' => round((float) $workOrder->items()->sum('amount'), 2),
        ])->save();

        return $workOrder;
    }

    private function syncItems(WorkOrder $workOrder, array $items): void
    {
        $workOrder->items()->delete();

        $lineNo = 0;

        foreach ($items as $item) {
            $basis = RateBasis::from($item['rate_basis']);
            $rate = round((float) ($item['rate'] ?? 0), 2);
            $qtyPeriods = round((float) ($item['qty_periods'] ?? 0), 3);

            if ($rate <= 0) {
                throw new LogicException('Setiap baris PPK memerlukan tarif lebih dari nol.');
            }

            if ($qtyPeriods <= 0) {
                throw new LogicException(
                    'Setiap baris PPK memerlukan plafon kuantitas (qty_periods) lebih dari nol.'
                );
            }

            if ($basis === RateBasis::PerJam && empty($item['asset_id'])) {
                throw new LogicException(
                    'Baris tarif per_jam harus menunjuk alat yang terdaftar di register aset — '
                    .'jam tagihannya dibaca dari hour-meter alat itu, bukan diketik.'
                );
            }

            $workOrder->items()->create([
                'line_no' => ++$lineNo,
                'asset_id' => $item['asset_id'] ?? null,
                'description' => $item['description'],
                'rate_basis' => $basis,
                'rate' => $rate,
                'qty_periods' => $qtyPeriods,
                'amount' => round($qtyPeriods * $rate, 2),
            ]);
        }
    }

    private function rentalOrSupplierOrFail(int $vendorId): Vendor
    {
        $vendor = Vendor::query()->findOrFail($vendorId);

        if (! in_array($vendor->vendor_type, [VendorType::Rental, VendorType::Supplier], true)) {
            throw new LogicException(
                "Vendor {$vendor->code} ({$vendor->name}) bertipe {$vendor->vendor_type->label()}; "
                .'PPK alat & jasa hanya untuk vendor rental atau pemasok jasa. Mandor memakai SP3, '
                .'subkontraktor memakai SPK.'
            );
        }

        return $vendor;
    }

    private function assertEditable(WorkOrder $workOrder): void
    {
        if (! $workOrder->status->isEditable()) {
            throw new LogicException(
                "PPK {$workOrder->code} berstatus {$workOrder->status->value} dan tidak dapat diubah lagi."
            );
        }
    }
}
