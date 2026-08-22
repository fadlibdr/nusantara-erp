<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AP bills (tagihan vendor) — from a PO, a subcon opname, or manual.
        Schema::create('fin_ap_bills', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // BIL/{Y}/{RM}/{N4}
            // Cross-module references — indexed, no DB constraint.
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable(); // prc_purchase_orders
            $table->unsignedBigInteger('subcontract_claim_id')->nullable(); // scm_progress_claims
            $table->date('bill_date');
            $table->date('due_date');
            $table->string('description', 500);
            $table->decimal('dpp', 18, 2);
            $table->decimal('ppn_amount', 18, 2)->default(0);
            // PPh withheld from the vendor (intra-module FK to the tax row).
            $table->foreignId('pph_tax_id')->nullable()->constrained('fin_taxes');
            $table->decimal('pph_amount', 18, 2)->default(0);
            $table->decimal('total_payable', 18, 2); // dpp + ppn - pph
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->string('vendor_invoice_no', 60);
            $table->string('faktur_pajak_no', 40)->nullable();
            $table->date('paid_at')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('vendor_id');
            $table->index('project_id');
            $table->index('purchase_order_id');
            $table->index('subcontract_claim_id');
            $table->index('status');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_ap_bills');
    }
};
