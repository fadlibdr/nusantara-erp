<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pekerjaan tambah-kurang — change orders against a signed contract.
 *
 * An approved contract is permanently immutable (DocumentStatus::isEditable()
 * allows only Draft and Rejected), which is correct: the signed value is the
 * basis of every termin invoice raised against it, and letting somebody edit it
 * in place would silently rewrite what has already been billed.
 *
 * The consequence was that legitimate added or removed scope had NO path at all.
 * The two available workarounds were both wrong — a second unrelated contract,
 * which breaks the one-project-one-contract link that Project.contract_id
 * assumes, or editing the database by hand.
 *
 * A change order is its own approvable document. It leaves the contract row
 * alone until it is approved, and then adds its value rather than replacing it;
 * crm_contracts.original_value keeps what was signed, so "what did we agree to"
 * and "what is it worth now" remain separate questions with separate answers.
 *
 * It never touches existing termins. Re-spreading percentages across a schedule
 * whose early milestones are already invoiced would restate history — added
 * scope is billed through termins the change order brings with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contract_change_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();          // CCO/{Y}/{RM}/{N4}
            $table->foreignId('contract_id')->constrained('crm_contracts')->cascadeOnDelete();
            $table->date('change_date');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('reason', 40)->nullable();       // permintaan_pelanggan|kondisi_lapangan|desain|lainnya

            // SIGNED. Negative is pekerjaan kurang, and it is as ordinary as
            // added scope — an unsigned column would force a separate "kurang"
            // flag that every reader would have to remember to apply.
            $table->decimal('value_change', 18, 2);
            $table->decimal('ppn_change', 18, 2)->default(0);

            $table->string('customer_ref', 60)->nullable(); // the customer's own CCO number
            $table->string('status', 30)->default('draft'); // draft|submitted|approved|rejected
            $table->timestamps();
            $table->softDeletes();

            $table->index(['contract_id', 'status']);
            $table->index('change_date');
        });

        Schema::table('crm_contracts', function (Blueprint $table): void {
            // What was signed, before any change order. Null on contracts that
            // have never had one — backfilled on the first approval, so the
            // column means "the value this started at" rather than "a copy".
            $table->decimal('original_value', 18, 2)->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contracts', function (Blueprint $table): void {
            $table->dropColumn('original_value');
        });

        Schema::dropIfExists('crm_contract_change_orders');
    }
};
