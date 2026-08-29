<?php

namespace Modules\Procurement\Services;

use Modules\Procurement\Enums\VendorDocumentType;
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
            /*
             * Untuk vendor yang MENGIRIM PEKERJA KE SITE (P4: vendor_type
             * subcontractor ATAU mandor — dulu is_subcontractor), dua jenis
             * K3L/pakta sepenuhnya milik klausul penyempitan di bawah — absen
             * maupun kedaluwarsa, buta tanda is_mandatory, dan baris TERSEGAR
             * yang menang. Tanpa pengecualian ini satu lembar K3L
             * wajib-kedaluwarsa disebut DUA KALI dalam satu pesan, dan subkon
             * yang MEMPERBARUI K3L-nya (baris segar ditambah, baris basi
             * dibiarkan) tetap terblokir oleh baris basinya. Vendor
             * material/rental tidak berubah: baris K3L mereka tunduk aturan
             * lama seperti dokumen wajib lain.
             */
            ->when($this->sendsWorkersToSite($vendor), fn ($query) => $query->whereNotIn('doc_type', [
                VendorDocumentType::K3lCommitment->value,
                VendorDocumentType::PaktaIntegritas->value,
            ]))
            ->orderBy('valid_until')
            ->get();

        foreach ($expired as $document) {
            /** @var VendorDocument $document */
            $blockers[] = "dokumen wajib {$document->name} kedaluwarsa sejak "
                .$document->valid_until->format('d-m-Y');
        }

        /*
         * PENYEMPITAN SADAR (P0-E) atas filosofi "absen bukan pelanggaran" di
         * atas: khusus vendor SUBKONTRAKTOR — dan sejak P4 juga MANDOR —
         * komitmen K3L dan pakta integritas wajib HADIR dan belum kedaluwarsa
         * — apa pun tanda is_mandatory barisnya. Orang yang mengirim
         * pekerjanya ke site tanpa komitmen K3L bukan register yang belum
         * rapi; itulah risiko yang gerbang ini ada untuk menahan. Vendor
         * material murni tidak tersentuh, dan override beralasan tetap
         * menjadi jalan daruratnya — mobilisasi besok pagi tidak menunggu
         * tanda tangan hari ini.
         *
         * KEPUTUSAN P4 YANG DIDOKUMENTASIKAN (roadmap diam soal mandor):
         * mandor IKUT dikenai penyempitan ini. Alasan P0-E di paragraf atas —
         * "mengirim pekerjanya ke site" — adalah persis seluruh bisnis
         * seorang mandor borongan; membebaskannya berarti gerbang menahan CV
         * subkon ber-akta tetapi meloloskan rombongan tukang tanpa komitmen
         * K3L apa pun. F/CVM (P4) menambah CV Mandor sebagai lembar
         * KUALIFIKASI mandor, bukan pengganti komitmen keselamatan — CV
         * menjawab "siapa dia", K3L menjawab "bagaimana orang-orangnya
         * bekerja dengan selamat"; dua pertanyaan, dua dokumen. Sejak P4
         * klausul ini membaca vendor_type (kode baru membaca vendor_type;
         * is_subcontractor deprecated), dan perilakunya untuk
         * vendor_type=subcontractor identik dengan perilaku lama untuk
         * is_subcontractor=true — VendorTypeTest memakukan keduanya.
         */
        if ($this->sendsWorkersToSite($vendor)) {
            $required = [
                VendorDocumentType::K3lCommitment,
                VendorDocumentType::PaktaIntegritas,
            ];

            foreach ($required as $type) {
                $document = $vendor->documents()
                    ->where('doc_type', $type->value)
                    ->orderByRaw('valid_until IS NULL DESC')
                    ->orderByDesc('valid_until')
                    ->first();

                // Nama dalam-kalimat: akronim mempertahankan kapitalnya
                // ('komitmen K3L', bukan 'komitmen k3l' hasil strtolower).
                $inSentence = match ($type) {
                    VendorDocumentType::K3lCommitment => 'komitmen K3L',
                    VendorDocumentType::PaktaIntegritas => 'pakta integritas',
                };

                if ($document === null) {
                    $blockers[] = "subkontraktor tanpa dokumen {$inSentence}"
                        .' — wajib ada sebelum SPK/PO diajukan';

                    continue;
                }

                $validUntil = $document->valid_until;

                if ($validUntil !== null && $validUntil->toDateString() < now()->toDateString()) {
                    $blockers[] = "dokumen {$inSentence}"
                        ." kedaluwarsa sejak {$validUntil->format('d-m-Y')}";
                }
            }
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

    /**
     * Siapa yang dikenai penyempitan K3L/pakta P0-E — dibaca dari
     * vendor_type (P4). Fallback ke is_subcontractor hanya untuk baris yang
     * belum pernah tersentuh model sejak kolomnya lahir (hook sinkron di
     * Vendor::booted mengisi vendor_type pada setiap simpan), supaya vendor
     * lama yang belum pernah di-load tetap dinilai dengan aturan yang sama.
     */
    private function sendsWorkersToSite(Vendor $vendor): bool
    {
        if ($vendor->vendor_type !== null) {
            return $vendor->vendor_type->sendsWorkersToSite();
        }

        return (bool) $vendor->is_subcontractor;
    }
}
