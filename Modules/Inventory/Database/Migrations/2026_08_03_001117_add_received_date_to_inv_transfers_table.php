<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the goods actually ARRIVED, which is no longer always the day they left.
 *
 * StockService::receiveTransfer() used to date the destination movement on the
 * transfer's send date and gate the fiscal period on that same date, so closing
 * a month over a moving truck stranded the goods for good — Rp 12.400.000 in
 * 1-1400 with nothing in either warehouse balance and no document able to bring
 * it back. The receipt is now an event of the day it happens whenever the send
 * date's period is no longer open, exactly as JournalService::reversalDate()
 * moves a reversal. That makes "sent 28 July, received 4 August" a real state,
 * and a stock card row dated after the transfer it belongs to is unreadable
 * unless the transfer says when it landed.
 *
 * Nullable and NOT backfilled: transfers received before this column existed
 * arrived on their transfer_date by construction, but the demo cannot prove the
 * hour and a data migration that invents business dates is not on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inv_transfers') || Schema::hasColumn('inv_transfers', 'received_date')) {
            return;
        }

        Schema::table('inv_transfers', function (Blueprint $table): void {
            $table->date('received_date')->nullable()->after('transfer_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inv_transfers') || ! Schema::hasColumn('inv_transfers', 'received_date')) {
            return;
        }

        Schema::table('inv_transfers', function (Blueprint $table): void {
            $table->dropColumn('received_date');
        });
    }
};
