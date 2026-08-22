<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what.
 *
 * Nothing recorded this before. core_approvals held approval ACTIONS and
 * core_notifications held what people were told, but a vendor's bank account
 * number could be edited, the PPN rate changed, a draft journal rewritten or a
 * user deactivated, and the row was simply overwritten with no trace of who did
 * it or what it said before.
 *
 * Changing a vendor's bank details is the classic invoice-fraud vector, and it
 * was precisely the change that left no evidence.
 *
 * This is APPEND-ONLY by intent. There is no update path and no delete path in
 * the application; a log an application can edit is not a log. Retention and
 * pruning are an operator's decision, taken deliberately, not a feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_audit_log', function (Blueprint $table): void {
            $table->id();

            // Nullable and nullOnDelete: a change made by a user who is later
            // deleted must not delete the record OF the change, and seeders and
            // console commands legitimately act with no user at all.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Kept alongside the FK so the actor is still named after the
            // account is gone — the id alone would become an orphan number.
            $table->string('user_name', 120)->nullable();

            $table->string('event', 20);              // created|updated|deleted
            $table->string('auditable_type', 120);
            $table->unsignedBigInteger('auditable_id');
            // A human handle for the row — code, name or email — so the log is
            // readable without joining to a record that may no longer exist.
            $table->string('auditable_label', 160)->nullable();

            // Only the attributes that actually changed: {"field": {"from": …, "to": …}}
            $table->json('changes');

            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_audit_log');
    }
};
