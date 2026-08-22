<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications.
 *
 * Deliberately a plain table rather than Laravel's `notifications` with its JSON
 * `data` blob: every consumer here wants to filter and count by recipient and
 * read state, and to link back to a document. Columns do that; a JSON blob makes
 * the unread badge a full-table scan and the document link unqueryable.
 *
 * The rows are a LOG, not a queue. Deleting one does not un-happen the approval
 * it describes, so nothing else keys on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event', 40);                // document.submitted|approved|rejected
            $table->string('title', 200);
            $table->text('body')->nullable();
            // Where the reader is sent. An SPA hash route, stored rather than
            // derived, so a notification about a document type that is later
            // renamed still points somewhere.
            $table->string('link', 255)->nullable();
            $table->string('document_type', 120)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_code', 40)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The unread badge is the hottest query in the application: it runs
            // on every screen paint.
            $table->index(['user_id', 'read_at']);
            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_notifications');
    }
};
