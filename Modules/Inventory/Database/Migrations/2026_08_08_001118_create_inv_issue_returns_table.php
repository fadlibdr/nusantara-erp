<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur material dari proyek (temuan 37): the PARTIAL way back for a posted bon.
 *
 * cancelIssue() unwinds a whole document, but the everyday case is smaller —
 * 150 zak issued, 30 come back when the pekerjaan finishes early. The two
 * workarounds that existed were both dishonest: a GRN without a vendor books
 * the come-back against EQUITY 3-3100 (saldo awal, for stock nobody bought),
 * and an opname books it against EXPENSE 6-4400 (selisih, for stock that was
 * lost) — and neither touches the fin_project_costs rows the issue wrote, so
 * the project P&L kept carrying material that was demonstrably back on the
 * shelf.
 *
 * Each return line references the ISSUE LINE it reverses, because that line
 * carries the one figure the whole document is about: unit_cost, the price the
 * goods LEFT at. Stock comes back at that price — never at today's average —
 * so the slice of project cost reversed is exactly the slice once booked.
 * The reference is also what makes "never return more than was issued" checkable
 * cumulatively across several partial returns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_issue_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // RTM/{Y}/{RM}/{N4}
            $table->foreignId('issue_id')->constrained('inv_issues');
            // Copied from the bon at creation: goods return to the warehouse
            // they left, and a return that could name any other warehouse
            // would un-issue stock a different gudang never held.
            $table->foreignId('warehouse_id')->constrained('inv_warehouses');
            $table->date('return_date');
            $table->unsignedBigInteger('returned_by')->nullable(); // users.id
            // Mandatory, like a cancellation reason: material coming back off a
            // site is the symptom of over-issuing, and "sisa" tells an auditor
            // nothing a year later.
            $table->string('reason', 500);
            $table->string('status', 30)->default('draft'); // draft / posted
            $table->timestamps();
            $table->softDeletes();

            $table->index('issue_id');
            $table->index('status');
        });

        Schema::create('inv_issue_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_return_id')->constrained('inv_issue_returns');
            $table->foreignId('issue_item_id')->constrained('inv_issue_items');
            // Denormalised from the issue line so the document still reads after
            // the bon's lines are eager-loaded elsewhere; posting re-copies it.
            $table->foreignId('item_id')->constrained('inv_items');
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_cost', 18, 2)->default(0); // frozen from the issue line at posting
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index('issue_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_issue_return_items');
        Schema::dropIfExists('inv_issue_returns');
    }
};
