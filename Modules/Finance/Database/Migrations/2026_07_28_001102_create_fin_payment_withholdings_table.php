<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax withheld by the customer when a termin is paid.
 *
 * A badan-usaha or government owner is REQUIRED to withhold PPh final jasa
 * konstruksi (PP 9/2022, 1,75%–6% of the DPP) out of every progress payment,
 * and a BUMN/government owner additionally collects the PPN itself (PPN wapu,
 * PMK 231/2019). The money that reaches the bank is therefore always SMALLER
 * than the invoice — and PaymentService demanded that allocations sum exactly to
 * the cash received and knew no line other than an allocation to an invoice. So
 * a real receipt could not be recorded at all: the operator either understated
 * the settlement, leaving a permanently "underpaid" invoice poisoning the aging
 * report, or overstated the cash, and then bank reconciliation never balanced.
 * 1-1700 Pajak Dibayar Dimuka PPh was seeded in the chart and posted to by not
 * one line of code.
 *
 * WHY ITS OWN TABLE RATHER THAN COLUMNS ON fin_payment_allocations
 *
 * An allocation answers "how much of this document did this payment settle" —
 * it is the row amount_paid is built from, and every one of them is cash the
 * bank moved. A withholding is a different fact about the same settlement: no
 * cash moved, the counterparty deposited it to the state on our behalf, and it
 * carries its own statutory document (bukti potong / bukti pungut) whose NUMBER
 * and DATE we must archive for years because they are the only evidence
 * supporting the PPh credit in the annual return.
 *
 * Folding both into the allocation table would mean a `kind` discriminator plus
 * two certificate columns that are null on the overwhelming majority of rows,
 * and — worse — "sum of allocations" would stop meaning one single thing. Here
 * the invariant stays legible instead:
 *
 *     sum(allocations) = payment.amount + sum(withholdings)
 *
 * so the invoice is settled in FULL while the bank leg carries only the net.
 *
 * The row points at the invoice, not merely at the payment: a bukti potong is
 * issued against a specific faktur, and the guard that a withholding cannot
 * exceed what is left on that invoice needs to know which one.
 *
 * Numbering: Finance's 001100–001199 block was exhausted on 2026_07_25 and
 * continued date-forward per the note in 2026_07_26_001100. Next free: 001104.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_payment_withholdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('fin_payments')->cascadeOnDelete();
            // Intra-module FK: a withholding without its invoice is unusable —
            // there is nothing left to prove what the bukti potong relates to.
            $table->foreignId('ar_invoice_id')->constrained('fin_ar_invoices');
            $table->string('type', 20); // pph_final | ppn_wapu (WithholdingType)
            $table->decimal('amount', 18, 2);
            // Bukti potong (PPh) / bukti pungut (PPN wapu). Mandatory for PPh
            // final — that number is the tax credit — optional for wapu, where
            // the owner's SSP often arrives later than the payment.
            $table->string('certificate_no', 100)->nullable();
            $table->date('certificate_date')->nullable();
            $table->timestamps();

            $table->index('ar_invoice_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_payment_withholdings');
    }
};
