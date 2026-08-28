<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-QC: concrete-sample custody (benda uji) and its strength tests.
 *
 * A sample is a set of specimens cast from one pour (a truck, a location, a
 * grade); a test is one specimen broken at an age (7/14/28 days) reporting a
 * strength in MPa. `pass` is COMPUTED — never typed — against the grade's
 * age-adjusted target by ConcreteStrengthService, which carries the SNI/PBI
 * relation and its source. This is the honesty core of the paket: pass/fail on a
 * signed test sheet must be the arithmetic, not an opinion.
 *
 * No document number: a sample is identified by its pour (F/BU prints the pour
 * identity, not a minted code), which keeps the paket to the two masks the seam
 * pins (QCI, NCR).
 *
 * Cross-module: project_id → prj_projects, location_id → core_locations;
 * unsignedBigInteger + index, NO constraint (CONVENTIONS §3). Tests are a line
 * table cascading with the sample (no softDeletes, §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_concrete_samples', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id'); // prj_projects — indexed, no FK
            $table->unsignedBigInteger('location_id'); // core_locations — indexed, no FK
            $table->date('pour_date');
            $table->string('grade', 20); // K-350 / fc'25 — parsed by ConcreteStrengthService
            $table->decimal('slump_cm', 6, 2)->nullable();
            $table->string('truck_no', 30)->nullable();
            $table->decimal('volume_m3', 15, 3)->nullable();
            $table->unsignedSmallInteger('sample_count')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('location_id');
        });

        Schema::create('qc_concrete_tests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sample_id')->constrained('qc_concrete_samples')->cascadeOnDelete();
            $table->unsignedSmallInteger('age_days'); // 7 / 14 / 28
            $table->decimal('strength_mpa', 8, 2); // fc' silinder (SNI 1974:2011), MPa
            $table->string('lab', 120)->nullable();
            $table->date('tested_at')->nullable();
            // COMPUTED vs the grade's age-adjusted target — never from the request.
            $table->boolean('pass')->default(false);
            $table->timestamps();

            $table->index('sample_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_concrete_tests');
        Schema::dropIfExists('qc_concrete_samples');
    }
};
