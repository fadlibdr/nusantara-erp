<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Modules\Core\Models\Attachment;
use Modules\Core\Support\AttachableDocuments;
use Modules\Core\Support\Geotag;
use Modules\Projects\Models\Project;

/**
 * Files attached to documents: scans of a faktur pajak, a vendor's invoice PDF,
 * site photographs on a daily report.
 *
 * The file arrives one of two ways, and both land on the same checks below:
 * as base64 inside the ordinary JSON body (api.js authenticates on a header
 * and serialises every request as JSON — fine for the 5 MB class), or as a
 * multipart POST to api/core/attachments/upload for the 25 MB class, which
 * base64-in-JSON arithmetically cannot carry — see MAX_BYTES/SIZE_LIMITS.
 *
 * Four rules, and every one of them exists because the alternative is a way to
 * put executable or misleading content into somebody else's browser:
 *
 *  1. THE STORED NAME IS GENERATED. Nothing the uploader typed reaches the
 *     filesystem. "../../.env" and "shell.php" are display labels.
 *  2. THE EXTENSION IS ALLOWLISTED, and .svg is not on it — SVG is a document
 *     format that executes script, and it is the one image type that is also an
 *     XSS vector.
 *  3. THE CONTENT IS SNIFFED, and must agree with the extension. A client-sent
 *     MIME type is a claim, not evidence; a .pdf that is really HTML is how a
 *     file upload becomes stored XSS.
 *  4. FILES ARE NOT WEB-ROOTED. They live under storage/app, which nginx does
 *     not serve, so the only way to read one is through an endpoint that
 *     checks permissions.
 */
class AttachmentService
{
    /**
     * 5 MB raw — the default cap, per extension overrides in SIZE_LIMITS.
     *
     * The arithmetic that decides which transport can carry what: base64
     * inflates by a third, so 5 MB raw is ~6.7 MB on the JSON route — inside
     * the 7 000 000-char request rule, PHP's stock 8M post_max_size, and the
     * deployed 26M (deploy/docker/php.ini). 25 MB raw would be ~33.4 MB of
     * base64, over even the deployed 26M — which is why the 25 MB class can
     * ONLY arrive on the multipart route, where the file travels raw:
     * 25 MB + multipart framing fits 26M, and equals upload_max_filesize
     * (25M). See docs/DEPLOYMENT.md for the deployed numbers.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Per-extension caps that override MAX_BYTES. Engineering drawings and
     * MS Project schedules genuinely run this large (roadmap asumsi #4);
     * everything else stays at the 5 MB default.
     */
    public const SIZE_LIMITS = [
        'dwg' => 25 * 1024 * 1024,
        'dxf' => 25 * 1024 * 1024,
        'mpp' => 25 * 1024 * 1024,
    ];

