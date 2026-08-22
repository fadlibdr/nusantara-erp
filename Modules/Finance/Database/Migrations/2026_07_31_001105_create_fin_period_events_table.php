<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The permanent record of every close and every reopen.
 *
 * A reopen is the audit-sensitive act: it makes a month that has already been
 * reported changeable again. Without a row here the only trace it leaves is a
 * status column that reads exactly the same as a period that was never closed
 * at all — nothing to ask a question about six months later.
 *
 * `checklist` IS NEVER READ BACK AS A GATE. It is the computed state at the
 * instant of the action, stored so an auditor can see what the closer was
 * looking at when they overrode a warning. Every screen and every guard
 * recomputes from the source tables; a snapshot that were trusted would go
 * stale the moment somebody posted the draft journal it complained about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_period_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_period_id')->constrained('fin_fiscal_periods')->cascadeOnDelete();
            $table->string('action', 20); // closed|reopened
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('note')->nullable();
            $table->json('overrides')->nullable();  // the warning keys the closer accepted
            $table->json('checklist')->nullable();  // evidence only — see the note above
            $table->timestamps();

            $table->index(['fiscal_period_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_period_events');
    }
};
