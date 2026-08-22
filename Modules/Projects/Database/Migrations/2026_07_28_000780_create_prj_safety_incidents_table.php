<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Register kecelakaan kerja (SMK3).
 *
 * The entire safety surface was one free-text column, prj_daily_reports.safety_notes.
 * The seed data shows what that costs: "Satu near-miss material jatuh dari lantai 5"
 * — an event PP 50/2012 and Permen PUPR 10/2021 require to be recorded and followed
 * up, buried in prose with no severity, no cause, no corrective action, nobody
 * accountable, and no closing date. There was no way to ask "every incident this
 * quarter", and no way to produce the monthly K3 report a project is contractually
 * obliged to hand its client.
 *
 * The columns here are chosen to answer that report. Frequency and severity rates
 * need the count of recordable incidents and the days lost; the follow-up columns
 * (root cause, corrective action, who owns it, when it is due, when it closed) are
 * what makes the register an instrument rather than a list of misfortunes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_safety_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();

            // Time of day, not just the date: shift patterns are half of what a
            // safety review looks for, and "16:40, end of the late shift" is the
            // finding. A date alone throws that away.
            $table->dateTime('occurred_at');
            $table->string('location', 150)->nullable(); // where on site — zona/lantai
            $table->string('severity', 30);              // near_miss … fatality
            $table->string('category', 40);              // fall_from_height, struck_by, …
            $table->text('description');
            $table->unsignedSmallInteger('people_involved')->default(0);
            // Hari kerja hilang. The numerator of the severity rate, and the one
            // number a client's HSE officer always asks for.
            $table->unsignedSmallInteger('lost_days')->default(0);

            $table->text('immediate_action')->nullable(); // tindakan segera di lokasi
            $table->text('root_cause')->nullable();       // hasil investigasi
            $table->text('corrective_action')->nullable(); // tindakan korektif/pencegahan

            // Who owns the corrective action, and by when. Nullable because an
            // incident is recorded the moment it happens — before anybody has been
            // assigned to it. Closing one without both is refused by the service.
            $table->foreignId('responsible_employee_id')->nullable()
                ->constrained('hr_employees')->nullOnDelete();
            $table->date('due_date')->nullable();

            $table->string('status', 20)->default('open'); // open | investigating | closed
            $table->date('closed_at')->nullable();

            // Reportable to Disnaker / the client's HSE under PP 50/2012. A flag,
            // not a derivation: whether an event crosses the reporting threshold
            // is a judgement the safety officer makes, and the register records
            // the judgement rather than second-guessing it.
            $table->boolean('is_reportable')->default(false);
            $table->date('reported_to_authority_at')->nullable();

            // User semantics (users.id) — app-owned, no DB constraint.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'occurred_at']);
            $table->index(['status', 'due_date']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_safety_incidents');
    }
};
