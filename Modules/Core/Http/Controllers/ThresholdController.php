<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Support\WatchedThresholds;

/**
 * Kebenaran hidup di balik layar Ambang (F-2 / T2.1).
 *
 * Sekembar DeadlineController, termasuk aturan penyaringannya: entri dibatasi
 * pada izin yang DIPEGANG pemanggil, jadi "tidak ada apa-apa di sini" dan
 * "tidak ada yang boleh Anda lihat" terbaca sama (aturan GlobalSearch) dan
 * layar ini tidak pernah menampilkan angka anggaran kepada orang yang tidak
 * boleh membacanya.
 *
 * Baris SKIPPED ikut dilaporkan pada meta, bukan disembunyikan: sebuah entri
 * yang tabelnya belum ada atau modul pemasoknya belum memasok berbeda dari
 * entri yang semua barisnya aman, dan menyamakan keduanya adalah cara sebuah
 * layar mengaku tenang justru saat ia buta.
 */
class ThresholdController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $scan = WatchedThresholds::scan();
        $user = $request->user();

        $measures = array_values(array_filter(
            $scan['measures'],
            static fn (array $measure): bool => $user !== null && $user->can($measure['permission']),
        ));

        return $this->ok($measures, null, [
            'checked' => $scan['checked'],
            'skipped' => count($scan['skipped']),
            'skipped_detail' => $scan['skipped'],
        ]);
    }
}
