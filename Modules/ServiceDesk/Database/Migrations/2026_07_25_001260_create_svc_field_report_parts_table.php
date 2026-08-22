<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parts used on-site. The actual stock issue (pengeluaran barang) is booked
        // by the Inventory module referencing the field report — no stock math here.
        Schema::create('svc_field_report_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('field_report_id')->constrained('svc_field_reports');
            $table->unsignedBigInteger('item_id'); // inv_items.id (cross-module)
            $table->decimal('qty', 15, 3)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svc_field_report_parts');
    }
};
