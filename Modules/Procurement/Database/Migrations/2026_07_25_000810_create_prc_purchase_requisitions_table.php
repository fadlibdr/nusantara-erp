<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prc_purchase_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // PR/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable(); // destination warehouse
            $table->unsignedBigInteger('requested_by')->nullable(); // users.id
            $table->date('needed_date')->nullable();
            $table->text('purpose')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('warehouse_id');
            $table->index('requested_by');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_purchase_requisitions');
    }
};
