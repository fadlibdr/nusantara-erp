<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where and when a photo was taken.
 *
 * The point of geotagging a site photograph is not the map pin — it is the
 * question "was this taken at the site?". A progress photo shot in the office
 * car park looks exactly like one shot on the eighth floor, and the coordinates
 * are the only thing that can tell them apart.
 *
 * geo_source records WHICH claim this is, because the two available sources are
 * not equally good and pretending otherwise would be worse than storing
 * nothing:
 *
 *   exif    read out of the image the camera wrote. Contemporaneous with the
 *           shot, and survives being sent through anything that does not strip
 *           metadata — but it is also just bytes in a file, and editable.
 *   device  the browser's Geolocation API at the moment of upload. Says where
 *           the PHONE was when the file was sent, which is not the same thing
 *           as where the picture was taken, and is trivially spoofable on a
 *           rooted device.
 *
 * Neither is evidence in the legal sense. Both are useful for the thing they
 * are actually for: noticing that today's progress photo was taken 8 km away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_attachments', function (Blueprint $table): void {
            // 7 decimal places ≈ 1 cm, far finer than any phone GPS.
            $table->decimal('latitude', 10, 7)->nullable()->after('caption');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('accuracy_m')->nullable()->after('longitude');
            $table->timestamp('taken_at')->nullable()->after('accuracy_m');
            $table->string('geo_source', 10)->nullable()->after('taken_at'); // exif|device
        });
    }

    public function down(): void
    {
        Schema::table('core_attachments', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude', 'accuracy_m', 'taken_at', 'geo_source']);
        });
    }
};
