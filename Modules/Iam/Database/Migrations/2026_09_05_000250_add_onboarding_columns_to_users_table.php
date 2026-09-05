<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Onboarding in the app — the owner's request of 5 Sep 2026: "on boarding is
 * not working, make it pop-up every user is logged in and create a button to
 * skip the onboarding process also make the choice is remembered".
 *
 * "Not working" was literal: the twelve per-role guides existed only as
 * docs/ONBOARDING/<role>.md, handed over by hand (README §"Cara
 * menyerahkan"). Nothing in the SPA ever showed them. The choice has to be
 * remembered ON THE USER, not in a browser: the field tablet is shared, and a
 * localStorage flag would make the guide nag again on every other device and
 * vanish for the next person who logs in on the same one.
 *
 * Two columns, both nullable, both additive. NULL means "has never decided" —
 * every existing account, and every account created from now on, gets the
 * guide at its next login until it presses Lewati or Selesai. The status is a
 * short string rather than a boolean so "skipped" and "completed" stay
 * distinguishable (an administrator can tell who never read theirs), and so a
 * user can reset it to NULL from the account menu to see it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('onboarding_status', 12)->nullable()->after('is_active');
            $table->timestamp('onboarding_seen_at')->nullable()->after('onboarding_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['onboarding_status', 'onboarding_seen_at']);
        });
    }
};
