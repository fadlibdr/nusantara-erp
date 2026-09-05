<?php

namespace Modules\Core\Models;

class NumberSequence extends BaseModel
{
    protected $table = 'core_number_sequences';

    /**
     * Baris penghitung (type, year, scope) — dibuat bila belum ada — dan
     * DIKUNCI eksklusif sampai transaksi pemanggil selesai. Dipakai
     * DocumentNumberService dan Finance\Support\BuktiPotongNumber; pemanggil
     * wajib berada di dalam DB::transaction().
     *
     * Dulu firstOrCreate() lalu lockForUpdate()->first(). Di MySQL itu pecah
     * pada NOMOR PERTAMA sebuah bucket baru (masa pajak baru, tahun baru, scope
     * {PROJ} baru) ketika beberapa permintaan datang bersamaan — diukur harness
     * burst T0.4, 5 Sep 2026: 4 persetujuan tagihan paralel di masa 2026-09
     * yang belum punya baris BP-202609 → 1 sukses, 3 × 500. Urutannya:
     * first() (bacaan konsisten, memasang read view REPEATABLE READ) → tidak
     * ada → INSERT → menunggu transaksi pertama commit → 1062 duplikat →
     * createOrFirst membaca ulang lewat read view LAMA → tetap tidak ada →
     * UniqueConstraintViolationException dilempar keluar.
     *
     * Kini satu pernyataan: INSERT ... ON DUPLICATE KEY UPDATE (SQLite: ON
     * CONFLICT DO UPDATE). Bila barisnya belum ada, ia lahir; bila ada,
     * pernyataan itu mengambil kunci X pada baris tersebut (MySQL memberi kunci
     * eksklusif, bukan berbagi, pada tabrakan kunci unik jalur ini) — jadi
     * permintaan kedua MENUNGGU permintaan pertama, bukan gagal. Bacaan
     * pengunci sesudahnya membaca versi commit terbaru, bukan snapshot, dan
     * mengembalikan baris yang sudah dikunci. Bukan SELECT ... FOR UPDATE lalu
     * INSERT: di REPEATABLE READ dua bacaan pengunci atas baris yang belum ada
     * sama-sama memegang gap lock, lalu dua INSERT saling menunggu = deadlock
     * 1213 — retry buta yang roadmap T0.4 larang.
     */
    public static function lockBucket(string $type, int $year, string $scope = ''): self
    {
        $key = ['type' => $type, 'year' => $year, 'scope' => $scope];

        static::query()->upsert([$key + ['last_number' => 0]], ['type', 'year', 'scope'], ['updated_at']);

        return static::query()->where($key)->lockForUpdate()->firstOrFail();
    }
}
