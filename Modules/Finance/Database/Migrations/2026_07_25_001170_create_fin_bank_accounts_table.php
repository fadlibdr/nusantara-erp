<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('bank_name', 100);
            $table->string('account_no', 40);
            $table->string('account_name', 150);
            // COA account (1-12xx Bank child) the payments post against.
            $table->foreignId('coa_account_id')->constrained('fin_accounts');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_accounts');
    }
};
