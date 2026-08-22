<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment header + allocations live in one migration (header/detail pair)
     * so the whole Finance schema fits the module's timestamp block.
     */
    public function up(): void
    {
        Schema::create('fin_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // RCV/{Y}/{RM}/{N4} in, PAY/{Y}/{RM}/{N4} out
            $table->string('direction', 10); // in|out
            $table->date('payment_date');
            $table->foreignId('bank_account_id')->constrained('fin_bank_accounts');
            $table->decimal('amount', 18, 2);
            $table->string('reference', 100)->nullable(); // bank mutation / transfer ref
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft'); // draft|posted
            $table->timestamps();
            $table->softDeletes();

            $table->index('direction');
            $table->index('status');
            $table->index('payment_date');
        });

        Schema::create('fin_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('fin_payments')->cascadeOnDelete();
            $table->string('payable_type', 20); // ar_invoice|ap_bill
            $table->unsignedBigInteger('payable_id');
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_payment_allocations');
        Schema::dropIfExists('fin_payments');
    }
};
