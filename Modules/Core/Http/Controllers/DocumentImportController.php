<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Core\Services\DocumentImportService;
use Modules\Core\Support\ImportableDocuments;

/**
 * Bulk load and bulk export of documents that are a parent plus lines —
 * penawaran, BOQ, AHSP, RAP.
 *
 * Mirrors MasterDataController exactly, and for the same reason: there is no
 * route-level permission because the right one depends on which document is
 * being loaded — a penawaran is crm, a BOQ is est — so it is derived per request
 * from the registry.
 *
 * Reading needs the module's VIEW; importing needs its CREATE **and** UPDATE,
 * because an import that matches on code updates existing documents as well as
 * creating new ones. approve is never required and never granted: nothing this
 * endpoint does can approve a document, and no template carries a status column,
 * so no file can ask for one.
 *
 * Per-document and per-line failures come back 200 with valid:false. They are
 * data, not HTTP errors, and the operator has to see all of them at once —
 * fixing a 400-line BOQ one refusal per upload is not a workflow.
 */
class DocumentImportController extends ApiController
{
    public function __construct(
        private readonly DocumentImportService $imports,
        private readonly ImportableDocuments $registry,
    ) {}

    /** What can be loaded, the row types each file uses, and the columns each row needs. */
    public function index(Request $request): JsonResponse
    {
        $resources = [];

        foreach ($this->registry->keys() as $key) {
            $definition = $this->registry->definition($key);

            if (! $this->registry->mayRead($request->user(), $definition)) {
                continue;
            }

            $rowTypes = [];
            $columns = [];

            foreach ($definition['rows'] as $type => $row) {
                $rowTypes[] = [
                    'tipe' => $type,
                    'label' => $row['label'],
                    'required_columns' => array_values(array_column(
                        array_filter($row['columns'], fn (array $column) => $column['required'] ?? false),
                        'header',
                    )),
                ];

                foreach ($row['columns'] as $column) {
                    $columns[$column['header']]['header'] = $column['header'];
                    $columns[$column['header']]['row_types'][] = $type;
                    $columns[$column['header']]['required'] = ($columns[$column['header']]['required'] ?? false)
                        || (bool) ($column['required'] ?? false);
                }
            }

            $resources[] = [
                'key' => $key,
                'label' => $definition['label'],
                'module' => $definition['module'],
                'permission' => $definition['permission'],
                'group_column' => $definition['group'],
                'can_import' => $this->registry->mayImport($request->user(), $definition),
                'row_types' => $rowTypes,
                'columns' => array_values($columns),
            ];
        }

        return $this->ok($resources);
    }

    public function template(Request $request, string $resource): Response|JsonResponse
    {
        return $this->guarded($request, $resource, 'view', fn () => $this->csvDownload(
            $this->imports->template($resource),
            "template-{$resource}.csv",
        ));
    }

    public function export(Request $request, string $resource): Response|JsonResponse
    {
        return $this->guarded($request, $resource, 'view', function () use ($request, $resource) {
            try {
                $csv = $this->imports->export($resource, [
                    'kode' => $request->query('kode'),
                    'status' => $request->query('status'),
                ]);
            } catch (LogicException $e) {
                // A filter this resource cannot answer — ?status= on AHSP, which
                // has no status column. Refused in words rather than served as a
                // file containing only the header row, which an operator reads
                // as "there are no analyses".
                return $this->error($e->getMessage());
            }

            return $this->csvDownload($csv, "{$resource}-".now()->format('Y-m-d').'.csv');
        });
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
                // The engine names the importer as the maker of any document a
                // definition lands as `submitted` (maker-checker, T3.4).
                $result = $this->imports->commit($resource, $data['filename'], $data['content'], $request->user());
            } catch (LogicException $e) {
                return $this->error($e->getMessage());
            }

            return $this->ok($result, sprintf(
                '%d dokumen dibuat, %d diperbarui, %d dilewati.',
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
            $definition = $this->registry->definition($resource);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }

        $allowed = $mode === 'write'
            ? $this->registry->mayImport($request->user(), $definition)
            : $this->registry->mayRead($request->user(), $definition);

        if (! $allowed) {
            // The message has to name the mode that was refused. 'view' guards
            // the template download and the export, not an upload — and saying
            // "untuk mengimpor" to somebody refused a TEMPLATE for lacking
            // est.view sent them asking for est.create and est.update, neither
            // of which would have opened the door.
            return $this->error($mode === 'write'
                ? 'Anda tidak memiliki izin untuk mengimpor dokumen ini.'
                : 'Anda tidak memiliki izin untuk mengunduh template atau ekspor dokumen ini.', 403);
        }

        return $run($definition);
    }
}
