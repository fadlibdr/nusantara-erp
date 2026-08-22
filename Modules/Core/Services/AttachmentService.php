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
 * The file arrives as base64 inside the ordinary JSON body, because api.js
 * authenticates on a header and serialises every request as JSON — a multipart
 * path would mean a second transport for one feature. The cost is the 33%
 * base64 overhead, which is why the raw cap is well under php-fpm's post limit.
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
    /** 5 MB raw. Base64 inflates it to ~6.7 MB, inside php-fpm's 8 MB post_max_size. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Extension => the MIME types the content is allowed to actually be.
     *
     * Deliberately absent: svg (executes script), html/htm, php/phtml, js, and
     * every archive format. An archive cannot be inspected here, so allowing one
     * would mean storing arbitrary unexamined content behind a friendly name.
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
        $binary = $this->decode($content);
        $extension = $this->extensionOf($filename);
        $mime = $this->sniff($binary);

        $this->assertAcceptable($extension, $mime);

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

        if (strlen($binary) > self::MAX_BYTES) {
            throw new LogicException(sprintf(
                'Berkas berukuran %s, melebihi batas %s.',
                $this->humanSize(strlen($binary)),
                $this->humanSize(self::MAX_BYTES),
            ));
        }

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

    private function assertAcceptable(string $extension, string $mime): void
    {
        if (! array_key_exists($extension, self::ALLOWED)) {
            throw new LogicException(sprintf(
                'Jenis berkas ".%s" tidak diizinkan. Yang diterima: %s.',
                $extension,
                implode(', ', array_keys(self::ALLOWED)),
            ));
        }

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
