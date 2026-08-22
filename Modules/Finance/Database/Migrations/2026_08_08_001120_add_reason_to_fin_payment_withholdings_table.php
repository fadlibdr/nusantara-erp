<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alasan tertulis pada baris potongan (temuan #15 — denda keterlambatan).
 *
 * A tax withholding explains itself through its statutory paper: the bukti
 * potong / bukti pungut number IS the story, and the certificate columns carry
 * it. A 'potongan lain-lain' — liquidated damages the owner deducts from a
 * late termin, a back-charged repair — has no such paper. Without a written
 * reason the row is a difference with no story: the auditor finds a 7-2400
 * debit and nobody can say who deducted it or under which contract clause.
 *
 * Nullable because the three tax kinds keep meaning what they always meant;
 * PaymentService REQUIRES it for WithholdingType::OtherDeduction rows.
 *
 * Numbering: Finance's 001100-001199 block was exhausted on 2026_07_25 and
 * continues date-forward; 2026_08_03_001116 was the last taken before the
 * 2026_08_08 batch (001120+ is this lane's range).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_payment_withholdings', function (Blueprint $table): void {
            $table->string('reason', 200)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('fin_payment_withholdings', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });
    }
};
