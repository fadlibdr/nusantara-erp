<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class GoodsReceiptUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'required', 'integer', Rule::exists('inv_warehouses', 'id')],
            'purchase_order_id' => $this->crossModuleId('prc_purchase_orders'),
            'vendor_id' => $this->crossModuleId('prc_vendors'),
            'receipt_date' => ['sometimes', 'required', 'date'],
            'delivery_note_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'integer', Rule::exists('inv_items', 'id')],
            'items.*.po_item_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->refuseUnconfirmedZeroCostPoLines($validator);
        });
    }

    /**
     * Same guard as GoodsReceiptStoreRequest::refuseUnconfirmedZeroCostPoLines
     * — a draft is edited right up to posting, so a price wiped to 0 on UPDATE
     * reaches StockService exactly as easily as one typed at create.
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
     * Rules for a nullable id owned by another module (Procurement). Same
     * contract as GoodsReceiptStoreRequest: an unresolvable purchase order or
     * vendor would name a clearing document that cannot exist, so it never
     * reaches a draft receipt in the first place.
     *
     * Guarded by Schema::hasTable so Inventory still validates on an
     * installation without Procurement. Soft-deleted rows do not count — a
     * deleted PO cannot be billed and a deleted vendor cannot be named on a
     * bill.
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
