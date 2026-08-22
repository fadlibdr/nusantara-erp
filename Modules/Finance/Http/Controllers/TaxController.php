<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\TaxStoreRequest;
use Modules\Finance\Http\Requests\TaxUpdateRequest;
use Modules\Finance\Http\Resources\TaxResource;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\Tax;

class TaxController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Tax::query()
            ->with('coaAccount')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('tax_type'), fn ($query) => $query->where('tax_type', $request->string('tax_type')))
            ->orderBy('code');

        return $this->listing($request, $query, TaxResource::class,
            sortable: ['code', 'name', 'tax_type', 'rate']);
    }

    public function store(TaxStoreRequest $request): JsonResponse
    {
        $tax = Tax::query()->create($request->validated());

        return $this->created(TaxResource::make($tax->load('coaAccount')));
    }

    public function show(Tax $tax): JsonResponse
    {
        return $this->ok(TaxResource::make($tax->load('coaAccount')));
    }

    public function update(TaxUpdateRequest $request, Tax $tax): JsonResponse
    {
        $tax->update($request->validated());

        return $this->ok(TaxResource::make($tax->load('coaAccount')));
    }

    /**
     * A tax row that documents point at cannot be deleted.
     *
     * fin_taxes rows are soft-deleted and ApBill::pphTax() is a plain belongsTo,
     * so deleting one turned every historic withholding that named it into
     * `pphTax === null`. TaxExportService::eBupotBlocker then reported those
     * bills as "Jenis PPh pada BIL/... belum ditetapkan — pilih jenis pajaknya
     * pada tagihan", an instruction assertEditable() refuses on an approved
     * bill: in the audit probe Rp 25.837.500 of PPh final dropped out of the
     * e-Bupot file for its masa with no remedy the operator could perform, and
     * the vendor's PPh credit went unreported.
     *
     * Same shape as AccountController::destroy(), which already refuses a COA
     * account carrying journal lines: master data in use is not deleted, it is
     * left alone. Cancelled bills do not count — their journals are reversed and
     * they report nothing.
     */
    public function destroy(Tax $tax): JsonResponse
    {
        $bills = ApBill::query()
            ->where('pph_tax_id', $tax->id)
            ->whereNot('status', DocumentStatus::Cancelled->value);

        $inUse = (clone $bills)->count();

        if ($inUse > 0) {
            $example = (clone $bills)->orderBy('id')->value('code');

            return $this->error(
                "Pajak {$tax->code} masih dipakai {$inUse} tagihan (mis. {$example}) dan tidak dapat dihapus; "
                .'bukti potongnya akan hilang dari file e-Bupot masa terkait.'
            );
        }

        $tax->delete();

        return $this->ok(null, 'Tax deleted.');
    }
}
