<?php

namespace Tests\Feature\Core;

use Modules\Core\Services\AttachmentService;
use Tests\ErpTestCase;

/**
 * The attachment policy exists twice: once in AttachmentService, where it is
 * enforced, and once in the SPA, where it decides what the file picker offers
 * and what the size hint promises. There is no build step to share it, so this
 * reads the JavaScript and fails when the two drift.
 *
 * Drift is not cosmetic. An extension missing from the accept list is a type
 * the server takes but the picker greys out on every device; a stale size
 * constant either promises what the API will refuse or refuses client-side
 * what the API would take; and a transport threshold above the JSON route's
 * ceiling would base64 a file straight into a 413.
 */
class AttachmentSpaPolicyTest extends ErpTestCase
{
    public function test_the_file_picker_offers_exactly_what_the_service_allows(): void
    {
        $source = $this->spa('views/attachments.js');

        $this->assertSame(
            1,
            preg_match_all("/accept: '([^']+)'/", $source, $matches),
            'Expected exactly one accept list in attachments.js; the picker input has moved or multiplied.',
        );

        $js = explode(',', $matches[1][0]);
        sort($js);

        $php = array_map(static fn (string $extension) => '.'.$extension, array_keys(AttachmentService::ALLOWED));
        sort($php);

        $this->assertSame(
            $php,
            $js,
            "The attachments.js accept list has drifted from AttachmentService::ALLOWED.\n"
            .'Only in PHP: '.implode(', ', array_diff($php, $js))."\n"
            .'Only in JS:  '.implode(', ', array_diff($js, $php)),
        );
    }

    public function test_the_javascript_size_limits_mirror_the_service(): void
    {
        $source = $this->spa('views/attachments.js');

        $this->assertSame(
            1,
            preg_match('/const MAX_BYTES = (\d+) \* 1024 \* 1024;/', $source, $matches),
            'MAX_BYTES could not be found in attachments.js in the N * 1024 * 1024 form this test reads.',
        );
        $this->assertSame(AttachmentService::MAX_BYTES, (int) $matches[1] * 1024 * 1024);

        $this->assertSame(
            1,
            preg_match('/const SIZE_LIMITS = \{(.*?)\};/s', $source, $matches),
            'SIZE_LIMITS could not be found in attachments.js; the per-extension caps are no longer mirrored.',
        );

        preg_match_all('/(\w+): (\d+) \* 1024 \* 1024/', $matches[1], $entries, PREG_SET_ORDER);

        $js = [];
        foreach ($entries as $entry) {
            $js[$entry[1]] = (int) $entry[2] * 1024 * 1024;
        }
        ksort($js);

        $php = AttachmentService::SIZE_LIMITS;
        ksort($php);

        $this->assertSame(
            $php,
            $js,
            'The attachments.js SIZE_LIMITS have drifted from AttachmentService::SIZE_LIMITS.',
        );
    }

    /**
     * uploadFile() sends anything over this threshold as multipart. It must
     * equal the service's MAX_BYTES: lower wastes the JSON route every
     * deployment already accepts, higher base64s a file past the JSON route's
     * 7 000 000-char ceiling — the arithmetic at AttachmentService::MAX_BYTES.
     */
    public function test_the_transport_threshold_in_api_js_is_the_json_route_ceiling(): void
    {
        $source = $this->spa('api.js');

        $this->assertSame(
            1,
            preg_match('/const JSON_UPLOAD_MAX_BYTES = (\d+) \* 1024 \* 1024;/', $source, $matches),
            'JSON_UPLOAD_MAX_BYTES could not be found in api.js; uploadFile() no longer picks its transport where this test can see it.',
        );
        $this->assertSame(AttachmentService::MAX_BYTES, (int) $matches[1] * 1024 * 1024);
    }

    private function spa(string $file): string
    {
        return (string) file_get_contents(public_path('app/js/'.$file));
    }
}
