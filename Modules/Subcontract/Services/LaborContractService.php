<?php

namespace Modules\Subcontract\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Procurement\Enums\VendorType;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\VendorQualificationService;
use Modules\Subcontract\Enums\LaborPphScheme;
use Modules\Subcontract\Models\LaborContract;

/**
 * SP3 Induk — SPK mandor upah borongan (P4, deviasi 3.5).
 *
 * Bentuknya sengaja dicetak dari SubcontractService (SPK subkon): gate
 * prakualifikasi yang sama (P0-E, kini juga berlaku untuk mandor — keputusan
 * yang didokumentasikan di VendorQualificationService), snapshot tarif pajak
 * saat dibuat, nilai = Σ amount baris, approve lewat service. Perbedaan yang
 * disengaja:
 *
 *   - vendor harus vendor_type = mandor (bukan is_subcontractor);
 *   - pph_scheme memakai LaborPphScheme (PPh final UMKM PP 55/2022 0,5%),
 *     BUKAN PphConstructionScheme — mandor borongan bukan jasa konstruksi
 *     bersertifikat PP 9/2022; asumsi #3 roadmap;
 *   - skema pph21_ter adalah pintu yang belum dibangun: memilihnya ditolak
 *     422 di sini (assertSchemeActive), dengan jujur, sampai pemilik
 *     memutuskan PPh 21 — jalurnya kelak memakai
 *     Modules\HrPayroll\Services\Pph21TerService, bukan tarif flat baru;
 *   - tanpa gerbang direktur (lihat docblock LaborContract);
 *   - tanpa retensi/uang muka — upah borongan dibayar per opname volume.
 */
class LaborContractService
{
    /**
     * Approve lewat service — alasan yang sama dengan SubcontractService:
     * controller yang memanggil ->approve() trait langsung adalah cara efek
     * samping dan gerbang masa depan terlewat. Maker-checker tetap berjalan
     * di dalam Approvable::approve.
     */
    public function approve(LaborContract $contract, User $by, ?string $note = null): LaborContract
    {
        return $contract->approve($by, $note);
    }

    public function create(array $data): LaborContract
    {
        return DB::transaction(function () use ($data): LaborContract {
            $items = Arr::pull($data, 'items', []);

            $vendor = $this->mandorOrFail((int) $data['vendor_id']);

            // Gate prakualifikasi (P0-E, diperluas ke mandor di P4): alasan
            // override hanya tersimpan bila gate benar-benar dilewati —
            // kontrak yang sama dengan SubcontractService::create.
            $reason = trim((string) Arr::pull($data, 'qualification_override_reason', ''));
            $overridden = app(VendorQualificationService::class)
                ->assertQualified($vendor, $reason === '' ? null : $reason);

            $scheme = LaborPphScheme::from($data['pph_scheme']);
            $this->assertSchemeActive($scheme);

            $contract = new LaborContract(Arr::except($data, ['code', 'status']));
            $contract->qualification_override_reason = $overridden !== [] ? $reason : null;
            $contract->status = DocumentStatus::Draft;
            // Non-PKP tidak bisa menerbitkan faktur pajak: tanpa PPN — aturan
            // yang sama dengan SPK subkon; mandor PKP praktis tidak ada,
            // tetapi aturannya milik master vendor, bukan tebakan modul ini.
            $contract->ppn_rate = $vendor->is_pkp ? Erp::float('tax.ppn_rate', 11.0) : 0.0;
            // Snapshot tarif PPh final UMKM (PP 55/2022) saat dibuat: ubahan
            // tarif di kemudian hari tidak menulis ulang SP3 yang ada.
            $contract->pph_rate = $scheme->rate();
            $contract->save(); // HasDocumentNumber mengisi kode SP3

            $this->syncItems($contract, $items);
            $this->recalcValue($contract);

            return $contract->load('items', 'vendor');
        });
    }

    public function update(LaborContract $contract, array $data): LaborContract
    {
        $this->assertEditable($contract);

        return DB::transaction(function () use ($contract, $data): LaborContract {
            $items = Arr::pull($data, 'items');

            // qualification_override_reason dikecualikan — hanya gate yang
            // boleh mencapnya (alasan yang sama dengan SubcontractService).
            $contract->fill(Arr::except(
                $data,
                ['code', 'status', 'ppn_rate', 'pph_rate', 'qualification_override_reason'],
            ));

            if (array_key_exists('vendor_id', $data)) {
                $vendor = $this->mandorOrFail((int) $data['vendor_id']);
                $contract->ppn_rate = $vendor->is_pkp ? Erp::float('tax.ppn_rate', 11.0) : 0.0;
                $contract->unsetRelation('vendor');
            }

            if (array_key_exists('pph_scheme', $data)) {
                $scheme = LaborPphScheme::from($data['pph_scheme']);
                $this->assertSchemeActive($scheme);
                // Masih draft: snapshot ulang tarif untuk skema baru.
                $contract->pph_rate = $scheme->rate();
            }

            $contract->save();

            if (is_array($items)) {
                $this->syncItems($contract, $items); // baris diganti seutuhnya
            }

            $this->recalcValue($contract);

            return $contract->load('items', 'vendor');
        });
    }

