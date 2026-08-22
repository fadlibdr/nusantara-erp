<?php

namespace Modules\Core\Http;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Carbon;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, ?string $message = null, ?array $meta = null): JsonResponse
    {
        $payload = ['data' => $data];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        // A paginator nested inside the envelope loses its meta when the
        // resource collection is serialised, so lift it out explicitly.
        $meta ??= $this->paginationMeta($data);

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload);
    }

    /**
     * Pagination meta for a paginated payload, null for anything else.
     */
    private function paginationMeta(mixed $data): ?array
    {
        $paginator = $data instanceof ResourceCollection ? $data->resource : $data;

        if (! $paginator instanceof AbstractPaginator) {
            return null;
        }

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        // simplePaginate() has no total/last_page — only report what exists.
        if (method_exists($paginator, 'total')) {
            $meta['total'] = $paginator->total();
            $meta['last_page'] = $paginator->lastPage();
        }

        return $meta;
    }

    /**
     * The one list mechanism every index() funnels through: whitelisted
     * sorting, an optional date window on the declared document-date column,
     * and a meta block advertising both — so the SPA discovers sortability
     * and the date column from the response instead of duplicating server
     * knowledge client-side.
     *
     * The whitelist lives in the controller call, not model introspection:
     * introspection would let a client ORDER BY unindexed text blobs, and it
     * cannot tell a base-table column from a joined or computed name. One
     * literal array per resource keeps it reviewable, base-table columns only.
     *
     * $resource is nullable because a few lists (audit log, change orders)
     * have no Resource class. Those used to hand ok() the paginator itself,
     * which serialised the rows one level deeper ({ data: { data: [...] } })
     * than the SPA's list reader ever looks — the change-order list rendered
     * permanently empty because of it. The concern emits the paginator's
     * collection instead, so data is a flat array on every list.
     */
    protected function listing(
        Request $request,
        Builder $query,
        ?string $resource = null,
        array $sortable = [],
        ?string $dateColumn = null,
        array $meta = [],
        int $perPageDefault = 20,
    ): JsonResponse {
        $sort = $request->query('sort');
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $sorted = $sort !== null && $sort !== '';

        if ($sorted) {
            // An unknown key is refused loudly, never silently ignored: a
            // silent fallback returns rows in the default order under a header
            // the user believes is sorted. Refusal also means the raw string
            // never reaches SQL, whatever shape it has.
            if (! is_string($sort) || ! in_array($sort, $sortable, true)) {
                $message = $sortable === []
                    ? 'Daftar ini tidak mendukung pengurutan.'
                    : 'Kolom urut tidak dikenali. Kolom yang tersedia: '.implode(', ', $sortable).'.';

                return $this->error($message, 422, ['sort' => [$message]]);
            }

            // reorder() clears the controller's own default ORDER BY without
            // touching that line of code; the id tiebreak keeps page
            // boundaries deterministic when the sort key has equal values.
            $query->reorder($sort, $dir)->orderByDesc($query->getModel()->getQualifiedKeyName());
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $param => $operator) {
            $value = $request->query($param);

            // Only strings shaped like a date reach the query. $request->date()
            // throws on garbage and would turn a crafted link into a 500; a
            // malformed bound is dropped instead, leaving the window open on
            // that side. Both bounds are inclusive (whereDate convention).
            if ($dateColumn !== null && is_string($value) && Carbon::hasFormat($value, 'Y-m-d')) {
                $query->whereDate($dateColumn, $operator, $value);
            }
        }

        // Deliberately uncapped: lookup.js pages these same endpoints at
        // per_page=500 up to its own 10.000 ceiling — a "safety" cap here
        // would break every picker in the app.
        $perPage = $request->integer('per_page', $perPageDefault);

        // integer() casts garbage ('abc') to 0, and paginate(0) quietly swaps
        // in Eloquent's generic 15 — the one page size no resource declares.
        // Anything not positive falls back to THIS list's default, so
        // meta.per_page always echoes a size the caller could have asked for.
        $paginator = $query->paginate($perPage > 0 ? $perPage : $perPageDefault);
        $data = $resource !== null ? $resource::collection($paginator) : $paginator->getCollection();

        return $this->ok($data, null, array_merge($this->paginationMeta($paginator) ?? [], [
            'sortable' => $sortable,
            'sort' => $sorted ? $sort : null,
            'dir' => $sorted ? $dir : null,
            'date_column' => $dateColumn,
        ], $meta));
    }

    protected function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->ok($data, $message ?? 'Created')->setStatusCode(201);
    }

    /**
     * A CSV an officer can double-click.
     *
     * Excel opens a UTF-8 CSV as Latin-1 unless it finds a BOM, which turns
     * every "PT Cahaya Abadi" with a non-ASCII character into mojibake on the
     * officer's machine. Shared by both importers so the two download endpoints
     * cannot drift apart on it.
     */
    protected function csvDownload(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ])->setContent("\xEF\xBB\xBF".$body);
    }

    protected function error(string $message, int $status = 422, mixed $errors = null): JsonResponse
    {
        $payload = ['message' => $message];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
