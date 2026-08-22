<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal header + lines live in one migration (header/detail pair) so the
     * whole Finance schema fits the module's timestamp block.
     */
    public function up(): void
    {
        Schema::create('fin_journals', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // JV/{Y}/{M2}/{N4}
            $table->date('journal_date');
            $table->string('description', 500);
            // Source document morph strings, e.g. ('ar_invoice', 12). Kept loose
            // (no morph map FK) so any module can feed the ledger.
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('status', 30)->default('draft'); // draft|posted
            // Cross-module reference (users) — indexed, no DB constraint.
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reference_type', 'reference_id']);
            $table->index('journal_date');
            $table->index('status');
            $table->index('posted_by');
        });

        Schema::create('fin_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('fin_journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('fin_accounts');
            $table->string('description', 500)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            // Cross-module reference (Projects) for the project P&L — no DB constraint.
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_journal_lines');
        Schema::dropIfExists('fin_journals');
    }
};