    /**
     * Extension => the MIME types the content is allowed to actually be.
     *
     * Deliberately absent: svg (executes script), html/htm, php/phtml, js, and
     * every archive format. An archive cannot be inspected here, so allowing one
     * would mean storing arbitrary unexamined content behind a friendly name.
     * (pptx, like docx/xlsx, is a zip — but one whose structure finfo verifies
     * before answering the presentationml MIME; a plain zip stays refused.)
     *
     * The engineering types (P0-D) are pinned to what THIS PHP build's finfo
     * actually answers for real minimal binaries of each format — generated
     * and re-verifiable by tests/fixtures/attachments/generate.php, never
     * transcribed from a MIME registry. PHP's fileinfo is a bundled, patched
     * libmagic (5.43 here) that disagrees with the /usr/bin/file CLI: an
     * ASCII DXF answers text/plain (the CLI says image/vnd.dxf), and
     * vnd.ms-project does not exist in its database at all, so an .mpp can
     * never sniff as one. What the pins still guarantee, honestly:
     *
     *  - dwg must genuinely open with the AC10xx AutoCAD signature;
     *  - dxf can only be TEXT (text/plain is its ceiling on this build — a
     *    renamed HTML, PNG or PDF still sniffs as itself and is refused;
     *    binary DXF sniffs application/octet-stream and is deliberately NOT
     *    accepted — export ASCII DXF instead);
     *  - ppt/mpp must be a real, parseable OLE compound file: finfo answers
     *    vnd.ms-powerpoint / vnd.ms-office when the summary stream names the
     *    producing application, x-ole-storage for a genuine container
     *    without one. Those container MIMEs are granted to these two OLE
     *    extensions ONLY — never widened to anything else;
     *  - xml must sniff text/xml AND survive the HTML masquerade sniff below
     *    (finfo answers text/xml for HTML hiding behind an XML prolog).
     */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'heic' => ['image/heic', 'image/heif'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'csv' => ['text/csv', 'text/plain'],
        'txt' => ['text/plain'],
        'dwg' => ['image/vnd.dwg'],
        'dxf' => ['text/plain'],
        'mpp' => ['application/vnd.ms-office', 'application/x-ole-storage'],
        'xml' => ['text/xml'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
    ];

    public function resolveDocument(string $slug, int $id): Model
    {
        $class = AttachableDocuments::classFor($slug);

        if ($class === null) {
            throw new LogicException("Jenis dokumen \"{$slug}\" tidak dapat dilampiri berkas.");
        }

        /** @var Model|null $document */
        $document = $class::query()->find($id);

        if ($document === null) {
            throw new LogicException(AttachableDocuments::labelFor($slug).' tidak ditemukan.');
        }

        return $document;
    }

    /**
     * @param  string  $content  base64, with or without a data: URI prefix
     * @param  array{latitude?: mixed, longitude?: mixed, accuracy_m?: mixed}  $devicePosition
     *                                                                                          where the phone said it was, used only when the
     *                                                                                          image carries no EXIF GPS of its own
     */
    public function store(
        Model $document,
        string $filename,
        string $content,
        ?string $caption = null,
        ?int $userId = null,
        array $devicePosition = [],
    ): Attachment {
        return $this->storeBinary($document, $filename, $this->decode($content), $caption, $userId, $devicePosition);
    }

    /**
     * The one path every transport lands on. The JSON route decodes its base64
     * and the multipart route reads its temp file, but extension, size, sniff
     * and masquerade checks all happen HERE — two routes, one policy, nothing
     * to drift apart.
     */
    public function storeBinary(
        Model $document,
        string $filename,
        string $binary,
        ?string $caption = null,
        ?int $userId = null,
        array $devicePosition = [],
    ): Attachment {
        $extension = $this->extensionOf($filename);

        $this->assertAllowedExtension($extension);
        $this->assertWithinSizeLimit($extension, $binary);
        $this->assertNotHtmlPosingAsXml($extension, $binary);

        $mime = $this->sniff($binary);

        $this->assertContentMatches($extension, $mime);

        $geo = $this->geotag($binary, $mime, $devicePosition);

        $path = sprintf(
            'attachments/%s/%s.%s',
            $document->getKey() % 100,        // spread across directories
            (string) Str::ulid(),
            $extension,
        );

        $this->write($path, $binary);

        return Attachment::query()->create([
            'attachable_type' => $document::class,
            'attachable_id' => $document->getKey(),
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->safeDisplayName($filename),
            'mime' => $mime,
            'extension' => $extension,
            'size_bytes' => strlen($binary),
            'sha256' => hash('sha256', $binary),
            'caption' => $caption,
            'uploaded_by' => $userId,
        ] + $geo);
    }

    /**
     * Where the photo says it was taken.
     *
     * EXIF wins over the device position whenever it is present, because it was
     * written by the camera at the moment of the shot; the device position is
     * where the phone was when somebody pressed upload, which may be hours and
     * kilometres later. Both are recorded with their source rather than merged
     * into one unlabelled pair of numbers — a reader has to be able to tell
     * which question was answered.
     *
     * @return array<string, mixed>
     */
    private function geotag(string $binary, string $mime, array $devicePosition): array
    {
        if ($mime === 'image/jpeg') {
            $exif = Geotag::fromExif($binary);

            if ($exif !== null) {
                return [
                    'latitude' => $exif['latitude'],
                    'longitude' => $exif['longitude'],
                    'taken_at' => $exif['taken_at'],
                    'geo_source' => 'exif',
                    'accuracy_m' => null,
                ];
            }
        }

        $latitude = $devicePosition['latitude'] ?? null;
        $longitude = $devicePosition['longitude'] ?? null;

        if (! Geotag::isValidLatitude($latitude) || ! Geotag::isValidLongitude($longitude)) {
            return [];
        }

        $accuracy = $devicePosition['accuracy_m'] ?? null;

        return [
            'latitude' => round((float) $latitude, 7),
            'longitude' => round((float) $longitude, 7),
            // Clamped: a browser that reports a 40 000 km accuracy radius is
            // reporting that it does not know, and an unsigned column would
            // reject it outright rather than store the uselessness honestly.
            'accuracy_m' => is_numeric($accuracy) ? (int) min(100_000, max(0, (float) $accuracy)) : null,
            'taken_at' => null,
            'geo_source' => 'device',
        ];
    }

    /**
     * Each attachment with how far it was taken from its project site, when
     * both ends are known.
     *
     * This is the number the feature exists for. A progress photograph shot in
     * the office car park is indistinguishable from one shot on the eighth
     * floor until something measures the distance.
     */
    public function withSiteDistance(Model $document, $attachments)
    {
        $site = $this->siteCoordinates($document);

        return $attachments->map(function (Attachment $attachment) use ($site): Attachment {
            $attachment->setAttribute('distance_from_site_m', null);

            if ($site !== null && $attachment->latitude !== null && $attachment->longitude !== null) {
                $attachment->setAttribute('distance_from_site_m', (int) round(Geotag::distanceMetres(
                    (float) $attachment->latitude,
                    (float) $attachment->longitude,
                    $site['latitude'],
                    $site['longitude'],
                )));
            }

            return $attachment;
        });
    }

    /**
     * The site a document belongs to, when it has one.
     *
     * Only documents that genuinely sit at a place are asked: a daily report is
     * written on site, a vendor bill is not, and inventing a location for the
     * latter would make the distance meaningless where it is meaningful.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private function siteCoordinates(Model $document): ?array
    {
        $project = match (true) {
            $document instanceof Project => $document,
            isset($document->project_id) => Project::query()->find($document->project_id),
            default => null,
        };

        if ($project === null
            || ! Geotag::isValidLatitude($project->latitude)
            || ! Geotag::isValidLongitude($project->longitude)) {
            return null;
        }

        return ['latitude' => (float) $project->latitude, 'longitude' => (float) $project->longitude];
    }

    /**
     * Write the bytes, and prove they arrived.
     *
     * The local disk is configured 'throw' => false, so a failed write returns
     * false with no exception and no log line — and the row would be created
     * anyway, giving the uploader a green tick over a statutory record that
     * does not exist. The size is re-read as well as the return value checked,
     * because a full disk does NOT make put() fail: file_put_contents returns a
     * short count, so the write "succeeds" and stores a truncated file.
     */
    private function write(string $path, string $binary): void
    {
        $disk = Storage::disk('local');

        if ($disk->put($path, $binary) === false) {
            throw new LogicException('Berkas gagal disimpan ke penyimpanan server. Hubungi administrator.');
        }

        if ($disk->size($path) !== strlen($binary)) {
            $disk->delete($path);

            throw new LogicException('Berkas tersimpan tidak utuh — penyimpanan server mungkin penuh.');
        }
    }

    public function delete(Attachment $attachment): void
    {
        $disk = Storage::disk($attachment->disk);

        if ($disk->exists($attachment->path)) {
            $disk->delete($attachment->path);
        }

        $attachment->delete();
    }

    public function forDocument(Model $document)
    {
        return Attachment::query()
            ->with('uploader:id,name')
            ->where('attachable_type', $document::class)
            ->where('attachable_id', $document->getKey())
            ->orderByDesc('id')
            ->get();
    }

    public function contents(Attachment $attachment): string
    {
        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            throw new LogicException("Berkas {$attachment->original_name} tidak ditemukan di penyimpanan.");
        }

        return (string) $disk->get($attachment->path);
    }

