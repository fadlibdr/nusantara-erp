<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Http\Resources\RateHistoryEntryResource;
use Modules\Core\Models\RateHistoryEntry;

/**
 * Riwayat tarif PPN & PPh final (P8, D5). Baca-saja dan bergerbang core.view,
 * seperti audit-log: baris ditulis hanya oleh SettingService lewat
 * RateHistoryService, dan riwayat yang bisa ditulis lewat permintaan bukan
 * riwayat. Tidak ada angka dokumen yang dihitung dari sini — snapshot per
 * dokumen tetap sumber kebenaran.
 */
class RateHistoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $entries = RateHistoryEntry::query()
            ->with('user:id,name')
            ->when(isset($data['key']), fn ($query) => $query->where('rate_key', $data['key']))
            ->orderByDesc('id');

        return $this->listing($request, $entries, RateHistoryEntryResource::class,
            sortable: ['created_at'],
            dateColumn: 'created_at',
            perPageDefault: 25,
        );
    }
}
