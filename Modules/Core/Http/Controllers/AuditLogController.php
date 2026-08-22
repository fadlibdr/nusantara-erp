<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\AuditLog;
use Modules\Core\Support\AuditedModels;

/**
 * The change log.
 *
 * Read-only, and gated on core.view — the log records who changed master data
 * that moves money, so it is not something every user should be able to browse.
 * There is no write endpoint by design: entries are made by observers, and a log
 * an application can write to on request is not evidence.
 */
class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auditable_type' => ['nullable', 'string', 'max:120'],
            'auditable_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'in:created,updated,deleted'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when(
                isset($data['auditable_type']),
                // Accepts the short name from the UI as well as the FQCN.
                fn ($query) => $query->where('auditable_type', 'like', '%'.class_basename($data['auditable_type'])),
            )
            ->when(isset($data['auditable_id']), fn ($query) => $query->where('auditable_id', $data['auditable_id']))
            ->when(isset($data['user_id']), fn ($query) => $query->where('user_id', $data['user_id']))
            ->when(isset($data['event']), fn ($query) => $query->where('event', $data['event']))
            ->orderByDesc('id');

        // created_at is the one place "semua aktivitas November" is asked
        // verbatim. No Resource class — listing() emits the rows flat and
        // restores the pagination meta the custom-meta ok() call used to drop.
        return $this->listing($request, $logs, null,
            sortable: ['created_at'],
            dateColumn: 'created_at',
            meta: ['audited_types' => array_map('class_basename', AuditedModels::classes())],
            perPageDefault: 25,
        );
    }
}
