<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\RateHistoryEntry;

/**
 * P8 — perekam riwayat tarif (D5). MEREKAM SAJA.
 *
 * Dipanggil dari satu-satunya jalur tulis Pengaturan (SettingService::set)
 * ketika kunci yang berubah adalah tarif PPN atau PPh final — asumsi roadmap:
 * hanya dua keluarga itu. Tidak ada method di kelas ini yang menghitung angka
 * dokumen dari riwayat, dan tidak boleh pernah ada: snapshot per dokumen tetap
 * sumber kebenaran, tabel ini hanya menjawab "tarifnya berubah kapan, dari
 * berapa, oleh siapa". RateHistoryTest memaku sikap itu.
 */
class RateHistoryService
{
    /** Kunci tarif yang direkam, persis (PPN) dan per awalan (PPh final). */
    private const TRACKED_KEYS = [
        'tax.ppn_rate',
        'tax.ppn_headline_rate',
        'tax.pph_final_umkm_rate',
    ];

    private const TRACKED_PREFIX = 'tax.pph_final_construction.';

    public function tracks(string $key): bool
    {
        return in_array($key, self::TRACKED_KEYS, true)
            || str_starts_with($key, self::TRACKED_PREFIX);
    }

    /**
     * Satu baris per perubahan NYATA: tulisan yang meninggalkan tarif efektif
     * di angka yang sama (menyimpan 11 di atas default 11) bukan perubahan dan
     * tidak direkam — riwayat penuh baris "11 → 11" hanya menenggelamkan
     * perubahan yang sungguh terjadi.
     */
    public function record(string $key, mixed $oldEffective, mixed $newEffective, ?int $changedBy): void
    {
        if (! $this->tracks($key)) {
            return;
        }

        // Pola overrides(): jalur seed/instalasi lama boleh menulis Pengaturan
        // sebelum tabel riwayat bermigrasi; jangan meledak di sana.
        if (! Schema::hasTable('core_rate_history')) {
            return;
        }

        $old = $oldEffective === null ? null : (float) $oldEffective;
        $new = $newEffective === null ? null : (float) $newEffective;

        if ($old === $new) {
            return;
        }

        RateHistoryEntry::query()->create([
            'rate_key' => $key,
            'old_rate' => $old,
            'new_rate' => $new,
            'changed_by' => $changedBy,
        ]);
    }
}
