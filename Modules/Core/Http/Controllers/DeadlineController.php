<?php

namespace Modules\Core\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Support\WatchedDeadlines;

/**
 * The live truth behind the tenggat screen.
 *
 * A notification can be read on a Tuesday and forgotten by Friday; this
 * endpoint re-runs the same scan the daily command runs, so an unresolved
 * deadline stays visible no matter what happened in anyone's inbox. Entries
 * are filtered to the permissions the CALLER holds — the same rule the
 * notifications follow, so this screen never shows a deadline its viewer
 * could not act on, and "nothing here" and "nothing you may see" read the
 * same (the GlobalSearch rule).
 */
class DeadlineController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $today = CarbonImmutable::today();
        $scan = WatchedDeadlines::scan($today);
        $user = $request->user();

        $findings = array_values(array_filter(
            $scan['findings'],
            static fn (array $finding): bool => $user !== null && $user->can($finding['permission']),
        ));

        return $this->ok($findings, null, [
            'today' => $today->toDateString(),
            'checked' => $scan['checked'],
            'skipped' => count($scan['skipped']),
        ]);
    }
}
