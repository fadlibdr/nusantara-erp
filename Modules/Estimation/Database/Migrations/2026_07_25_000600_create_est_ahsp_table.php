<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('est_ahsp', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 255);
            $table->string('unit', 20);
            $table->string('category', 20); // sipil | arsitektur | mep | elv | ict
            $table->decimal('overhead_pct', 8, 4)->default(10);
            // Cached result of sum(coefficient * unit_price) * (1 + overhead_pct/100),
            // maintained by AhspService::recalcUnitPrice().
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('est_ahsp');
    }
};
