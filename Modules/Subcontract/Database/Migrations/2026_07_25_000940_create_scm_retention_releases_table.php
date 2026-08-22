<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scm_retention_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subcontract_id')->constrained('scm_subcontracts');
            $table->date('release_date');
            $table->decimal('amount', 18, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scm_retention_releases');
    }
};
