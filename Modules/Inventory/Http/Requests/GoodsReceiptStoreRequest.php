<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class GoodsReceiptStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('inv_warehouses', 'id')],
            'purchase_order_id' => $this->crossModuleId('prc_purchase_orders'),
            'vendor_id' => $this->crossModuleId('prc_vendors'),
            'receipt_date' => ['required', 'date'],
            'delivery_note_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            'items.*.po_item_id' => ['nullable', 'integer'], // cross-module: prc_purchase_order_items.id
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->refuseUnconfirmedZeroCostPoLines($validator);
        });
    }

    /**
     * Harga satuan 0 pada baris TERTAUT PO butuh konfirmasi eksplisit.
     *
     * Silently accepting it was the defect: the form prefills the PO price,
     * so a 0 that survives to submit is a wiped field or a deliberate
     * free-issue — and StockService posts either without a word, receiving
     * the goods at zero value, dragging the warehouse moving average down
     * permanently and letting every later issue charge the project Rp 0.
     * A genuine free-issue (vendor bonus) stays legal: the SPA shows a
     * confirmDialog naming the zero-priced items — these messages carry the
     * names — and resubmits with confirm_zero_cost.
     *
     * confirm_zero_cost is deliberately NOT in rules(): it is a request flag,
     * not a column, and validated() feeds GoodsReceipt::fill directly.
     *
     * Unlinked lines (no po_item_id) are untouched — opening stock and site
     * returns legitimately arrive at zero with no PO price to deviate from.
     */
    protected function refuseUnconfirmedZeroCostPoLines(Validator $validator): void
    {
        if ($this->boolean('confirm_zero_cost')) {
            return;
        }

        foreach ((array) $this->input('items', []) as $index => $item) {
            if (! is_array($item) || empty($item['po_item_id'])) {
                continue;
            }

            // A missing or non-numeric unit_cost is already refused by the
            // base rule; a second message here would only bury the first.
            if (! is_numeric($item['unit_cost'] ?? null) || round((float) $item['unit_cost'], 2) !== 0.0) {
                continue;
            }

            $validator->errors()->add(
                "items.{$index}.unit_cost",
                sprintf(
                    '"%s" diterima dengan harga satuan Rp 0 padahal tertaut baris PO — stok masuk '
                    .'bernilai nol dan HPP rata-rata gudang ikut turun. Lanjutkan hanya bila memang '
                    .'barang gratis (free-issue/bonus).',
                    $this->itemName($item['item_id'] ?? null) ?? 'Baris '.($index + 1),
                ),
            );
        }
    }

    /**
     * The item name, for a refusal the clerk can act on — "items.4.unit_cost"
     * alone does not say which of fifteen lines is the wiped one.
     */
    protected function itemName(mixed $itemId): ?string
    {
        if (! is_numeric($itemId)) {
            return null;
        }

        return DB::table('inv_items')->where('id', (int) $itemId)->value('name');
    }

    /**
     * Rules for a nullable id owned by another module (Procurement).
     *
     * These two ids are not decoration: StockService reads them to decide which
     * liability the receipt journal credits, and therefore which document is
     * expected to clear it. An id pointing at a row that does not exist would
     * name a clearing document that cannot be raised, so it is rejected here —
     * and resolved again in the service, because the API is not the only way
     * rows reach the table.
     *
     * Guarded by Schema::hasTable so Inventory still validates on an
     * installation without Procurement: the module owns the table, not us.
     * Soft-deleted rows do not count — a deleted PO cannot be billed and a
     * deleted vendor cannot be named on a bill.
     *
     * @return array<int, mixed>
     */
    protected function crossModuleId(string $table): array
    {
        $rules = ['nullable', 'integer'];

        if (Schema::hasTable($table)) {
            $rules[] = Rule::exists($table, 'id')->whereNull('deleted_at');
        }

        return $rules;
    }
}
