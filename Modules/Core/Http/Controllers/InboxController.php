<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Support\ApprovalQueue;

/**
 * GET core/inbox — kotak masuk persetujuan pemanggil. Logikanya di
 * ApprovalQueue supaya kartu dasbor, layar Tugas Saya, dan erp:approval-watch
 * membaca antrean yang sama persis.
 */
class InboxController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $now = now();
        $queue = ApprovalQueue::pending($request->user(), $now);

        return $this->ok($queue['rows'], null, ['total' => count($queue['rows']), 'failed' => $queue['failed'], 'as_of' => $now->toDateTimeString()]);
    }
}
