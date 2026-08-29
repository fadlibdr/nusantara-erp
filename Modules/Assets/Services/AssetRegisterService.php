<?php

namespace Modules\Assets\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;

/**
 * Mutasi register aset — update dan hapus, diputuskan pada baris yang DIBACA
 * ULANG di dalam transaksinya.
 *
 * Penjaga status di controller dulu membaca instance milik route binding:
 * pelepasan yang commit di antara binding dan tulisan tidak terlihat olehnya,
 * sehingga PUT status=available yang menyusul menghidupkan kembali aset yang
 * barusan di-derecognise — register bilang hidup, GL bilang sudah keluar,
 * dan keduanya tidak pernah bertemu lagi. DELETE pada jendela yang sama
 * menghapus baris yang jurnal pelepasannya baru saja menunjuk.
 *
 * lockForUpdate adalah no-op di SQLite; pembacaan ulangnya yang menjadi
 * penjaga, kuncinya yang membuat kode ini benar di server yang menghormatinya.
 */
class AssetRegisterService
{
    public function update(Asset $asset, array $data): Asset
    {
        return DB::transaction(function () use ($asset, $data): Asset {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            // Aset yang dilepas adalah buku yang sudah ditutup: harga
            // perolehan dan akumulasinya telah keluar dari GL lewat jurnal
            // pelepasan, jadi menyunting baris registernya sesudah itu
            // memisahkan register dari GL untuk selamanya.
            if ($asset->status === AssetStatus::Disposed) {
                throw new LogicException("Aset {$asset->code} sudah dihapusbukukan dan tidak dapat diubah lagi.");
            }

            if (array_key_exists('status', $data)
                && $asset->status === AssetStatus::Deployed
                && $data['status'] !== AssetStatus::Deployed->value) {
                throw new LogicException("Aset {$asset->code} sedang termobilisasi; kembalikan dari proyek terlebih dahulu.");
            }

            $this->assertFieldsMatchOwnership($asset, $data);

            $asset->update($data);

            // Nilai buku tersimpan mengikuti komponen biayanya — dan NULL
            // mengikuti NULL: alat sewa (P5) tidak punya harga perolehan,
            // jadi nilai bukunya bergaris, bukan Rp 0 hasil cast diam-diam.
            $asset->forceFill([
                'book_value' => $asset->acquisition_cost === null
                    ? null
                    : round((float) $asset->acquisition_cost - (float) $asset->accumulated_depreciation, 2),
            ])->save();

            return $asset->load('category');
        });
    }

    /**
     * P5 — bentuk kolom mengikuti kepemilikan, dua arah: aset sewa menolak
     * suntingan kolom perolehan (harga perolehannya memang tidak ada), aset
     * beli menolak kolom sewa (tarif sewanya memang tidak ada). Guard di
     * service, bukan hanya request, karena request update tidak tahu
     * kepemilikan barisnya — service yang membaca ulang barisnya yang tahu.
     */
    private function assertFieldsMatchOwnership(Asset $asset, array $data): void
    {
        $acquisitionFields = ['acquisition_date', 'acquisition_cost', 'salvage_value', 'useful_life_months', 'depreciation_start_date'];
        $rentalFields = ['vendor_id', 'rental_rate', 'rate_basis', 'rental_start', 'rental_end'];

        if ($asset->isRented()) {
            foreach ($acquisitionFields as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    throw new LogicException(
                        "Aset {$asset->code} adalah alat sewa; kolom perolehan/penyusutan tidak berlaku untuknya."
                    );
                }
            }

            return;
        }

        foreach ($rentalFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                throw new LogicException(
                    "Aset {$asset->code} milik sendiri; kolom sewa tidak berlaku untuknya."
                );
            }
        }
    }

    public function delete(Asset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if ($asset->status === AssetStatus::Deployed || $asset->activeDeployment()->exists()) {
                throw new LogicException("Aset {$asset->code} sedang termobilisasi dan tidak dapat dihapus.");
            }

            // Jurnal pelepasannya menunjuk baris ini; menghapusnya
            // meninggalkan entri GL yang menunjuk aset yang tidak ada.
            if ($asset->status === AssetStatus::Disposed) {
                throw new LogicException("Aset {$asset->code} sudah dihapusbukukan; riwayatnya harus tetap ada.");
            }

            if ($asset->depreciationEntries()->exists()) {
                throw new LogicException("Aset {$asset->code} sudah memiliki riwayat penyusutan; gunakan aksi hapus buku, bukan hapus.");
            }

            $asset->delete();
        });
    }
}
