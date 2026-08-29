<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4 — prc_vendors.vendor_type enum supplier|subcontractor|mandor|rental.
 *
 * Filled FROM is_subcontractor: true => subcontractor, false => supplier —
 * a restatement of a fact the row already carries, not a new accounting fact,
 * which is what makes this backfill legitimate under the forward-only rule.
 *
 * is_subcontractor is KEPT, deliberately: it has 18 readers (13 PHP usages +
 * 5 SPA files) that all stay correct, and the Vendor model keeps the two
 * columns in step from now on (see Vendor::booted). It is DEPRECATED for new
 * code — read vendor_type instead; the boolean cannot see a mandor or a
 * rental vendor.
 *
 * The backfill is guarded so re-running up() (tests do; a re-deploy might)
 * only promotes rows still at the shipped default 'supplier' whose boolean
 * says subcontractor. A row somebody has already typed as mandor/rental can
 * never be clobbered back: the boolean cannot express those types, so it has
 * no authority over them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prc_vendors', 'vendor_type')) {
            Schema::table('prc_vendors', function (Blueprint $table): void {
                $table->string('vendor_type', 20)->default('supplier')->after('is_subcontractor');
                $table->index('vendor_type');
            });
        }

        DB::table('prc_vendors')
            ->where('is_subcontractor', true)
            ->where('vendor_type', 'supplier')
            ->update(['vendor_type' => 'subcontractor']);
    }

    public function down(): void
    {
        Schema::table('prc_vendors', function (Blueprint $table): void {
            $table->dropIndex(['vendor_type']);
            $table->dropColumn('vendor_type');
        });
    }
};
