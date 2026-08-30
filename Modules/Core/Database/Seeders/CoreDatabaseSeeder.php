<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Company;
use Modules\Core\Models\MethodLibraryEntry;
use Modules\Core\Models\NumberSequence;

class CoreDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->updateOrCreate(
            ['name' => 'PT Nusantara Karya Integrasi'],
            [
                'legal_name' => 'PT Nusantara Karya Integrasi',
                'npwp' => '01.234.567.8-012.000', // dummy
                'nib' => '8120001234567',          // dummy
                'is_pkp' => true,
                'sppkp_number' => 'S-000/PKP/WPJ.00/2020', // dummy
                'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
                'city' => 'Jakarta Timur',
                'province' => 'DKI Jakarta',
                'postal_code' => '13910',
                'phone' => '021-4600888',
                'email' => 'info@nusantarakarya.co.id',
                'website' => 'https://nusantarakarya.co.id',
            ],
        );

        $this->seedMethodLibrary();
    }

    /**
     * P7 — dua entri pustaka metode kerja, dan salah satunya SUDAH DIREVISI.
     *
     * Yang kedua bukan hiasan: aturan yang paling mudah dilanggar diam-diam
     * pada paket ini adalah penawaran yang mengutip versi metode yang sudah
     * ditarik, dan demo tanpa satu pun rangkaian berversi tidak pernah
     * menunjukkan aturan itu bekerja. MTD/2026/0001 adalah versi 1 yang sudah
     * digantikan MTD/2026/0002; layar Penawaran hanya menawarkan yang kedua,
     * dan server menolak yang pertama dengan 422 yang menyebut penggantinya.
     *
     * Kode eksplisit + updateOrCreate pada `code` (pola seeder repo), lalu
     * penomorannya digeser lewat NumberSequence agar MTD berikutnya yang
     * dibangkitkan HasDocumentNumber tidak bertabrakan dengan kanon ini.
     */
    private function seedMethodLibrary(): void
    {
        $first = MethodLibraryEntry::withTrashed()->updateOrCreate(
            ['code' => 'MTD/2026/0001'],
            [
                'category' => 'struktur',
                'work_package' => 'Pekerjaan pondasi bore pile',
                'title' => 'Metode Pelaksanaan Bore Pile Ø600 — Rev. 0',
                'version' => 1,
                'summary' => 'Urutan kerja pengeboran, pembesian, dan pengecoran bore pile Ø600 '
                    .'termasuk pengendalian mutu slump dan benda uji.',
                'effective_date' => '2026-01-15',
            ],
        );

        $second = MethodLibraryEntry::withTrashed()->updateOrCreate(
            ['code' => 'MTD/2026/0002'],
            [
                'category' => 'struktur',
                'work_package' => 'Pekerjaan pondasi bore pile',
                'title' => 'Metode Pelaksanaan Bore Pile Ø600 — Rev. 1 (casing sementara)',
                'version' => 2,
                'summary' => 'Sama dengan Rev. 0, dengan penambahan casing sementara pada 6 m '
                    .'teratas untuk tanah lanau lepas.',
                'effective_date' => '2026-05-02',
            ],
        );

        // Entri ELV — dirujuk checklist paket tender demo sebagai metode
        // pelaksanaannya, dan satu-satunya entri berkategori bukan struktur.
        MethodLibraryEntry::withTrashed()->updateOrCreate(
            ['code' => 'MTD/2026/0003'],
            [
                'category' => 'elv',
                'work_package' => 'Instalasi backbone fiber optik & CCTV',
                'title' => 'Metode Pelaksanaan Instalasi Backbone FO dan CCTV',
                'version' => 1,
                'summary' => 'Penarikan kabel, terminasi, pengujian OTDR, commissioning NVR.',
                'effective_date' => '2026-03-01',
            ],
        );

        // Versi 1 digantikan versi 2 — ditulis langsung, bukan lewat
        // MethodLibraryService::publishRevision: publishRevision MEMBUAT baris
        // baru, dan seeder yang memanggilnya akan menerbitkan satu versi lagi
        // setiap kali dijalankan. Idempotensi menang di seeder.
        if ((int) $first->superseded_by_id !== (int) $second->id) {
            $first->forceFill(['superseded_by_id' => $second->id])->save();
        }

        $sequence = NumberSequence::query()->firstOrCreate(
            ['type' => 'MTD', 'year' => 2026],
            ['last_number' => 0],
        );

        if ((int) $sequence->last_number < 3) {
            $sequence->update(['last_number' => 3]);
        }
    }
}
