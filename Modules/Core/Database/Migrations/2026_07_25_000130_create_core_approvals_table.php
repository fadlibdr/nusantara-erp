<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_approvals', function (Blueprint $table): void {
            $table->id();
            $table->morphs('approvable');
            $table->string('action', 20); // submitted | approved | rejected
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_approvals');
    }
};
