<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No softDeletes here on purpose: the unique (year, month) pair must stay
        // enforceable — a soft-deleted run would block the period forever. Draft
        // runs are hard-deleted / rebuilt by DepreciationService instead.
        Schema::create('ast_depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // DPR/{Y}/{M2}/{N3}
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month'); // 1-12
            $table->string('status', 30)->default('draft'); // draft/posted
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->dateTime('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['period_year', 'period_month']);
        });

        Schema::create('ast_depreciation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('depreciation_run_id')->constrained('ast_depreciation_runs')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('ast_assets');
            $table->decimal('amount', 18, 2);
            $table->decimal('book_value_after', 18, 2);
            $table->timestamps();

            $table->unique(['depreciation_run_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ast_depreciation_entries');
        Schema::dropIfExists('ast_depreciation_runs');
    }
};
