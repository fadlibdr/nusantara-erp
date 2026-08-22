<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('est_cost_budget_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cost_budget_id')->constrained('est_cost_budgets')->cascadeOnDelete();
            // RAP lines derive from BOQ items; replacing the BOQ lines replaces them too.
            $table->foreignId('boq_item_id')->constrained('est_boq_items')->cascadeOnDelete();
            $table->string('cost_category', 20); // material | labor | subcon | equipment | overhead
            $table->string('description', 500);
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index('cost_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('est_cost_budget_items');
    }
};
