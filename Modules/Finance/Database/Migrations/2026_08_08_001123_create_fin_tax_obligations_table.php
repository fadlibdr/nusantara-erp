<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender pajak (#25): one row per (jenis pajak, masa) — the register of the
 * company's monthly tax obligations.
 *
 * The statutory deadlines already run the cash projection
 * (CashFlowService::projectTaxes, now via Support\TaxDeadlines), but a
 * projection charges rupiah to weeks; nobody was shown WHICH masa is unpaid,
 * which SSP has its NTPN, and which SPT masa is still unreported. A missed
 * masa costs real money — 2% per month late-payment interest plus
 * Rp 100.000/Rp 500.000 denda telat lapor — and the demo's own 2-1300 balance
 * (Rp 1.067.000.000, all masa Juli) shows the sums at stake.
 *
 * MANUAL ENTRY BY DESIGN. NTPN and the setor/lapor dates are typed off the
 * real SSP/BPE — there is no e-filing integration, and pretending otherwise
 * with an "integration-shaped" schema would only invent data DJP never
 * confirmed. journal_id is a picked reference to the JV that settled the masa
 * (nullable, nothing automatic). No soft deletes: a masa row is not a
 * document, it is a fact of the calendar — rows are minted idempotently per
 * year and never destroyed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_tax_obligations', function (Blueprint $table): void {
            $table->id();
            $table->string('tax_type', 30); // TaxMasaType
            $table->unsignedSmallInteger('masa_year');
            $table->unsignedTinyInteger('masa_month');
            // The human handle ('PPh 21 masa Jul 2026') — also the display
            // column the tenggat watcher names rows by.
            $table->string('name', 60);
            $table->date('due_date'); // setoran deadline, from TaxDeadlines
            $table->decimal('amount', 18, 2)->nullable(); // SSP amount, manual
            $table->string('ntpn', 30)->nullable();
            $table->date('disetor_date')->nullable();
            $table->date('dilapor_date')->nullable();
            // JV yang menyetorkan masa ini — intra-module reference, manual pick.
            $table->foreignId('journal_id')->nullable()->constrained('fin_journals');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['tax_type', 'masa_year', 'masa_month']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_tax_obligations');
    }
};
