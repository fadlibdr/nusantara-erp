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

    /**
     * Jawaban "pemberi #N memegang ability X sendiri?" untuk unit kerja ini.
     *
     * Tanpa ini, setiap pemeriksaan izin approve pada pengguna yang memegang
     * delegasi melakukan satu SELECT users — dan satu permintaan memeriksa
     * izin puluhan kali. Baris delegasinya sudah dimemo di atas; pemberinya
     * juga harus, kalau tidak memo itu hanya memindahkan kuerinya.
     *
     * @var array<string, bool>
     */
    private array $giverHolds = [];

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

    public function hasGiverAnswer(int $giverId, string $ability): bool
    {
        return array_key_exists($giverId.'|'.$ability, $this->giverHolds);
    }

    public function giverAnswer(int $giverId, string $ability): bool
    {
        return $this->giverHolds[$giverId.'|'.$ability] ?? false;
    }

    public function rememberGiver(int $giverId, string $ability, bool $holds): void
    {
        $this->giverHolds[$giverId.'|'.$ability] = $holds;
    }

    public function flush(): void
    {
        $this->rows = [];
        $this->giverHolds = [];
    }
}
