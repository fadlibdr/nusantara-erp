<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();
            $table->string('name');
            $table->date('due_date');
            $table->date('achieved_date')->nullable();
            // Cross-module reference (crm_contract_termins) — indexed, no DB constraint.
            $table->unsignedBigInteger('termin_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('termin_id');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_milestones');
    }
};
