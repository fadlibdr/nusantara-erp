<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svc_ticket_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('svc_tickets');
            $table->unsignedBigInteger('user_id')->nullable(); // users.id (owned by app/Iam)
            $table->string('activity_type', 20)->default('comment'); // comment | status_change | assignment | work_log
            $table->text('body');
            $table->unsignedInteger('minutes_spent')->nullable(); // for work_log entries
            $table->timestamps();

            $table->index('user_id');
            $table->index('activity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svc_ticket_activities');
    }
};
