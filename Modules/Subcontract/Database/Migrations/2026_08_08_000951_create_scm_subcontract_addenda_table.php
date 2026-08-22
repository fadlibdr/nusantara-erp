<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addendum SPK — pekerjaan tambah-kurang subkontraktor (temuan #48).
 *
 * An approved SPK is permanently immutable (assertEditable allows only draft
 * and rejected), and ClaimService::assertWithinContractValue locks cumulative
 * opname at the ORIGINAL value. Both are correct on their own — and together
 * they left field scope changes with no path at all: when the customer's CCO
 * pushed added work down to the subcontractor, the only way to record it was a
 * second unrelated SPK, splitting progress history, retention and evaluation
 * across two documents for one paket pekerjaan.
 *
 * The shape mirrors Crm's ContractChangeOrder deliberately. The addendum is
 * its own approvable document; the SPK row is untouched until it is APPROVED,
 * and then its value is adjusted rather than replaced —
 * scm_subcontracts.original_value keeps what was signed, so "what did we agree
 * to" and "what is it worth now" stay two separate questions.
 *
 * Existing SPK LINES are never modified, for the same reason CCO never
 * re-spreads termins: a line whose progress is already opnamed cannot have its
 * amount moved without restating every approved claim built on
 * `period_progress_pct × amount`. Added scope arrives as NEW lines (the
 * addendum items below); removed scope only lowers the value/plafon — the
 * over-provisioned lines simply never reach 100 %.
 *
 * change_type ships on day one (tambah_kurang | eskalasi_harga): Crm needed it
 * retrofitted (temuan #61) after escalations were recorded as added work that
 * misled exactly the audit the escalation clause exists for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scm_subcontract_addenda', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();          // ADS/{Y}/{RM}/{N4}
            $table->foreignId('subcontract_id')->constrained('scm_subcontracts');
            $table->date('addendum_date');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('reason', 40)->nullable();       // permintaan_pemberi_kerja|kondisi_lapangan|desain|lainnya
            $table->string('change_type', 30)->default('tambah_kurang'); // cast to AddendumChangeType

            // SIGNED. Negative is pekerjaan kurang, as ordinary as added scope;
            // an unsigned column would force a direction flag every reader has
            // to remember to apply. Same reasoning as crm value_change.
            $table->decimal('value_change', 18, 2);

            // Stamped on submit against the SAME threshold the SPK itself uses
            // (approvals.subcontract.threshold_two_level), computed on the
            // POST-CHANGE value. Without it the addendum is the side door
            // around the SPK director gate: an SPK of Rp 190 juta approved
            // without a director plus a Rp 50 juta addendum is a Rp 240 juta
            // commitment no director ever saw.
            $table->boolean('needs_director_approval')->default(false);

            $table->string('status', 30)->default('draft'); // draft|submitted|approved|rejected
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subcontract_id', 'status']);
            $table->index('addendum_date');
        });

        // New SPK lines the addendum brings with it, appended on approval with
        // progress 0. Line detail table: no softDeletes, per conventions.
        Schema::create('scm_subcontract_addendum_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('addendum_id')->constrained('scm_subcontract_addenda')->cascadeOnDelete();
            $table->string('wbs_code', 20)->nullable();
            $table->string('description', 500);
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2); // qty × unit_price, computed by the service
            $table->timestamps();
        });

        Schema::table('scm_subcontracts', function (Blueprint $table): void {
            // What was signed, before any addendum. Null on SPKs that have
            // never had one — backfilled on the first approval, so the column
            // means "the value this SPK started at" rather than "a copy".
            $table->decimal('original_value', 18, 2)->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('scm_subcontracts', function (Blueprint $table): void {
            $table->dropColumn('original_value');
        });

        Schema::dropIfExists('scm_subcontract_addendum_items');
        Schema::dropIfExists('scm_subcontract_addenda');
    }
};
