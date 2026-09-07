<?php

namespace Modules\Core\Support;

/**
 * Potret delegasi hidup untuk SATU unit kerja (F-1).
 *
 * Gate::before berjalan pada setiap pemeriksaan izin, dan satu permintaan
 * memeriksa izin puluhan kali; tanpa memo, setiap pemeriksaan approve adalah
 * satu SELECT. Tetapi memo statis adalah cacat yang berbeda: pekerja antrean
 * adalah proses yang hidup berjam-jam, dan sebuah delegasi yang dicabut pukul
 * sepuluh akan tetap memberi hak sampai pekerjanya direstart.
 *
 * Maka memonya adalah objek yang di-bind scoped() di CoreServiceProvider —
 * pola yang sama persis dengan SettingService, dan batas yang sama: kontainer
 * membuangnya di akhir tiap permintaan HTTP dan sebelum tiap job antrean, jadi
 * satu unit kerja membaca satu potret dari awal sampai akhir dan unit
 * berikutnya membaca ulang.
 */
final class ApprovalDelegationMemo
{
    /** @var array<int, list<array<string, mixed>>> */
    private array $rows = [];

    public function has(int $userId): bool
    {
        return array_key_exists($userId, $this->rows);
    }

    /** @return list<array<string, mixed>> */
    public function get(int $userId): array
    {
        return $this->rows[$userId] ?? [];
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function put(int $userId, array $rows): void
    {
        $this->rows[$userId] = $rows;
    }

    public function flush(): void
    {
        $this->rows = [];
    }
}
