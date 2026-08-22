<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\ApiController;
use Modules\Core\Http\Requests\UpdateSettingsRequest;
use Modules\Core\Services\SettingService;

/**
 * Registry-driven settings screen. Every editable parameter, its effective
 * value and its shipped default come from SettingService; nothing here knows
 * about individual keys.
 */
class SettingController extends ApiController
{
    public function __construct(private readonly SettingService $settings) {}

    public function index(): JsonResponse
    {
        return $this->ok(['groups' => $this->settings->overview()]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->settings->setMany($request->overrides()));

        // setMany() flushes per key, i.e. before the commit — a concurrent read
        // could have re-cached the pre-commit map. Flush once more after commit.
        $this->settings->flush();

        // Same payload as index() so the client can re-render without a second call.
        return $this->ok(['groups' => $this->settings->overview()], 'Pengaturan disimpan.');
    }
}
