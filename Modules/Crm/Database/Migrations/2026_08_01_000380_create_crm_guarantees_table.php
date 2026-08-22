<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Register jaminan & asuransi — bank guarantees and project insurance policies.
 *
 * The demo data shows what "not having this table" costs. Termin 1 of
 * CTR/2026/I/0001 (DP 20% of Rp 48,5 miliar = Rp 9,7 miliar) is conditioned on
 * "penyerahan jaminan uang muka", and the approval note says "jaminan uang muka
 * sudah diterima" — so an advance-payment bond of around Rp 9,7 miliar exists,
 * but its number, issuer and EXPIRY DATE live nowhere. A bond that lapses while
 * the advance is still unamortised leaves that money unsecured, and nobody is
 * told, because there is no date for anything to watch.
 *
 * This is a REGISTER, not a document: no code sequence, no approval flow, no GL.
 * The bank's own number is the identity — unique(issuer, number) — because two
 * banks can and do collide on plain numbers like "001/BG/2026".
 *
 * 'expired' is deliberately NOT a status value. It is derived from end_date, so
 * a stale status left at 'active' cannot silence erp:deadline-watch — the
 * watcher reads end_date on active rows directly. The operator only records the
 * two things the paper actually says: dikembalikan (released) or dicairkan
 * (claimed).
 *
 * A bid bond attaches to the tender before any contract exists, which is why
 * both FKs are nullable with an at-least-one guard in the requests instead of a
 * single required contract_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_guarantees', function (Blueprint $table): void {
            $table->id();
            $table->string('guarantee_type', 30); // GuaranteeType: bid_bond|performance_bond|advance_payment_bond|maintenance_bond|car|tpl|lainnya
            $table->string('number', 100);        // the bank's/insurer's own number — the identity
            $table->string('issuer', 160);        // bank penerbit / perusahaan asuransi
            $table->foreignId('contract_id')->nullable()->constrained('crm_contracts')->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('crm_quotations')->restrictOnDelete();
            $table->decimal('value', 18, 2);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 30)->default('active'); // GuaranteeStatus: active|released|claimed — never 'expired'
            $table->string('document_location', 160)->nullable(); // fisik: brankas, bank, customer
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['issuer', 'number']);
            $table->index('guarantee_type');
            $table->index('end_date'); // the column erp:deadline-watch scans daily
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_guarantees');
    }
};