    public function delete(LaborContract $contract): void
    {
        $this->assertEditable($contract);

        if ($contract->claims()->withTrashed()->exists()) {
            throw new LogicException(
                "SP3 {$contract->code} sudah memiliki opname mandor dan tidak dapat dihapus."
            );
        }

        $contract->delete();
    }

    /** Nilai SP3 (DPP upah) selalu = Σ amount barisnya. */
    public function recalcValue(LaborContract $contract): LaborContract
    {
        $contract->forceFill([
            'value' => round((float) $contract->items()->sum('amount'), 2),
        ])->save();

        return $contract;
    }

    /**
     * PINTU YANG BELUM DIBANGUN, dengan jujur (asumsi #3 roadmap): skema
     * pph21_ter ada di enum supaya kolom dan API sudah benar bentuknya dan
     * pembalikan kelak murah, tetapi jalurnya belum dibangun — bila pemilik
     * memilih PPh 21, implementasinya memanggil mesin payroll yang sudah ada
     * (Modules\HrPayroll\Services\Pph21TerService, TER PMK 168/2023) per
     * penerima, bukan tarif flat di sini. Sampai saat itu, memilihnya adalah
     * 422 yang menyebut dirinya sendiri — bukan angka yang diam-diam salah.
     */
    private function assertSchemeActive(LaborPphScheme $scheme): void
    {
        if ($scheme === LaborPphScheme::Pph21Ter) {
            throw new LogicException(
                'Skema PPh 21 (TER) untuk upah mandor belum diaktifkan; SP3 saat ini memakai '
                .'PPh final UMKM 0,5% (PP 55/2022) sesuai asumsi #3. Bila pemilik memutuskan '
                .'PPh 21, jalur ini akan memakai mesin payroll (Pph21TerService).'
            );
        }
    }

    /**
     * Baris bisa diketik bebas atau ditarik dari BOQ (deskripsi/qty/satuan
     * default dari baris BOQ; tarif upah SELALU dinegosiasikan — harga BOQ
     * adalah harga jual, bukan upah mandor). Pola yang sama dengan
     * SubcontractService::syncItems.
     */
    private function syncItems(LaborContract $contract, array $items): void
    {
        $contract->items()->delete();

        $lineNo = 0;

        foreach ($items as $item) {
            $boqLine = $this->boqLine($item['boq_item_id'] ?? null);

            $qty = round((float) ($item['qty'] ?? $boqLine?->qty ?? 0), 3);
            $unitRate = round((float) ($item['unit_rate'] ?? 0), 2);

            if ($qty <= 0) {
                throw new LogicException('Setiap baris SP3 memerlukan volume (qty) lebih dari nol.');
            }

            if ($unitRate <= 0) {
                throw new LogicException('Setiap baris SP3 memerlukan tarif upah lebih dari nol.');
            }

            $contract->items()->create([
                'line_no' => ++$lineNo,
                'boq_item_id' => $item['boq_item_id'] ?? null,
                'wbs_code' => $item['wbs_code'] ?? $boqLine?->wbs_code,
                'description' => $item['description'] ?? $boqLine?->description ?? '-',
                'qty' => $qty,
                'unit' => $item['unit'] ?? $boqLine?->unit,
                'unit_rate' => $unitRate,
                'amount' => round($qty * $unitRate, 2),
            ]);
        }
    }

    /** Baca polos tabel Estimation (tanpa FK lintas-modul by design). */
    private function boqLine(?int $boqItemId): ?object
    {
        if ($boqItemId === null || ! Schema::hasTable('est_boq_items')) {
            return null;
        }

        return DB::table('est_boq_items')->where('id', $boqItemId)->first();
    }

    private function mandorOrFail(int $vendorId): Vendor
    {
        $vendor = Vendor::query()->findOrFail($vendorId);

        if ($vendor->vendor_type !== VendorType::Mandor) {
            throw new LogicException(
                "Vendor {$vendor->code} ({$vendor->name}) bukan vendor bertipe mandor; "
                .'SP3 hanya dapat dibuat untuk mandor. Ubah jenis vendornya di master vendor, '
                .'atau pakai SPK subkon untuk subkontraktor.'
            );
        }

        return $vendor;
    }

    private function assertEditable(LaborContract $contract): void
    {
        if (! $contract->status->isEditable()) {
            throw new LogicException(
                "SP3 {$contract->code} berstatus {$contract->status->value} dan tidak dapat diubah lagi."
            );
        }
    }
}
