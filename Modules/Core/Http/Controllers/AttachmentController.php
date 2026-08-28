<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\Attachment;
use Modules\Core\Services\AttachmentService;
use Modules\Core\Support\AttachableDocuments;

/**
 * Attachments on documents.
 *
 * Permission is derived from the DOCUMENT, not fixed on the route: reading an
 * attachment on a vendor bill needs fin.view, adding one needs fin.update. An
 * attachment must never be reachable by somebody who cannot reach the document
 * it belongs to, and a single route-level permission could not express that
 * across twenty-two document types.
 */
class AttachmentController extends ApiController
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in(AttachableDocuments::slugs())],
            'document_id' => ['required', 'integer', 'min:1'],
        ]);

        // Permission BEFORE resolution. The other order answers "does bill 4711
        // exist?" for somebody with no right to know — the not-found message and
        // the forbidden one are distinguishable, and that difference is an
        // enumeration oracle over every document in the system.
        if (($denied = $this->deny($request, $data['document_type'], 'view')) !== null) {
            return $denied;
        }

        try {
            $document = $this->attachments->resolveDocument($data['document_type'], (int) $data['document_id']);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok($this->attachments->withSiteDistance($document, $this->attachments->forDocument($document)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in(AttachableDocuments::slugs())],
            'document_id' => ['required', 'integer', 'min:1'],
            'filename' => ['required', 'string', 'max:255'],
            // ~6.8 MB of base64 for a 5 MB file, refused here rather than by
            // php-fpm — which would return an empty 413 with no message.
            'content' => ['required', 'string', 'max:7000000'],
            'caption' => ['nullable', 'string', 'max:255'],
            // Where the phone says it is. Only consulted when the image carries
            // no EXIF GPS of its own — see AttachmentService::geotag().
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (($denied = $this->deny($request, $data['document_type'], 'update')) !== null) {
            return $denied;
        }

        try {
            $document = $this->attachments->resolveDocument($data['document_type'], (int) $data['document_id']);

            $attachment = $this->attachments->store(
                $document,
                $data['filename'],
                $data['content'],
                $data['caption'] ?? null,
                $request->user()?->id,
                [
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'accuracy_m' => $data['accuracy_m'] ?? null,
                ],
            );

            return $this->created($attachment, "Lampiran {$attachment->original_name} disimpan.");
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * The multipart twin of store(), for the sizes base64-in-JSON cannot
     * carry (a 25 MB drawing is ~33 MB of base64 — over the deployed 26M
     * post_max_size; raw multipart fits). Same permission, same registry
     * checks, same service path, same resource shape — only the transport
     * differs.
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in(AttachableDocuments::slugs())],
            'document_id' => ['required', 'integer', 'min:1'],
            // No size rule here: the per-extension limit is the service's,
            // shared with the JSON route so the two can never disagree.
            'file' => ['required', 'file'],
            'caption' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (($denied = $this->deny($request, $data['document_type'], 'update')) !== null) {
            return $denied;
        }

        try {
            $document = $this->attachments->resolveDocument($data['document_type'], (int) $data['document_id']);

            $file = $request->file('file');

            $attachment = $this->attachments->storeBinary(
                $document,
                $file->getClientOriginalName(),
                (string) file_get_contents($file->getRealPath()),
                $data['caption'] ?? null,
                $request->user()?->id,
                [
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'accuracy_m' => $data['accuracy_m'] ?? null,
                ],
            );

            return $this->created($attachment, "Lampiran {$attachment->original_name} disimpan.");
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function download(Request $request, int $attachment): Response|JsonResponse
    {
        $found = $this->reachable($request, $attachment, 'view');

        if (! $found instanceof Attachment) {
            return $found;
        }

        try {
            $body = $this->attachments->contents($found);
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 404);
        }

        $attachment = $found;

        // Inline only for images and PDFs; everything else downloads. nosniff on
        // both, so a browser cannot decide for itself that a stored file is
        // markup and run it in this origin.
        return response($body, 200, [
            'Content-Type' => $attachment->mime,
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $attachment->isInlineSafe() ? 'inline' : 'attachment',
                str_replace('"', '', $attachment->original_name),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function destroy(Request $request, int $attachment): JsonResponse
    {
        $found = $this->reachable($request, $attachment, 'update');

        if (! $found instanceof Attachment) {
            return $found;
        }

        $this->attachments->delete($found);

        return $this->ok(null, 'Lampiran dihapus.');
    }

    /**
     * The attachment, or the reply to send instead.
     *
     * Route-model binding is deliberately NOT used on these two routes. It 404s
     * a missing id before the controller runs, while a forbidden one gets a 403
     * naming the module — so the two replies differ and the pair enumerates
     * every attachment in the system, and tells you which module each belongs
     * to. Missing, unmapped, orphaned and forbidden all collapse onto one
     * indistinguishable answer here.
     *
     * The parent is resolved as well as the row: an attachment whose document
     * has been deleted must not remain downloadable by anyone who still holds
     * the module's view permission.
     */
    private function reachable(Request $request, int $id, string $action): Attachment|JsonResponse
    {
        $refusal = $this->error('Lampiran tidak ditemukan.', 404);

        $attachment = Attachment::query()->find($id);

        if ($attachment === null) {
            return $refusal;
        }

        $slug = $attachment->documentSlug();

        if ($slug === null || ! $request->user()?->can(AttachableDocuments::prefixFor($slug).'.'.$action)) {
            return $refusal;
        }

        $parent = $attachment->attachable_type::query()
            ->when(
                in_array(SoftDeletes::class, class_uses_recursive($attachment->attachable_type), true),
                fn ($query) => $query->withTrashed(),
            )
            ->find($attachment->attachable_id);

        return $parent === null ? $refusal : $attachment;
    }

    /**
     * The permission the parent document's module requires, or null when the
     * caller holds it.
     */
    private function deny(Request $request, string $slug, string $action): ?JsonResponse
    {
        $permission = AttachableDocuments::prefixFor($slug).'.'.$action;

        if ($request->user()?->can($permission)) {
            return null;
        }

        return $this->error("Anda tidak memiliki izin {$permission}.", 403);
    }
}
