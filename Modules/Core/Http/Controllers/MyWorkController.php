<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Support\MyWork;

/**
 * GET core/my-work — Pekerjaan Saya pemanggil (ERRATA E-9, UX-F1). Logikanya di
 * MyWork, cermin ApprovalQueue; meta memakai kejujuran kotak masuk: `failed`
 * menyebut jenis yang gagal dimuat, `as_of` kapan dibaca.
 */
class MyWorkController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $now = now();
        $work = MyWork::for($request->user(), $now);

        return $this->ok($work['rows'], null, [
            'total' => count($work['rows']),
            'failed' => $work['failed'],
            'as_of' => $now->toDateTimeString(),
        ]);
    }
}