    // ----------------------------------------------------------------- guards

    private function decode(string $content): string
    {
        // Accept a data: URI, because that is what FileReader.readAsDataURL
        // produces and asking the client to strip it invites it being done
        // differently in two places.
        if (str_contains($content, ',') && str_starts_with($content, 'data:')) {
            $content = substr($content, strpos($content, ',') + 1);
        }

        $binary = base64_decode(trim($content), true);

        if ($binary === false || $binary === '') {
            throw new LogicException('Isi berkas tidak dapat dibaca (base64 tidak valid).');
        }

        // Size is NOT checked here: the limit is per extension, which decode()
        // does not know — storeBinary() enforces it for both transports. Over
        // HTTP an oversized base64 body never even reaches this method: the
        // controller's 7 000 000-char rule refuses it first.
        return $binary;
    }

    /**
     * The last dot-segment of the basename, lowercased. basename() first, so a
     * path is reduced to its final component before anything else looks at it.
     */
    private function extensionOf(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === '') {
            throw new LogicException('Nama berkas tidak memiliki ekstensi, jadi jenisnya tidak dapat dipastikan.');
        }

        return $extension;
    }

    /** What the bytes actually are, not what the client said they are. */
    private function sniff(string $binary): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) ($finfo->buffer($binary) ?: 'application/octet-stream');
    }

    private function assertAllowedExtension(string $extension): void
    {
        if (! array_key_exists($extension, self::ALLOWED)) {
            throw new LogicException(sprintf(
                'Jenis berkas ".%s" tidak diizinkan. Yang diterima: %s.',
                $extension,
                implode(', ', array_keys(self::ALLOWED)),
            ));
        }
    }

    private function assertWithinSizeLimit(string $extension, string $binary): void
    {
        $limit = self::SIZE_LIMITS[$extension] ?? self::MAX_BYTES;

        if (strlen($binary) > $limit) {
            throw new LogicException(sprintf(
                'Berkas berukuran %s, melebihi batas %s untuk berkas .%s.',
                $this->humanSize(strlen($binary)),
                $this->humanSize($limit),
                $extension,
            ));
        }
    }

    /**
     * .xml only: refuse content that reads as an HTML document, naming what it
     * looked like. finfo answers text/xml for HTML hiding behind an XML prolog,
     * so the MIME check alone would wave exactly the dangerous case through —
     * and an HTML document behind a data-file extension is the shape of a
     * stored-XSS payload, whatever the download headers say.
     */
    private function assertNotHtmlPosingAsXml(string $extension, string $binary): void
    {
        if ($extension !== 'xml') {
            return;
        }

        $marker = $this->htmlMarkerIn($binary);

        if ($marker !== null) {
            throw new LogicException(sprintf(
                'Berkas .xml ini terlihat seperti dokumen HTML (diawali %s), bukan data XML. '
                .'Berkas HTML tidak diizinkan.',
                $marker,
            ));
        }
    }

    /**
     * The first HTML-family construct at the top of the content, if any: a
     * `<!DOCTYPE html>` or an opening tag from the HTML vocabulary, looked for
     * past any BOM, whitespace, XML prologs / processing instructions and
     * comments — the wrappers an attacker would use to keep finfo saying
     * text/xml while a browser still sees HTML.
     */
    private function htmlMarkerIn(string $binary): ?string
    {
        $head = substr($binary, 0, 4096);

        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }

        while (true) {
            $head = ltrim($head);

            if (str_starts_with($head, '<?')) {
                if (($end = strpos($head, '?>')) === false) {
                    return null;
                }
                $head = substr($head, $end + 2);

                continue;
            }

            if (str_starts_with($head, '<!--')) {
                if (($end = strpos($head, '-->')) === false) {
                    return null;
                }
                $head = substr($head, $end + 3);

                continue;
            }

            break;
        }

        if (preg_match('/^<!doctype\s+html/i', $head) === 1) {
            return 'deklarasi <!DOCTYPE html>';
        }

        $tags = 'html|head|body|script|iframe|frameset|object|embed|meta|link|style|base|form|title';

        // Prefiks namespace ikut ditangkap: '<x:html xmlns:x="...xhtml">'
        // adalah dokumen HTML yang sama persis di mata peramban, hanya
        // berganti baju XML — tanpa cabang ini ia lolos sniff (finfo
        // menjawab text/xml) dan tersimpan sebagai .xml.
        if (preg_match('/^<(?:[a-z][a-z0-9_.-]*:)?('.$tags.')[\s\/>]/i', $head, $m) === 1) {
            return 'tag <'.strtolower($m[1]).'>';
        }

        // Namespace XHTML pada tag pembuka apa pun adalah pengakuan diri.
        if (preg_match('/^<[^>]{0,512}xmlns(?::[a-z0-9_.-]+)?\s*=\s*["\x27]http:\/\/www\.w3\.org\/1999\/xhtml["\x27]/i', $head) === 1) {
            return 'namespace XHTML (www.w3.org/1999/xhtml)';
        }

        return null;
    }

    private function assertContentMatches(string $extension, string $mime): void
    {
        if (! in_array($mime, self::ALLOWED[$extension], true)) {
            throw new LogicException(sprintf(
                'Isi berkas terbaca sebagai %s, tidak cocok dengan ekstensi ".%s". '
                .'Berkas yang isinya berbeda dari namanya ditolak.',
                $mime,
                $extension,
            ));
        }
    }

    /**
     * The label shown in the UI. Stripped of anything that could make it read as
     * a path or break out of a filename when a browser saves it, and never used
     * to decide where the bytes go.
     */
    private function safeDisplayName(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return mb_substr($name === '' ? 'lampiran' : $name, 0, 255);
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1).' MB'
            : round($bytes / 1024).' kB';
    }
}
