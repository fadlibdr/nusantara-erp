<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('npwp', 30)->nullable();
            $table->boolean('is_pkp')->default(false);
            $table->string('billing_address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('pic_name', 100)->nullable();
            $table->string('pic_phone', 30)->nullable();
            $table->unsignedSmallInteger('payment_term_days')->default(30);
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_customers');
    }
};
