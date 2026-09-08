<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Http\ApiController;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Http\Resources\LeadResource;
use Modules\Crm\Models\Lead;

/**
 * `GET crm/pipeline/board` — muatan papan kanban prospek (F-3 / T3.6).
 *
 * KENAPA BUKAN `GET crm/leads` SAJA, yang sudah dipakai papan-papan Fase 1.
 * Papan generik mengambil satu halaman (PER_LANE × jumlah kolom) lalu
 * mengelompokkannya di klien — yang berarti sebuah corong dengan 300 prospek
 * Menang akan mendorong seluruh kolom "Baru" keluar dari halaman itu, dan
 * papannya tampak KOSONG di kolom yang justru paling banyak dikerjakan. Rute
 * ini mengambil N teratas PER KOLOM dan, yang lebih penting, memulangkan
 * JUMLAH SEBENARNYA per kolom: kartu yang tidak digambar tetap terhitung, dan
 * papannya mengatakannya alih-alih diam.
 *
 * Urutan di dalam kolom: tindak lanjut paling mendesak di atas (turunan
 * aktivitas, T3.3), yang tanpa tanggal di bawahnya — bukan "tahun 1970".
 *
 * Tidak ada aturan transisi di sini. Rute ini MEMBACA; perpindahan tetap lewat
 * POST leads/{id}/pipeline (LeadPipelineService), pintu yang sama dengan layar
 * dokumen — papan yang menulis statusnya sendiri akan kehilangan aturan
 * mundur-beralasan dan menang-lewat-penawaran sekaligus, diam-diam.
 */
class PipelineBoardController extends ApiController
{
    /** Kartu per kolom. Papan bukan daftar lengkap — dan ia mengaku begitu. */
    private const PER_LANE = 25;

    public function board(Request $request): JsonResponse
    {
        $perLane = min(100, max(5, (int) $request->integer('per_lane', self::PER_LANE)));
        $today = Carbon::today()->toDateString();

        $cards = [];
        $lanes = [];

        foreach (LeadStatus::cases() as $status) {
            $base = Lead::query()->where('status', $status->value);

            $rows = (clone $base)
                ->with('owner:id,name')
                // Aktivitas terbuka yang lewat tanggal — angka yang membuat
                // sebuah kartu layak disentuh hari ini.
                ->withCount(['activities as overdue_activities_count' => fn ($query) => $query
                    ->whereNull('done_at')
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', $today)])
                ->withCount(['activities as open_activities_count' => fn ($query) => $query->whereNull('done_at')])
                ->orderByRaw('CASE WHEN next_follow_up_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('next_follow_up_at')
                ->orderByDesc('id')
                ->limit($perLane)
                ->get();

            foreach ($rows as $lead) {
                $cards[] = $this->card($lead);
            }

            $lanes[] = [
                'status' => $status->value,
                'label' => $status->label(),
                // Jumlah sebenarnya, bukan jumlah yang digambar.
                'count' => (clone $base)->count(),
                'shown' => $rows->count(),
                'estimated_value' => (float) (clone $base)->sum('estimated_value'),
            ];
        }

        return $this->ok($cards, null, [
            'lanes' => $lanes,
            'per_lane' => $perLane,
        ]);
    }

    /**
     * Satu kartu: baris prospek apa adanya (LeadResource — kalimat "Belum
     * ditugaskan" yang sama dengan daftar dan CSV) plus satu kalimat aktivitas.
     */
    private function card(Lead $lead): array
    {
        $row = LeadResource::make($lead)->resolve();

        $overdue = (int) $lead->overdue_activities_count;
        $open = (int) $lead->open_activities_count;

        /*
         * SATU kalimat, dan ia hanya ada bila ada yang bisa dikatakan: sebuah
         * kartu yang selalu memajang "0 aktivitas" mengajari pembacanya
         * mengabaikan baris itu, dan yang mengabaikannya juga akan melewatkan
         * "3 lewat tanggal". null = kartunya diam.
         */
        $row['activity_note'] = match (true) {
            $overdue > 0 => "{$overdue} aktivitas lewat tanggal",
            $open > 0 => "{$open} aktivitas terbuka",
            default => null,
        };

        $row['open_activities_count'] = $open;
        $row['overdue_activities_count'] = $overdue;

        return $row;
    }
}
