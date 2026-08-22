<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\GoodsReceiptController;
use Modules\Inventory\Http\Controllers\IssueController;
use Modules\Inventory\Http\Controllers\IssueReturnController;
use Modules\Inventory\Http\Controllers\ItemCategoryController;
use Modules\Inventory\Http\Controllers\ItemController;
use Modules\Inventory\Http\Controllers\PurchaseReturnController;
use Modules\Inventory\Http\Controllers\StockAdjustmentController;
use Modules\Inventory\Http\Controllers\StockController;
use Modules\Inventory\Http\Controllers\TransferController;
use Modules\Inventory\Http\Controllers\WarehouseController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Item categories
    Route::get('item-categories', [ItemCategoryController::class, 'index']);
    Route::post('item-categories', [ItemCategoryController::class, 'store'])->middleware('permission:inv.create');
    Route::get('item-categories/{itemCategory}', [ItemCategoryController::class, 'show']);
    Route::put('item-categories/{itemCategory}', [ItemCategoryController::class, 'update'])->middleware('permission:inv.update');
    Route::delete('item-categories/{itemCategory}', [ItemCategoryController::class, 'destroy'])->middleware('permission:inv.delete');

    // Items (canonical item master for the whole ERP)
    Route::get('items', [ItemController::class, 'index']);
    Route::post('items', [ItemController::class, 'store'])->middleware('permission:inv.create');
    Route::get('items/{item}', [ItemController::class, 'show']);
    Route::put('items/{item}', [ItemController::class, 'update'])->middleware('permission:inv.update');
    Route::delete('items/{item}', [ItemController::class, 'destroy'])->middleware('permission:inv.delete');

    // Warehouses (gudang pusat & gudang site)
    Route::get('warehouses', [WarehouseController::class, 'index']);
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('permission:inv.create');
    Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show']);
    Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('permission:inv.update');
    Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('permission:inv.delete');

    // Goods receipts (GRN / penerimaan barang)
    Route::get('goods-receipts', [GoodsReceiptController::class, 'index']);
    Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('permission:inv.create');
    Route::get('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show']);
    Route::put('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'update'])->middleware('permission:inv.update');
    Route::delete('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'destroy'])->middleware('permission:inv.delete');
    Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->middleware('permission:inv.post');
    // Cancelling walks the stock back out, posts a reversing journal and
    // reopens the PO, so it is a posting right (inv.post), not a delete right
    // — the same call issues/{issue}/cancel makes, whole-document where the
    // retur pembelian below is the partial way back (audit T37).
    Route::post('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel'])->middleware('permission:inv.post');
    // "Buat Retur" off the GRN detail: drafts a retur pembelian covering the
    // remaining returnable quantities. Creating a DRAFT, so inv.create — the
    // posting act (stock, clearing, PO) sits on the retur's own /post below.
    Route::post('goods-receipts/{goodsReceipt}/returns', [PurchaseReturnController::class, 'storeFromReceipt'])->middleware('permission:inv.create');

    // Material issues (pengeluaran barang ke proyek)
    Route::get('issues', [IssueController::class, 'index']);
    Route::post('issues', [IssueController::class, 'store'])->middleware('permission:inv.create');
    Route::get('issues/{issue}', [IssueController::class, 'show']);
    Route::put('issues/{issue}', [IssueController::class, 'update'])->middleware('permission:inv.update');
    Route::delete('issues/{issue}', [IssueController::class, 'destroy'])->middleware('permission:inv.delete');
    Route::post('issues/{issue}/post', [IssueController::class, 'post'])->middleware('permission:inv.post');
    // Cancelling puts the stock back and posts a reversing journal, so it is a
    // posting right (inv.post), not a delete right — the same call Finance's
    // ap-bills/{bill}/cancel makes on fin.post.
    Route::post('issues/{issue}/cancel', [IssueController::class, 'cancel'])->middleware('permission:inv.post');
    // "Buat Retur" off the bon detail — the partial way back where /cancel is
    // the whole-document one. Drafting only, so inv.create.
    Route::post('issues/{issue}/returns', [IssueReturnController::class, 'storeFromIssue'])->middleware('permission:inv.create');

    // Retur material dari proyek (pengembalian sisa material ke gudang)
    Route::get('issue-returns', [IssueReturnController::class, 'index']);
    Route::post('issue-returns', [IssueReturnController::class, 'store'])->middleware('permission:inv.create');
    Route::get('issue-returns/{issueReturn}', [IssueReturnController::class, 'show']);
    Route::put('issue-returns/{issueReturn}', [IssueReturnController::class, 'update'])->middleware('permission:inv.update');
    Route::delete('issue-returns/{issueReturn}', [IssueReturnController::class, 'destroy'])->middleware('permission:inv.delete');
    Route::post('issue-returns/{issueReturn}/post', [IssueReturnController::class, 'post'])->middleware('permission:inv.post');

    // Retur pembelian ke vendor (barang kembali atas satu GRN)
    Route::get('purchase-returns', [PurchaseReturnController::class, 'index']);
    Route::post('purchase-returns', [PurchaseReturnController::class, 'store'])->middleware('permission:inv.create');
    Route::get('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show']);
    Route::put('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'update'])->middleware('permission:inv.update');
    Route::delete('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'destroy'])->middleware('permission:inv.delete');
    Route::post('purchase-returns/{purchaseReturn}/post', [PurchaseReturnController::class, 'post'])->middleware('permission:inv.post');

    // Transfers (antar gudang)
    Route::get('transfers', [TransferController::class, 'index']);
    Route::post('transfers', [TransferController::class, 'store'])->middleware('permission:inv.create');
    Route::get('transfers/{transfer}', [TransferController::class, 'show']);
    Route::put('transfers/{transfer}', [TransferController::class, 'update'])->middleware('permission:inv.update');
    Route::delete('transfers/{transfer}', [TransferController::class, 'destroy'])->middleware('permission:inv.delete');
    Route::post('transfers/{transfer}/send', [TransferController::class, 'send'])->middleware('permission:inv.post');
    Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->middleware('permission:inv.post');

    // Stock adjustments (opname / damage / loss) — approval posts the ledger
    Route::get('stock-adjustments', [StockAdjustmentController::class, 'index']);
    Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])->middleware('permission:inv.create');
    Route::get('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show']);
    Route::put('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'update'])->middleware('permission:inv.update');
    Route::delete('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'destroy'])->middleware('permission:inv.delete');
    Route::post('stock-adjustments/{stockAdjustment}/submit', [StockAdjustmentController::class, 'submit'])->middleware('permission:inv.update');
    Route::post('stock-adjustments/{stockAdjustment}/approve', [StockAdjustmentController::class, 'approve'])->middleware('permission:inv.approve');
    Route::post('stock-adjustments/{stockAdjustment}/reject', [StockAdjustmentController::class, 'reject'])->middleware('permission:inv.approve');

    // Stock reports
    Route::get('stock/balances', [StockController::class, 'balances']);
    Route::get('stock/ledger', [StockController::class, 'ledger']);
    Route::get('stock/low-stock', [StockController::class, 'lowStock']);
});
