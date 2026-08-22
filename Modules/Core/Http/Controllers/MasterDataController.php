<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Core\Services\MasterDataImportService;
use Modules\Core\Support\ImportableResources;

/**
 * Bulk load and bulk export of master data.
 *
 * There is no route-level permission because the right one depends on which
 * table is being loaded — items are inv, vendors are prc, and so on. It is
 * derived per request from the registry, the same way the attachment controller
 * derives one from the document type.
 *
 * Reading needs the module's VIEW; writing needs its CREATE **and** UPDATE,
 * because an import that matches on code updates existing rows as well as
 * creating new ones. Somebody who may only create should not be able to rewrite
 * two thousand records by uploading a sheet.
 */
class MasterDataController extends ApiController
{
    public function __construct(private readonly MasterDataImportService $imports) {}

    /** What can be loaded, and the columns each file needs. */
    public function index(Request $request): JsonResponse
    {
        $resources = [];

        foreach (ImportableResources::all() as $key => $definition) {
            if (! $request->user()?->can("{$definition['permission']}.view")) {
                continue;
            }

            $resources[] = [
                'key' => $key,
                'label' => $definition['label'],
                'can_import' => $this->canWrite($request, $definition['permission']),
                'columns' => array_map(fn ($column) => [
                    'header' => $column['header'],
                    'required' => (bool) ($column['required'] ?? false),
                ], $definition['columns']),
            ];
        }

        return $this->ok($resources);
    }

    public function template(Request $request, string $resource): Response|JsonResponse
    {
        return $this->guarded($request, $resource, 'view', fn (array $definition) => $this->csv(
            $this->imports->template($resource),
            "template-{$resource}.csv",
        ));
    }

    public function export(Request $request, string $resource): Response|JsonResponse
    {
        return $this->guarded($request, $resource, 'view', fn (array $definition) => $this->csv(
            $this->imports->export($resource),
            "{$resource}-".now()->format('Y-m-d').'.csv',
        ));
    }

    /** What the file would do, without doing it. */
    public function preview(Request $request, string $resource): JsonResponse
    {
        return $this->guarded($request, $resource, 'write', function () use ($request, $resource) {
            $data = $this->validateUpload($request);

            try {
                return $this->ok($this->imports->preview($resource, $data['filename'], $data['content']));
            } catch (LogicException $e) {
                return $this->error($e->getMessage());
            }
        });
    }

    public function commit(Request $request, string $resource): JsonResponse
    {
        return $this->guarded($request, $resource, 'write', function () use ($request, $resource) {
            $data = $this->validateUpload($request);

            try {
                $result = $this->imports->commit($resource, $data['filename'], $data['content']);
            } catch (LogicException $e) {
                return $this->error($e->getMessage());
            }

            return $this->ok($result, sprintf(
                '%d dibuat, %d diperbarui, %d dilewati.',
                $result['created'],
                $result['updated'],
                $result['skipped'],
            ));
        });
    }

    private function validateUpload(Request $request): array
    {
        return $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            // ~6.8 MB of base64 for a 5 MB file, refused here rather than by
            // php-fpm — which would return an empty 413 with no message.
            'content' => ['required', 'string', 'max:7340032'],
        ]);
    }

    /**
     * Resolve the resource, check the permission its module demands, then run.
     *
     * The unknown-resource check comes first and is deliberately indistinguishable
     * from a typo: the list of what exists is already public to any signed-in user
     * via index(), so there is nothing to leak.
     */
    private function guarded(Request $request, string $resource, string $mode, \Closure $run): Response|JsonResponse
    {
        try {
            $definition = ImportableResources::definition($resource);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }

        $allowed = $mode === 'write'
            ? $this->canWrite($request, $definition['permission'])
            : (bool) $request->user()?->can("{$definition['permission']}.view");

        if (! $allowed) {
            return $this->error('Anda tidak memiliki izin untuk data master ini.', 403);
        }

        return $run($definition);
    }

    /** An import both creates and updates, so it needs both rights. */
    private function canWrite(Request $request, string $module): bool
    {
        $user = $request->user();

        return (bool) $user?->can("{$module}.create") && (bool) $user->can("{$module}.update");
    }

    /** BOM, headers and all — shared with the document importer on ApiController. */
    private function csv(string $body, string $filename): Response
    {
        return $this->csvDownload($body, $filename);
    }
}
