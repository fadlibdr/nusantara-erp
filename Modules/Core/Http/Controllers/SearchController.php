<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Services\GlobalSearchService;

/**
 * One search box over everything the caller may read.
 *
 * No route-level permission: the service queries only the groups whose module
 * permission the caller holds, and skips the rest entirely rather than filtering
 * their results afterwards. A search box that runs the query and then hides the
 * rows still answers "does PO/2026/VII/0042 exist?" through its timing and its
 * empty-vs-forbidden distinction.
 */
class SearchController extends ApiController
{
    public function __construct(private readonly GlobalSearchService $search) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:120'],
            'per_group' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return $this->ok($this->search->search(
            $request->user(),
            $data['q'],
            (int) ($data['per_group'] ?? 5),
        ));
    }
}
