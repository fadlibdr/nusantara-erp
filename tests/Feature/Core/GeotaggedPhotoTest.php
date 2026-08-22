<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Storage;
use Modules\Core\Services\AttachmentService;
use Modules\Core\Support\Geotag;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * Geotagged site photographs.
 *
 * The feature is not "show a map pin". It is "was this taken at the site?" — a
 * progress photograph shot in the office car park is indistinguishable from one
 * shot on the eighth floor until something measures the distance, and a site
 * supervisor who has worked out that nobody checks will stop walking upstairs.
 *
 * The two sources of position are not equally good and the tests below keep
 * them apart, because a reader has to be able to tell which question was
 * answered: EXIF says where the CAMERA was when the shutter fired, the device
 * position says where the PHONE was when somebody pressed upload.
 */
class GeotaggedPhotoTest extends ErpTestCase
{
    private AttachmentService $attachments;

    private Project $project;

    private DailyReport $report;

    /** Monas, Jakarta. */
    private const SITE_LAT = -6.1753924;

    private const SITE_LNG = 106.8271528;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->attachments = app(AttachmentService::class);

        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-900',
            'name' => 'Proyek Uji Geotag',
            'type' => 'construction',
            'location' => 'Jakarta Pusat',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'status' => 'active',
        ]);

        $this->report = DailyReport::query()->create([
            'code' => 'DRP/2026/07/9001',
            'project_id' => $this->project->id,
            'report_date' => '2026-07-20',
            'manpower_count' => 12,
            'activities' => 'Pengecoran lantai 5',
        ]);
    }

    /**
     * A real JPEG carrying GPS EXIF, written here rather than checked in as a
     * fixture so the bytes and the expected coordinates cannot drift apart.
     */
    private function jpegWithGps(float $lat, float $lng): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        return base64_encode($this->insertExifGps($jpeg, $lat, $lng));
    }

    private function plainJpeg(): string
    {
        return $this->stripExif($this->jpegWithGps(0, 0));
    }

    public function test_a_photo_with_gps_exif_is_positioned_from_the_file_itself(): void
    {
        $attachment = $this->attachments->store(
            $this->report,
            'cor-lantai5.jpg',
            $this->jpegWithGps(self::SITE_LAT, self::SITE_LNG),
        );

        $this->assertSame('exif', $attachment->geo_source);
        $this->assertEqualsWithDelta(self::SITE_LAT, (float) $attachment->latitude, 0.0002);
        $this->assertEqualsWithDelta(self::SITE_LNG, (float) $attachment->longitude, 0.0002);
    }

    /**
     * The southern and western hemispheres are negative. Dropping the sign puts
     * a Jakarta photo in the Gulf of Thailand, and the only symptom is a
     * distance that looks odd.
     */
    public function test_a_southern_hemisphere_photo_stays_in_the_southern_hemisphere(): void
    {
        $attachment = $this->attachments->store($this->report, 'site.jpg', $this->jpegWithGps(-6.9, 107.6));

        $this->assertLessThan(0, (float) $attachment->latitude, 'S must be negative');
        $this->assertGreaterThan(0, (float) $attachment->longitude, 'E must be positive');
    }

    /**
     * Most photographs arrive stripped — WhatsApp, Telegram and every social
     * platform remove EXIF. That is the ordinary case, and it is why the phone's
     * own position exists as a fallback.
     */
    public function test_a_stripped_photo_falls_back_to_the_device_position(): void
    {
        $attachment = $this->attachments->store(
            $this->report,
            'foto.jpg',
            $this->plainJpeg(),
            null,
            null,
            ['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG, 'accuracy_m' => 12],
        );

        $this->assertSame('device', $attachment->geo_source);
        $this->assertSame(12, $attachment->accuracy_m);
    }

    /** EXIF is contemporaneous with the shot; the device position is not. */
    public function test_exif_wins_over_the_device_position(): void
    {
        $attachment = $this->attachments->store(
            $this->report,
            'foto.jpg',
            $this->jpegWithGps(self::SITE_LAT, self::SITE_LNG),
            null,
            null,
            ['latitude' => 0.0, 'longitude' => 0.0, 'accuracy_m' => 5],
        );

        $this->assertSame('exif', $attachment->geo_source);
        $this->assertEqualsWithDelta(self::SITE_LAT, (float) $attachment->latitude, 0.0002);
    }

    public function test_a_photo_with_no_position_at_all_is_stored_without_one(): void
    {
        $attachment = $this->attachments->store($this->report, 'foto.jpg', $this->plainJpeg());

        $this->assertNull($attachment->geo_source);
        $this->assertNull($attachment->latitude);
        $this->assertFalse($attachment->hasPosition());
    }

    public function test_an_impossible_device_position_is_ignored_rather_than_stored(): void
    {
        $attachment = $this->attachments->store(
            $this->report,
            'foto.jpg',
            $this->plainJpeg(),
            null,
            null,
            ['latitude' => 999, 'longitude' => 999],
        );

        $this->assertNull($attachment->geo_source);
        $this->assertNull($attachment->latitude);
    }

    // ---------------------------------------------------------- the point

    public function test_a_photo_taken_at_the_site_reports_a_short_distance(): void
    {
        $this->attachments->store($this->report, 'di-lokasi.jpg', $this->jpegWithGps(self::SITE_LAT, self::SITE_LNG));

        $listed = $this->attachments->withSiteDistance($this->report, $this->attachments->forDocument($this->report));

        $this->assertLessThan(50, $listed->first()->distance_from_site_m);
    }

    /**
     * Sudirman is about 4 km from Monas. This is the number a project manager
     * needs to see, and the whole reason for the columns.
     */
    public function test_a_photo_taken_elsewhere_reports_the_distance(): void
    {
        $this->attachments->store($this->report, 'jauh.jpg', $this->jpegWithGps(-6.2146200, 106.8206600));

        $distance = $this->attachments
            ->withSiteDistance($this->report, $this->attachments->forDocument($this->report))
            ->first()->distance_from_site_m;

        $this->assertGreaterThan(3_000, $distance);
        $this->assertLessThan(6_000, $distance);
    }

    public function test_a_photo_without_a_position_has_no_distance_rather_than_a_wrong_one(): void
    {
        $this->attachments->store($this->report, 'tanpa-gps.jpg', $this->plainJpeg());

        $listed = $this->attachments->withSiteDistance($this->report, $this->attachments->forDocument($this->report));

        $this->assertNull($listed->first()->distance_from_site_m);
    }

    /**
     * A document that does not sit at a place must not be given a distance.
     * Inventing one for a vendor bill would make the number meaningless where
     * it is meaningful.
     */
    public function test_a_project_without_coordinates_yields_no_distance(): void
    {
        $this->project->forceFill(['latitude' => null, 'longitude' => null])->save();

        $this->attachments->store($this->report, 'foto.jpg', $this->jpegWithGps(self::SITE_LAT, self::SITE_LNG));

        $listed = $this->attachments->withSiteDistance($this->report, $this->attachments->forDocument($this->report));

        $this->assertNull($listed->first()->distance_from_site_m);
    }

    // --------------------------------------------------------------- maths

    public function test_the_distance_between_two_known_points_is_right(): void
    {
        // Monas to Bandung Alun-Alun, about 120 km.
        $metres = Geotag::distanceMetres(self::SITE_LAT, self::SITE_LNG, -6.9218234, 107.6070804);

        $this->assertEqualsWithDelta(120_000, $metres, 5_000);
    }

    public function test_exif_rationals_convert_to_signed_degrees(): void
    {
        // 6° 10' 31.41" S  =>  -6.17539
        $this->assertEqualsWithDelta(
            -6.1753917,
            (float) Geotag::coordinate(['6/1', '10/1', '3141/100'], 'S'),
            0.0001,
        );

        $this->assertEqualsWithDelta(
            106.8271528,
            (float) Geotag::coordinate(['106/1', '49/1', '3775/100'], 'E', true),
            0.0005,
        );
    }

    /**
     * The axis decides the bound, so a longitude past 90° is accepted as a
     * longitude — every Indonesian site is east of 95°E, and inferring the axis
     * from the hemisphere letter discarded all of them whenever the letter was
     * missing.
     */
    public function test_the_axis_decides_the_bound_not_the_hemisphere_letter(): void
    {
        $this->assertEqualsWithDelta(
            106.8166667,
            (float) Geotag::coordinate(['106/1', '49/1', '0/1'], 'E', true),
            0.0005,
        );

        // The same magnitude is not a valid latitude.
        $this->assertNull(Geotag::coordinate(['106/1', '49/1', '0/1'], 'N', false));
    }

    /**
     * A coordinate with no readable hemisphere is not a coordinate.
     *
     * Assuming north for a latitude whose ref is missing puts a Jakarta photo
     * in the Gulf of Thailand, and the only symptom is a site distance that
     * looks slightly large. Refusing costs a fallback to the device position;
     * guessing costs the truth.
     */
    public function test_a_missing_or_wrong_hemisphere_is_refused_rather_than_assumed(): void
    {
        $this->assertNull(Geotag::coordinate(['6/1', '10/1', '31/1'], null, false), 'no ref');
        $this->assertNull(Geotag::coordinate(['6/1', '10/1', '31/1'], '', false), 'empty ref');
        $this->assertNull(Geotag::coordinate(['6/1', '10/1', '31/1'], 'E', false), 'longitude ref on a latitude');
        $this->assertNull(Geotag::coordinate(['106/1', '49/1', '0/1'], 'S', true), 'latitude ref on a longitude');

        // Lowercase and padded refs are ordinary, and are read.
        $this->assertLessThan(0, (float) Geotag::coordinate(['6/1', '10/1', '31/1'], ' s ', false));
    }

    /**
     * EXIF comes out of a file the uploader chose, so a tag can hold anything.
     * A TypeError here is not a LogicException, so it would leave the controller
     * as an HTTP 500 on an ordinary bad photo.
     */
    public function test_exif_tags_of_the_wrong_shape_degrade_instead_of_crashing(): void
    {
        foreach (['not-an-array', 42, null, true, [['6/1'], ['10/1'], ['31/1']], ['6/1']] as $dms) {
            $this->assertNull(Geotag::coordinate($dms, 'S'), 'malformed GPSLatitude must not throw');
        }

        $this->assertNull(Geotag::coordinate(['6/1', '10/1', '31/1'], ['S']), 'malformed ref must not throw');
    }

    public function test_malformed_exif_rationals_are_refused_rather_than_guessed(): void
    {
        $this->assertNull(Geotag::coordinate(['6/0', '10/1', '31/1'], 'S'));
        $this->assertNull(Geotag::coordinate(['6/1'], 'S'));
        $this->assertNull(Geotag::coordinate(null, 'S'));
    }

    // ------------------------------------------------------------- helpers

    /**
     * Splices a minimal APP1/EXIF segment carrying GPS into a JPEG.
     *
     * Hand-built rather than pulled from a library so the test depends on
     * nothing but the format itself.
     */
    private function insertExifGps(string $jpeg, float $lat, float $lng): string
    {
        $latRef = $lat < 0 ? 'S' : 'N';
        $lngRef = $lng < 0 ? 'W' : 'E';

        $tiff = $this->tiffWithGps(abs($lat), abs($lng), $latRef, $lngRef);
        $app1 = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        // After SOI (FF D8), before everything else.
        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    private function tiffWithGps(float $lat, float $lng, string $latRef, string $lngRef): string
    {
        // Big-endian TIFF header, IFD0 at offset 8.
        $header = "MM\x00\x2A".pack('N', 8);

        // IFD0: one entry (GPSInfoIFDPointer, tag 0x8825, LONG) -> GPS IFD.
        $gpsIfdOffset = 8 + 2 + 12 + 4;
        $ifd0 = pack('n', 1)
            .pack('nnN', 0x8825, 4, 1).pack('N', $gpsIfdOffset)
            .pack('N', 0);

        // GPS IFD: 4 entries; the rationals live after it.
        $entryCount = 4;
        $gpsIfdSize = 2 + ($entryCount * 12) + 4;
        $dataOffset = $gpsIfdOffset + $gpsIfdSize;

        $latRationals = $this->rationals($lat);
        $lngRationals = $this->rationals($lng);

        $gps = pack('n', $entryCount)
            // GPSLatitudeRef (ASCII, 2 bytes, inline)
            .pack('nnN', 0x0001, 2, 2).$latRef."\x00\x00\x00"
            // GPSLatitude (RATIONAL x3, at offset)
            .pack('nnN', 0x0002, 5, 3).pack('N', $dataOffset)
            .pack('nnN', 0x0003, 2, 2).$lngRef."\x00\x00\x00"
            .pack('nnN', 0x0004, 5, 3).pack('N', $dataOffset + 24)
            .pack('N', 0);

        return $header.$ifd0.$gps.$latRationals.$lngRationals;
    }

    /** Degrees, minutes, seconds as three big-endian RATIONALs. */
    private function rationals(float $decimal): string
    {
        $degrees = (int) floor($decimal);
        $minutesFloat = ($decimal - $degrees) * 60;
        $minutes = (int) floor($minutesFloat);
        $seconds = (int) round(($minutesFloat - $minutes) * 60 * 100);

        return pack('NN', $degrees, 1).pack('NN', $minutes, 1).pack('NN', $seconds, 100);
    }

    /** Re-encodes through GD, which writes no EXIF — what a stripping service does. */
    private function stripExif(string $base64Jpeg): string
    {
        $image = imagecreatefromstring(base64_decode($base64Jpeg));
        ob_start();
        imagejpeg($image);
        $stripped = (string) ob_get_clean();
        imagedestroy($image);

        return base64_encode($stripped);
    }
}
