<?php

namespace Modules\Core\Support;

/**
 * Reading GPS out of a photograph, and measuring how far it is from somewhere.
 *
 * EXIF GPS is stored as three rationals ("6/1", "17/1", "2141/100") plus a
 * hemisphere letter — degrees, minutes, seconds. Getting the conversion wrong
 * is quiet: a southern-hemisphere photo whose sign is dropped lands in the
 * northern one, which for Indonesia means the Gulf of Thailand rather than
 * Jakarta, and the only symptom is a distance that looks implausible.
 */
class Geotag
{
    /** Mean Earth radius in metres (IUGG). */
    private const EARTH_RADIUS_M = 6_371_008.8;

    /**
     * GPS and capture time from an image's EXIF, or null when it has none.
     *
     * Most photographs arriving here will have none: WhatsApp, Telegram and
     * every social platform strip EXIF on upload, and Android's own share sheet
     * often does too. That is expected, not an error — it is exactly why the
     * device-reported position exists as a fallback.
     *
     * @return array{latitude: float, longitude: float, taken_at: ?string}|null
     */
    public static function fromExif(string $binary): ?array
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        // exif_read_data() emits warnings on anything it dislikes, and a
        // malformed photo is an ordinary event here, not a failure.
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($binary), 'EXIF,GPS', true);

        if ($exif === false || ! isset($exif['GPS'])) {
            return null;
        }

        $gps = $exif['GPS'];

        $latitude = self::coordinate($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? null, false);
        $longitude = self::coordinate($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? null, true);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'taken_at' => self::takenAt($exif),
        ];
    }

    /**
     * Degrees/minutes/seconds as EXIF rationals, into a signed decimal degree.
     *
     * $dms and $ref are `mixed` on purpose. They come from exif_read_data() on
     * a file an uploader chose, and a camera — or something pretending to be
     * one — can put anything in those tags: a bare string where an array is
     * expected, an array of arrays, an integer. A typed parameter would turn
     * each of those into a TypeError, which is not a LogicException, so it
     * would leave the controller as an HTTP 500 on an ordinary bad photo. A
     * photo whose GPS cannot be read is not an error; it is a photo with no
     * position, which this whole class is built to tolerate.
     *
     * $isLongitude is passed rather than inferred from the hemisphere letter,
     * for two reasons. Inferring it bounded a longitude with no GPSLongitudeRef
     * at ±90, which discarded every Indonesian fix (all east of 95°E) for the
     * wrong reason. And the hemisphere letter has a job of its own: it carries
     * the SIGN.
     *
     * Which is why a missing or unreadable ref is refused rather than assumed.
     * Guessing "north" for a latitude with no ref puts a Jakarta photo in the
     * Gulf of Thailand — the exact failure this class opens by warning about —
     * and the only symptom is a site distance that looks a bit large. Refusing
     * loses the position of a photo whose camera wrote a malformed tag, which
     * costs a fallback to the device position; guessing loses the truth.
     */
    public static function coordinate(mixed $dms, mixed $ref, bool $isLongitude = false): ?float
    {
        if (! is_array($dms) || count($dms) < 3) {
            return null;
        }

        $ref = is_string($ref) ? strtoupper(trim($ref)) : '';
        $hemispheres = $isLongitude ? ['E', 'W'] : ['N', 'S'];

        if (! in_array($ref, $hemispheres, true)) {
            return null;
        }

        $degrees = self::rational($dms[0]);
        $minutes = self::rational($dms[1]);
        $seconds = self::rational($dms[2]);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + $minutes / 60 + $seconds / 3600;

        // S and W are negative. Dropping this puts a Jakarta photo in the Gulf
        // of Thailand, and nothing downstream would say so.
        if ($ref === 'S' || $ref === 'W') {
            $decimal = -$decimal;
        }

        return self::plausible($decimal, $isLongitude) ? round($decimal, 7) : null;
    }

    /** Metres between two points, on a sphere. Accurate to ~0.5% — far better than any phone fix. */
    public static function distanceMetres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lon2 - $lon1);

        $a = sin($deltaPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function isValidLatitude(int|float|string|null $value): bool
    {
        return $value !== null && is_numeric($value) && abs((float) $value) <= 90;
    }

    public static function isValidLongitude(int|float|string|null $value): bool
    {
        return $value !== null && is_numeric($value) && abs((float) $value) <= 180;
    }

    private static function rational(mixed $value): ?float
    {
        if (is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (! is_string($value) || ! str_contains($value, '/')) {
            return null;
        }

        [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '1');

        if (! is_numeric($numerator) || ! is_numeric($denominator) || (float) $denominator === 0.0) {
            return null;
        }

        return (float) $numerator / (float) $denominator;
    }

    private static function plausible(float $decimal, bool $isLongitude): bool
    {
        return abs($decimal) <= ($isLongitude ? 180 : 90);
    }

    /**
     * The moment the shutter fired. EXIF writes "Y:m:d H:i:s" with colons in
     * the date, which no standard parser accepts as-is.
     */
    private static function takenAt(array $exif): ?string
    {
        foreach ([['EXIF', 'DateTimeOriginal'], ['IFD0', 'DateTime'], ['EXIF', 'DateTimeDigitized']] as [$section, $key]) {
            $raw = $exif[$section][$key] ?? null;

            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $normalised = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', trim($raw)) ?? '';
            $timestamp = strtotime($normalised);

            if ($timestamp !== false && $timestamp > 0) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        return null;
    }
}
