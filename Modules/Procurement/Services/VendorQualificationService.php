<?php

namespace Modules\Procurement\Services;

use Modules\Procurement\Enums\VendorStatus;
use Modules\Procurement\Exceptions\VendorNotQualifiedException;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorDocument;

/**
 * Gate prakualifikasi vendor (temuan #35).
 *
 * Dua fakta yang memblokir pengajuan PO/SPK: vendor berstatus nonaktif, dan
 * dokumen register yang DITANDAI wajib sudah lewat masa berlakunya. Tidak
 * lebih — register yang belum diisi bukan pelanggaran (memblokir seluruh
 * vendor pada hari pertama fitur terpasang berarti setiap PO butuh override,
 * dan gate seperti itu langsung dimatikan orang), dan dokumen kedaluwarsa
 * yang tidak wajib adalah catatan pembinaan, bukan blokade.
 *
 * Jalan daruratnya override BERALASAN — pembelian darurat ke pemegang lisensi
 * tunggal tidak boleh menunggu perpanjangan SBU — dan pemanggil menyimpan
 * alasan itu di dokumennya (kolom qualification_override_reason PO), supaya
 * jejaknya terbaca auditor, bukan hilang di percakapan.
 *
 * Dipanggil dari PurchaseOrderController::submit; PoService::create/
 * createFromPr dan SubcontractService::create memanggil assertQualified()
 * yang sama, supaya PO/SPK tidak pernah lahir untuk vendor yang pengajuannya
 * pasti ditolak.
 */
class VendorQualificationService
{
    /**
     * Daftar alasan vendor ini TIDAK lolos prakualifikasi — kosong bila lolos.
     *
     * @return list<string>
     */
    public function blockers(Vendor $vendor): array
    {
        $blockers = [];

        if ($vendor->status !== VendorStatus::Active) {
            $blockers[] = 'vendor berstatus nonaktif';
        }

        // "Berlaku s/d" masih sah PADA hari terakhirnya, jadi kedaluwarsa
        // adalah valid_until < hari ini — perbandingan string setengah-terbuka
        // yang menelan kedua bentuk simpanan SQLite ("2026-06-30" maupun
        // "2026-06-30 00:00:00"), footgun yang sama yang didokumentasikan
        // WatchedDeadlines. NULL = tidak kedaluwarsa (pola hr_certificates).
        $expired = $vendor->documents()
            ->where('is_mandatory', true)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now()->toDateString())
            ->orderBy('valid_until')
            ->get();

        foreach ($expired as $document) {
            /** @var VendorDocument $document */
            $blockers[] = "dokumen wajib {$document->name} kedaluwarsa sejak "
                .$document->valid_until->format('d-m-Y');
        }

        return $blockers;
    }

    /**
     * Lolos, atau VendorNotQualifiedException yang menyebut semua penyebabnya.
     *
     * Alasan override yang tidak kosong meloloskan vendor terblokir; daftar
     * blokir yang dilewati dikembalikan supaya pemanggil tahu overridenya
     * benar-benar DIPAKAI (dan hanya saat itu menyimpan alasannya) — alasan
     * yang diketik untuk vendor sehat bukan jejak override, melainkan salah
     * paham formulir.
     *
     * @return list<string> blokir yang dilewati override; kosong bila memang lolos
     *
     * @throws VendorNotQualifiedException
     */
    public function assertQualified(Vendor $vendor, ?string $overrideReason = null): array
    {
        $blockers = $this->blockers($vendor);

        if ($blockers === []) {
            return [];
        }

        if ($overrideReason !== null && trim($overrideReason) !== '') {
            return $blockers;
        }

        throw VendorNotQualifiedException::make($vendor, $blockers);
    }
}
