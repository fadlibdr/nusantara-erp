<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One nomor seri faktur pajak, one invoice.
 *
 * DJP issues each serial exactly once. registerFakturPajak() took any string
 * and the column carried no index, so a clerk copying the previous termin's
 * number onto the next invoice produced two FK records in the same e-Faktur
 * file under one serial — Rp 1.177.000.000 of PPN keluaran reported against a
 * number DJP issued once, with `blocked` still 0 and nothing on the screen
 * comparing the two.
 *
 * The index covers CANCELLED invoices too, and that is the point: a cancelled
 * faktur is replaced by a nota pembatalan that cites the same serial, so the
 * serial stays spent. Soft-deleted rows are equally covered, which costs
 * nothing — only an APPROVED invoice can be given a faktur number and an
 * approved invoice can no longer be deleted.
 *
 * NULL is unconstrained in both SQLite and MySQL, so any number of invoices may
 * still be waiting for their serial.
 *
 * Numbering: Finance's 001100-001199 block was exhausted on 2026_07_25 and
 * continues date-forward per the note in 2026_07_26_001100. Next free: 001115.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_ar_invoices')) {
            return;
        }

        // Refuse loudly rather than let the index creation fail with a driver
        // error nobody can act on: the operator has to decide which invoice
        // keeps the serial before the rule can be enforced.
        $duplicates = DB::table('fin_ar_invoices')
            ->whereNotNull('faktur_pajak_no')
            ->groupBy('faktur_pajak_no')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('faktur_pajak_no')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Nomor faktur pajak berikut dipakai lebih dari satu invoice dan harus dibetulkan '
                .'sebelum migrasi ini dijalankan: '.implode(', ', $duplicates)
            );
        }

        Schema::table('fin_ar_invoices', function (Blueprint $table): void {
            $table->unique('faktur_pajak_no');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_ar_invoices')) {
            return;
        }

        Schema::table('fin_ar_invoices', function (Blueprint $table): void {
            $table->dropUnique(['faktur_pajak_no']);
        });
    }
};
