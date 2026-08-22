<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Root status/info endpoints. Controller-based (not closures) so that
 * `php artisan route:cache` can cache the full route table in production.
 */
class StatusController extends Controller
{
    /**
     * Send the root to the front-end. The trailing slash matters: the SPA in
     * public/app references its assets relatively, so landing on "/app" (no
     * slash) would resolve them against the document root.
     */
    public function app(): RedirectResponse
    {
        return new RedirectResponse('/app/');
    }

    public function web(): JsonResponse
    {
        return response()->json([
            'app' => config('app.name'),
            'status' => 'ok',
            'docs' => 'See README.md and docs/ for API documentation.',
        ]);
    }

    public function api(): JsonResponse
    {
        return response()->json([
            'app' => config('app.name'),
            'modules' => collect(glob(base_path('Modules/*'), GLOB_ONLYDIR))
                ->map(fn ($dir) => basename($dir))
                ->values(),
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
