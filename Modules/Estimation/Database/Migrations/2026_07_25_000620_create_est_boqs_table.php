<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('est_boqs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            // Cross-module references (Projects / Crm) — indexed, no DB constraint.
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('quotation_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('title', 255);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 30)->default('draft');
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('quotation_id');
            $table->index('contract_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('est_boqs');
    }
};
