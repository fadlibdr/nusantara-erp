<?php

namespace Modules\Estimation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Estimation\Services\PriceHistoryService;

class PriceHistoryController extends ApiController
{
    public function __construct(private readonly PriceHistoryService $history) {}

    /**
     * Riwayat harga beli satu item — PO prices and GRN valuations, one series.
     *
     * No exists-rule on item_id: inv_items belongs to another module, and an
     * unknown id honestly answers item=null with an empty series, which is what
     * the screen renders as "belum pernah dibeli".
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return $this->ok($this->history->forItem(
            (int) $validated['item_id'],
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        ));
    }
}
