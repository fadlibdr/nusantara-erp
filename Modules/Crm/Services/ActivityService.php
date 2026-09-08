<?php

namespace Modules\Crm\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Models\Activity;
use Modules\Crm\Support\ActivityDocuments;

/**
 * Satu-satunya pintu tulis aktivitas CRM.
 *
 * Ia ada karena tiga alasan, dan ketiganya adalah kebohongan yang mungkin
 * terjadi kalau controller menulis langsung:
 *
 *  1. `done_at` / `done_by_id` DICAP SERVER. Sebuah aktivitas yang bisa
 *     diketik "selesai kemarin oleh Budi" bukan catatan, ia karangan; kartu
 *     aktivitas menampilkan keduanya sebagai fakta.
 *  2. Induknya HARUS ADA. document_type + document_id yang menunjuk baris yang
 *     tidak ada (atau sudah dihapus) membuat aktivitas yang tidak pernah
 *     muncul di layar mana pun — pekerjaan yang hilang tanpa satu galat pun.
 *  3. next_follow_up_at prospek DITURUNKAN dari baris-baris ini (F-3 / T3.3),
 *     jadi setiap perubahan di sini harus menghitung ulang turunannya. Itulah
 *     kenapa hanya ADA SATU pintu: dua pintu berarti satu di antaranya lupa.
 */
class ActivityService
{
    public function __construct(private readonly LeadFollowUpService $followUp) {}

    public function create(array $data): Activity
    {
        $this->assertDocumentExists((string) $data['document_type'], (int) $data['document_id']);

        return DB::transaction(function () use ($data): Activity {
            $activity = Activity::query()->create([
                ...Arr::except($data, ['done_at', 'done_by_id']),
                // Pemilik yang tidak disebut TIDAK ditebak dari pembuatnya:
                // "Belum ditugaskan" adalah keadaan yang sungguhan ada, dan
                // menugaskan diam-diam adalah cara sebuah antrean kerja
                // menjadi milik orang yang tidak pernah menyanggupinya.
                'owner_user_id' => $data['owner_user_id'] ?? null,
            ]);

            $this->afterChange($activity);

            return $activity;
        });
    }

    public function update(Activity $activity, array $data): Activity
    {
        if (array_key_exists('document_type', $data) || array_key_exists('document_id', $data)) {
            $type = (string) ($data['document_type'] ?? $activity->document_type);
            $id = (int) ($data['document_id'] ?? $activity->document_id);
            $this->assertDocumentExists($type, $id);
        }

        return DB::transaction(function () use ($activity, $data): Activity {
            // Dokumen LAMA ikut dihitung ulang: memindahkan aktivitas dari satu
            // prospek ke prospek lain mengubah tanggal tindak lanjut KEDUANYA,
            // dan yang ditinggalkan adalah yang paling mudah terlupakan.
            $before = ['type' => $activity->document_type, 'id' => (int) $activity->document_id];

            $activity->fill(Arr::except($data, ['done_at', 'done_by_id']))->save();

            $this->afterChange($activity);
            $this->followUp->recomputeFor($before['type'], $before['id']);

            return $activity->refresh();
        });
    }

    /**
     * "Selesai" — dicap dengan jam server dan nama penekannya.
     *
     * Menolak yang sudah selesai, dengan kalimat yang menyebut kapan dan oleh
     * siapa: dua orang yang menekan tombol yang sama pada baris yang sama
     * tidak boleh diam-diam saling menimpa cap waktunya.
     */
    public function markDone(Activity $activity, ?User $actor = null): Activity
    {
        if ($activity->done_at !== null) {
            $who = $activity->doneBy?->name;

            throw ValidationException::withMessages([
                'done_at' => ['Aktivitas ini sudah ditandai selesai pada '
                    .$activity->done_at->format('d/m/Y H:i')
                    .($who !== null ? " oleh {$who}" : '').'.'],
            ]);
        }

        return DB::transaction(function () use ($activity, $actor): Activity {
            $activity->forceFill([
                'done_at' => Carbon::now(),
                'done_by_id' => $actor?->id,
            ])->save();

            $this->afterChange($activity);

            return $activity->refresh();
        });
    }

    /**
     * Membatalkan cap selesai.
     *
     * Ada karena satu klik salah pada baris yang salah akan menghapus tanggal
     * tindak lanjut sebuah prospek diam-diam (turunannya hanya membaca yang
     * TERBUKA), dan tanpa jalan pulang orang akan mengetik ulang aktivitas
     * kedua yang isinya sama — dua baris untuk satu telepon.
     */
    public function reopen(Activity $activity): Activity
    {
        if ($activity->done_at === null) {
            throw ValidationException::withMessages([
                'done_at' => ['Aktivitas ini memang belum ditandai selesai.'],
            ]);
        }

        return DB::transaction(function () use ($activity): Activity {
            $activity->forceFill(['done_at' => null, 'done_by_id' => null])->save();

            $this->afterChange($activity);

            return $activity->refresh();
        });
    }

    public function delete(Activity $activity): void
    {
        DB::transaction(function () use ($activity): void {
            $activity->delete();

            $this->afterChange($activity);
        });
    }

    /**
     * Induk yang disebut harus ada. Kalimatnya menyebut jenis dokumennya dalam
     * bahasa layar ("Prospek", bukan "lead") — yang membacanya adalah orang
     * yang salah menempelkan sebuah id.
     */
    private function assertDocumentExists(string $type, int $id): void
    {
        if (ActivityDocuments::find($type, $id) !== null) {
            return;
        }

        $label = ActivityDocuments::label($type) ?? $type;

        throw ValidationException::withMessages([
            'document_id' => ["{$label} #{$id} tidak ditemukan, jadi aktivitas ini tidak bisa digantungkan padanya."],
        ]);
    }

    /** Turunan yang harus ikut bergerak setiap kali satu baris berubah. */
    private function afterChange(Activity $activity): void
    {
        $this->followUp->recomputeFor((string) $activity->document_type, (int) $activity->document_id);
    }
}
