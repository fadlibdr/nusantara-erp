<?php

namespace Modules\Subcontract\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\AwardDecisionService;
use Modules\Procurement\Services\VendorQualificationService;
use Modules\Procurement\Support\DirectorApproval;
use Modules\Subcontract\Enums\PphConstructionScheme;
use Modules\Subcontract\Models\Subcontract;

class SubcontractService
{
    /**
     * Approval goes through the service so the director gate runs at all: the
     * controller used to call $subcontract->approve() directly, which is how
     * SPK/2026/II/0001 (Rp 6,5 miliar, 32,5× the Rp 200 juta threshold) got
     * approved by a non-director while flagged "Perlu persetujuan direktur".
     * Maker-checker still runs inside Approvable::approve, so a director who
     * submitted the SPK remains refused.
     */
    public function approve(Subcontract $subcontract, User $by, ?string $note = null): Subcontract
    {
        // Kriteria #4 (P2): SPK yang lahir dari RFQ subkon tidak boleh disetujui
        // sebelum keputusan pemenang (award) untuk vendornya disetujui. Inert
        // untuk SPK biasa (rfq_id null — sebagian besar SPK).
        app(AwardDecisionService::class)->assertApprovedAward($subcontract, $subcontract->rfq_id, (int) $subcontract->vendor_id);

        DirectorApproval::assertMayApprove(
            $subcontract,
            $by,
            (float) $subcontract->value,
            Subcontract::directorApprovalThreshold(),
        );

        return $subcontract->approve($by, $note);
    }

    public function create(array $data): Subcontract
    {
        return DB::transaction(function () use ($data): Subcontract {
            $items = Arr::pull($data, 'items', []);

            $vendor = $this->subcontractorOrFail((int) $data['vendor_id']);
            // Gate prakualifikasi vendor (temuan #35) — kontrak yang sama
            // dengan PoService::create: alasan tersimpan di SPK HANYA saat
            // gate mengembalikan blokir yang dilewati; alasan yang diketik
            // untuk subkon sehat bukan jejak override dan tetap NULL.
            $reason = trim((string) Arr::pull($data, 'qualification_override_reason', ''));
            $overridden = app(VendorQualificationService::class)
                ->assertQualified($vendor, $reason === '' ? null : $reason);
            $scheme = PphConstructionScheme::from($data['pph_scheme']);

            $subcontract = new Subcontract(Arr::except($data, ['code', 'status']));
            $subcontract->qualification_override_reason = $overridden !== [] ? $reason : null;
            $subcontract->status = DocumentStatus::Draft;
            // Non-PKP subcontractors cannot issue a faktur pajak: no PPN.
            $subcontract->ppn_rate = $vendor->is_pkp ? Erp::float('tax.ppn_rate', 11.0) : 0.0;
            $subcontract->retention_pct = $data['retention_pct']
                ?? Erp::float('projects.default_retention_pct', 5.0);
            // Snapshot the statutory PPh final rate (PP 9/2022) at creation so
            // later rate changes never rewrite history on this SPK.
            $subcontract->pph_rate = $scheme->rate();
            $subcontract->save(); // HasDocumentNumber fills the SPK code

            $this->syncItems($subcontract, $items);
            $this->recalcValue($subcontract);

            return $subcontract->load('items', 'vendor');
        });
    }

    public function update(Subcontract $subcontract, array $data): Subcontract
    {
        $this->assertEditable($subcontract);

        return DB::transaction(function () use ($subcontract, $data): Subcontract {
            $items = Arr::pull($data, 'items');

            // qualification_override_reason ikut dikecualikan: kolom itu
            // hanya boleh dicap oleh gate prakualifikasi (create/submit),
            // bukan lewat edit — edit bebas berarti jejak override bisa
            // ditulis tanpa satu pun blokir yang dilewati.
            $subcontract->fill(Arr::except(
                $data,
                ['code', 'status', 'ppn_rate', 'pph_rate', 'needs_director_approval', 'qualification_override_reason'],
            ));

            if (array_key_exists('vendor_id', $data)) {
                $vendor = $this->subcontractorOrFail((int) $data['vendor_id']);
                $subcontract->ppn_rate = $vendor->is_pkp ? Erp::float('tax.ppn_rate', 11.0) : 0.0;
                $subcontract->unsetRelation('vendor');
            }

            if (array_key_exists('pph_scheme', $data)) {
                // Still a draft: re-snapshot the rate for the new scheme.
                $subcontract->pph_rate = PphConstructionScheme::from($data['pph_scheme'])->rate();
            }

            $subcontract->save();

            if (is_array($items)) {
                $this->syncItems($subcontract, $items); // lines are replaced wholesale
            }

            $this->recalcValue($subcontract);

            return $subcontract->load('items', 'vendor');
        });
    }

    public function delete(Subcontract $subcontract): void
    {
        $this->assertEditable($subcontract);

        if ($subcontract->claims()->withTrashed()->exists()) {
            throw new LogicException(
                "SPK {$subcontract->code} has progress claims and cannot be deleted."
            );
        }

        $subcontract->delete();
    }

    /**
     * SPK value (DPP) is always the sum of its line amounts.
     */
    public function recalcValue(Subcontract $subcontract): Subcontract
    {
        $subcontract->forceFill([
            'value' => round((float) $subcontract->items()->sum('amount'), 2),
        ])->save();

        return $subcontract;
    }

    /**
     * Lines can be typed in free-form or picked from a BOQ: when a line only
     * carries boq_item_id, wbs/description/qty/unit default from the BOQ line
     * (unit_price still has to be negotiated — BOQ price is the sell price,
     * not the subcon buy price — so it defaults to the given value or 0).
     */
    private function syncItems(Subcontract $subcontract, array $items): void
    {
        $subcontract->items()->delete();

        $lineNo = 0;

        foreach ($items as $item) {
            $boqLine = $this->boqLine($item['boq_item_id'] ?? null);

            $qty = round((float) ($item['qty'] ?? $boqLine?->qty ?? 0), 3);
            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);

            if ($qty <= 0) {
                throw new LogicException('Every SPK line needs a positive quantity.');
            }

            $subcontract->items()->create([
                'line_no' => ++$lineNo,
                'boq_item_id' => $item['boq_item_id'] ?? null,
                'wbs_code' => $item['wbs_code'] ?? $boqLine?->wbs_code,
                'description' => $item['description'] ?? $boqLine?->description ?? '-',
                'qty' => $qty,
                'unit' => $item['unit'] ?? $boqLine?->unit,
                'unit_price' => $unitPrice,
                'amount' => round($qty * $unitPrice, 2),
                'progress_pct' => 0,
            ]);
        }
    }

    /**
     * Plain query against the Estimation table (no cross-module FK by design);
     * silently absent when the Estimation module is not migrated.
     */
    private function boqLine(?int $boqItemId): ?object
    {
        if ($boqItemId === null || ! Schema::hasTable('est_boq_items')) {
            return null;
        }

        return DB::table('est_boq_items')->where('id', $boqItemId)->first();
    }

    private function subcontractorOrFail(int $vendorId): Vendor
    {
        $vendor = Vendor::query()->findOrFail($vendorId);

        if (! $vendor->is_subcontractor) {
            throw new LogicException(
                "Vendor {$vendor->code} ({$vendor->name}) is not registered as a subcontractor."
            );
        }

        return $vendor;
    }

    private function assertEditable(Subcontract $subcontract): void
    {
        if (! $subcontract->status->isEditable()) {
            throw new LogicException(
                "SPK {$subcontract->code} is {$subcontract->status->value} and can no longer be edited."
            );
        }
    }
}
