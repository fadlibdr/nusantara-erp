<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prc_vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // VND-nnnn
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('npwp', 30)->nullable();
            $table->boolean('is_pkp')->default(false);
            $table->string('sppkp_number', 50)->nullable();
            $table->boolean('is_subcontractor')->default(false);
            $table->string('classification', 30); // material | jasa | ict | sipil | me
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('pic_name', 100)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_no', 50)->nullable();
            $table->string('bank_account_name', 100)->nullable();
            $table->unsignedInteger('payment_term_days')->default(30);
            $table->decimal('rating', 3, 1)->nullable(); // rolling average of evaluations, 1.0 - 5.0
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('classification');
            $table->index('is_subcontractor');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_vendors');
    }
};
