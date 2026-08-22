<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ast_maintenances', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // MTC/{Y}/{RM}/{N3}
            $table->foreignId('asset_id')->constrained('ast_assets');
            $table->date('maintenance_date');
            $table->string('maintenance_type', 30); // service_rutin/perbaikan/kalibrasi
            $table->unsignedBigInteger('vendor_id')->nullable(); // prc_vendors.id (external workshop)
            $table->decimal('cost', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->date('next_due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('vendor_id');
            $table->index('maintenance_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ast_maintenances');
    }
};
