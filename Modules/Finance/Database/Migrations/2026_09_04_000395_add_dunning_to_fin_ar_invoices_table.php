<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3.7 — how far the dunning of a customer invoice has gone.
 *
 * Production, 4 Sep 2026 (ANALISIS-PROSES §2 row Q2C, §3 gap A2):
 * INV/2026/VIII/0004 Rp 15,42 M is approved and falls due 22 Sep — the
 * ar_invoice_due watcher will name it the morning after, and then nothing:
 * there is no surat penagihan in the system, so "diawasi tetapi tanpa
 * tindakan". The three house letters (Surat Penagihan ke-1/2/3) need to know
 * which one comes next and when the last one went out, and that is these two
 * columns.
 *
 * dunning_level     0 = no letter yet; 1..3 = the highest letter ISSUED.
 *                   Only ArInvoiceService::issueDunningLetter moves it, by
 *                   exactly one, and only after the due date has passed —
 *                   a letter that says "telah jatuh tempo" before the due date
 *                   would be a false claim under the company's letterhead.
 * last_dunning_at   when that letter was issued. It dates the letter of the
 *                   CURRENT level on every reprint; an earlier level's date is
 *                   not kept, which is why an earlier letter is refused rather
 *                   than re-dated (FinanceFormService::dunningLetterDate).
 *
 * hasColumn guard + rollback: the 000394 / 000870 pattern. FORWARD-ONLY, no
 * backfill: 0 is the truth for every existing invoice — none has had a letter,
 * because there were none to print.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fin_ar_invoices', 'dunning_level')) {
            return;
        }

        Schema::table('fin_ar_invoices', function (Blueprint $table): void {
            $table->unsignedTinyInteger('dunning_level')->default(0)->after('paid_at');
            $table->dateTime('last_dunning_at')->nullable()->after('dunning_level');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fin_ar_invoices', 'dunning_level')) {
            return;
        }

        Schema::table('fin_ar_invoices', function (Blueprint $table): void {
            $table->dropColumn(['dunning_level', 'last_dunning_at']);
        });
    }
};
