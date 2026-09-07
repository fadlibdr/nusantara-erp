<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Core\Support\ApprovalQueue;
use Modules\Core\Support\Erp;

/**
 * GET core/inbox — kotak masuk persetujuan pemanggil. Logikanya di
 * ApprovalQueue supaya kartu dasbor, layar Tugas Saya, dan erp:approval-watch
 * membaca antrean yang sama persis.
 */
class InboxController extends ApiController
{
    /**
     * Plafon setujui massal, atau null bila fiturnya mati.
     *
     * Kosong dan nol sama-sama MATI: seorang operator yang mengetik 0 berarti
     * "jangan", dan menafsirkannya sebagai "tanpa batas" adalah cara terburuk
     * untuk salah membaca sebuah angka.
     */
    private function batchCap(): ?int
    {
        $cap = Erp::setting('approvals.batch_cap');

        if ($cap === null || $cap === '' || ! is_numeric($cap)) {
            return null;
        }

        $cap = (int) $cap;

        return $cap > 0 ? $cap : null;
    }

    /** @return list<array<string, mixed>> */
    private function activeDelegations($user): array
    {
        $ids = array_column(ApprovalDelegations::activeFor($user), 'id');

        if ($ids === []) {
            return [];
        }

        return ApprovalDelegation::query()
            ->with('giver:id,name')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (ApprovalDelegation $row): array => [
                'id' => $row->id,
                'giver' => $row->giver?->name,
                'scope' => $row->scope,
                'starts_at' => $row->starts_at?->toDateString(),
                'ends_at' => $row->ends_at?->toDateString(),
                'reason' => $row->reason,
            ])
            ->values()
            ->all();
    }

    public function __invoke(Request $request): JsonResponse
    {
        $now = now();
        $user = $request->user();
        $queue = ApprovalQueue::pending($user, $now);

        return $this->ok($queue['rows'], null, [
            'total' => count($queue['rows']),
            'failed' => $queue['failed'],
            'as_of' => $now->toDateTimeString(),
            /*
             * F-1 — plafon setujui massal. null = fitur MATI, dan layar tidak
             * menggambar tombolnya sama sekali; sebuah tombol yang ada tetapi
             * menolak bekerja lebih buruk daripada tidak ada tombol.
             * Dikirim di sini dan bukan dibaca dari GET core/settings karena
             * inilah satu-satunya layar yang memakainya, dan sebuah antrean
             * persetujuan tidak boleh menuntut hak baca seluruh Pengaturan.
             */
            'batch_cap' => $this->batchCap(),
            // Spanduk "Anda menyetujui a.n. …": delegasi yang sedang berjalan
            // untuk pembaca ini, supaya hak pinjaman tidak pernah dipakai
            // tanpa orangnya tahu ia sedang memakainya.
            'delegations' => $this->activeDelegations($user),
        ]);
    }
}
