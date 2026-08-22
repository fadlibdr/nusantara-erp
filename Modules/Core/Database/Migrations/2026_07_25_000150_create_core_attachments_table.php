<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to documents.
 *
 * `path` is GENERATED, never derived from what the uploader called the file.
 * The original name is kept in its own column for display only, so a file named
 * "../../.env" or "invoice.php" is a label and nothing more — it never reaches
 * the filesystem and never decides how anything is served.
 *
 * Files live on the local disk under storage/app, which nginx does not serve.
 * The only way to read one is the download endpoint, which checks the
 * permission of the module the document belongs to. Putting them under
 * public/ would make every attachment world-readable to anyone who guessed a
 * path, which for scanned invoices and employee documents is a data breach with
 * no authentication step to get wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_attachments', function (Blueprint $table): void {
            $table->id();
            // The document this belongs to, stored as the class name. It is
            // never accepted from the client: the API takes a slug and resolves
            // it through AttachableDocuments.
            $table->string('attachable_type', 120);
            $table->unsignedBigInteger('attachable_id');
            $table->string('disk', 20)->default('local');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->string('extension', 10);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('caption', 255)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_attachments');
    }
};
