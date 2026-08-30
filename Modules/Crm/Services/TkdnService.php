<?php

namespace Modules\Crm\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Erp;
use Modules\Crm\Enums\TkdnCostGroup;
use Modules\Crm\Enums\TkdnNationality;
use Modules\Crm\Enums\TkdnOrigin;
use Modules\Crm\Enums\TkdnOwnership;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Models\TkdnWorksheet;
use Modules\Crm\Models\TkdnWorksheetItem;

/**
 * P7 — TKDN Jasa: the arithmetic, and where every number in it comes from.
 *
 * ============================================================================
 * SUMBER (dibaca, bukan diingat)
 *
 *   Peraturan Menteri Perindustrian Republik Indonesia Nomor 35 Tahun 2025
 *   tentang Ketentuan dan Tata Cara Sertifikasi Tingkat Komponen Dalam Negeri
 *   dan Bobot Manfaat Perusahaan.
 *   Ditetapkan di Jakarta pada tanggal 11 September 2025.
 *
 *   Tanggal itu TERVERIFIKASI: `pdftotext -layout` atas salinan resmi di bawah
 *   mengeluarkan "Ditetapkan di Jakarta / pada tanggal 11 September 2025" pada
 *   halaman tanda tangan. Sebuah pemeriksaan sebelumnya gagal menariknya keluar
 *   dan menyimpulkan tanggalnya tidak dapat diverifikasi; ekstraksi yang gagal
 *   bukan ketiadaan, dan fakta terverifikasi tidak dibuang karenanya. (Tanggal
 *   DIUNDANGKAN memang tidak terbaca — kolomnya kosong pada salinan ini — dan
 *   karena itu tidak dikutip di mana pun.)
 *
 *   Salinan resmi yang dibaca saat menulis kelas ini:
 *     https://peraturan.go.id/files/permenperin-no-35-tahun-2025.pdf
 *     (halaman peraturannya: https://peraturan.go.id/id/permenperin-no-35-tahun-2025
 *      dan https://peraturan.bpk.go.id/Details/333003/permenperin-no-35-tahun-2025)
 *
 * MENGAPA BUKAN RUMUS LAMA. Pasal 74 huruf a Permen ini MENCABUT Peraturan
 * Menteri Perindustrian Nomor 16/M-IND/PER/2/2011 tentang Ketentuan dan Tata
 * Cara Penghitungan Tingkat Komponen Dalam Negeri — beserta Permenperin
 * 02/2014, 03/2014 dan 46/2022. Rumus TKDN "yang biasa beredar" adalah
 * keturunan aturan 2011 itu; sejak Permen 35/2025 ia bukan lagi rumus yang
 * berlaku, dan sebuah angka TKDN pada dokumen penawaran adalah klaim hukum.
 *
 * ---------------------------------------------------------------- PASAL 14
 *
 * Pasal 14 ayat (1): "Penghitungan nilai TKDN Jasa Industri dilakukan
 * berdasarkan perbandingan antara biaya Jasa Industri keseluruhan dikurangi
 * biaya Jasa Industri luar negeri terhadap biaya Jasa Industri keseluruhan."
 *
 *      TKDN Jasa = (biaya keseluruhan − biaya luar negeri) / biaya keseluruhan
 *
 * Pasal 14 ayat (2): biaya keseluruhan adalah biaya yang dikeluarkan untuk
 * menghasilkan jasa itu, "dihitung sampai di lokasi pengerjaan".
 *
 * Pasal 14 ayat (3): biaya tersebut meliputi biaya (a) tenaga kerja;
 * (b) alat kerja/fasilitas kerja; dan (c) Jasa umum. Tiga, dan hanya tiga —
 * TkdnCostGroup.
 *
 * ------------------------------------------- LAMPIRAN IV huruf B (faktor KDN)
 *
 * B.1 Tenaga kerja, dinilai berdasarkan KEWARGANEGARAAN:
 *       Warga Negara Indonesia    KDN 100%
 *       Warga Negara Asing        KDN   0%
 *
 * B.2 Alat kerja/fasilitas kerja, dinilai berdasarkan KEPEMILIKAN dan NEGARA
 *     ASAL (penyusutan alat yang di akhir pekerjaan tetap milik penyedia):
 *       Dibuat DN — Dimiliki DN              KDN 100%
 *       Dibuat DN — Dimiliki DN + LN         KDN 100%
 *       Dibuat DN — Dimiliki LN              KDN 100%
 *       Dibuat LN — Dimiliki DN              KDN  50%
 *       Dibuat LN — Dimiliki DN + LN         KDN  50% × proporsional saham DN
 *       Dibuat LN — Dimiliki LN              KDN   0%
 *
 * B.3 Jasa umum, dinilai berdasarkan ASAL PENYEDIA:
 *       Penyedia jasa dalam negeri KDN 100%
 *       Penyedia jasa luar negeri  KDN   0%
 *
 * "Biaya luar negeri" Pasal 14 ayat (1) karenanya = biaya × (1 − faktor KDN),
 * dan kedua perumusan itu memberi angka yang sama. Kelas ini menghitung lewat
 * faktor karena baris 50% dan baris "50% × saham" tidak punya bentuk lain.
 *
 * -------------------------------------------------- AKUMULASI KE TINGKAT PAKET
 *
 * Persentase paket di sini adalah Pasal 14 yang diterapkan pada SELURUH baris
 * biaya lembar ini sekaligus: (Σ biaya − Σ biaya LN) / Σ biaya. Secara
 * aritmetika itu identik dengan merata-ratakan TKDN per baris penawaran dengan
 * BOBOT BIAYA masing-masing — jadi ia adalah akumulasi berbobot, bukan
 * rata-rata polos persentase (uji
 * test_the_package_percentage_is_cost_weighted_not_a_plain_average memakukan
 * selisihnya: 80,00% versus 50,00% pada data yang sama).
 *
 * Bobot BIAYA, bukan bobot HARGA, dan itu perbedaan yang disengaja.
 * Pasal 18 / Lampiran IV huruf C menimbang TKDN gabungan Barang dan TKDN Jasa
 * dengan "proporsi nilai perolehan" — harga — tetapi itu adalah aturan untuk
 * MENGGABUNGKAN dua sertifikat yang masing-masing sudah jadi, bukan aturan
 * untuk menghitung TKDN Jasa dari uraian biayanya. Di dalam satu jasa, Pasal 14
 * berbicara tentang BIAYA. Menimbang dengan harga di sini akan menyelipkan
 * margin ke dalam penyebut sebuah rasio biaya.
 *
 * -------------------------------------------------------- YANG TIDAK DIBANGUN
 *
 * TKDN GABUNGAN BARANG DAN JASA (Pasal 18–20) TIDAK dihitung di sini. Sebuah
 * pekerjaan konstruksi umumnya adalah gabungan Barang dan Jasa, dan angka
 * gabungannya butuh nilai TKDN tiap BARANG — yang menurut Lampiran IV huruf A
 * datang dari Sertifikat TKDN barang itu, bukan dari uraian biaya kita. Tidak
 * ada tabel sertifikat TKDN barang di ERP ini, jadi tidak ada cara jujur
 * mengarangnya, dan sebuah kolom "TKDN barang" yang boleh diketik adalah persis
 * mesin pemalsu yang kelas ini dibuat untuk menghindari. Lembar ini menjawab
 * sisi JASA-nya, menyebut dirinya begitu, dan menyisakan sisi barangnya untuk
 * keputusan pemilik (lihat laporan paket, "PERLU KONFIRMASI PEMILIK").
 *
 * Pasal 17 berbunyi, kata demi kata: "Penghitungan nilai TKDN Jasa Industri
 * ditetapkan oleh Sekretaris Jenderal." Yang didelegasikan bukan panduan
 * tentang penghitungannya melainkan PENGHITUNGANNYA SENDIRI — dan itu adalah
 * pendelegasian yang lebih luas daripada bunyi Pasal 13, yang menyerahkan
 * "Petunjuk teknis penghitungan nilai TKDN Barang" kepada pejabat yang sama.
 * Pasal 13 berbicara tentang Barang; kata "Petunjuk teknis" adalah miliknya,
 * dan meminjamnya ke Pasal 17 akan mengecilkan pendelegasian Jasa menjadi
 * sekadar panduan. Yang dikodekan di sini adalah Pasal 14 dan Lampiran IV
 * huruf B apa adanya; keputusan Sekretaris Jenderal itu TIDAK diperoleh, jadi
 * bila ia menetapkan penghitungan yang berbeda dari salah satu baris di bawah,
 * baris itulah yang berubah — beserta uji yang memakukannya.
 * ============================================================================
 */
class TkdnService
{
    public function createWorksheet(array $data, ?User $by = null): TkdnWorksheet
    {
        $quotation = Quotation::query()->findOrFail((int) $data['quotation_id']);

        $existing = TkdnWorksheet::query()->where('quotation_id', $quotation->id)->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'quotation_id' => ["Penawaran {$quotation->code} sudah memiliki lembar TKDN ({$existing->code})."],
            ]);
        }

        $worksheet = new TkdnWorksheet(Arr::only($data, ['quotation_id', 'tender_package_id', 'notes']));
        $worksheet->created_by = $by?->id;
        $worksheet->save(); // HasDocumentNumber fills the TKD code

        return $worksheet;
    }

    public function updateWorksheet(TkdnWorksheet $worksheet, array $data): TkdnWorksheet
    {
        // Lembar tidak pindah penawaran: uraian biayanya menguraikan baris
        // penawaran ITU.
        $worksheet->fill(Arr::only($data, ['tender_package_id', 'notes']))->save();

        return $worksheet;
    }

    /**
     * Ganti seluruh baris biaya lembar ini (pola baris dokumen repo: diganti
     * utuh, di dalam satu transaksi).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function replaceItems(TkdnWorksheet $worksheet, array $rows): TkdnWorksheet
    {
        $allowed = $worksheet->quotation?->items->pluck('id')->all() ?? [];

        $prepared = [];

        foreach (array_values($rows) as $index => $row) {
            $prepared[] = $this->prepareRow($worksheet, $row, $index, $allowed);
        }

        DB::transaction(function () use ($worksheet, $prepared): void {
            $worksheet->items()->delete();

            foreach ($prepared as $attributes) {
                $worksheet->items()->create($attributes);
            }
        });

        return $worksheet->load('items');
    }

    /**
     * Faktor komponen dalam negeri satu baris, 0..1 — Lampiran IV huruf B.
     *
     * Tabel B.2 dibaca apa adanya: alat BUATAN DALAM NEGERI adalah 100% KDN
     * berapa pun kepemilikannya (tiga baris pertama), dan hanya alat buatan
     * luar negeri yang kepemilikannya mengubah jawaban.
     */
    public function domesticFactor(TkdnWorksheetItem $row): float
    {
        return match ($row->cost_group) {
            TkdnCostGroup::TenagaKerja => $row->nationality?->domesticFactor() ?? 0.0,
            TkdnCostGroup::JasaUmum => $row->provider_origin === TkdnOrigin::Dn ? 1.0 : 0.0,
            TkdnCostGroup::AlatKerja => $this->toolFactor($row),
            default => 0.0,
        };
    }

    /**
     * Lembar ini sebagai angka — dan SELALU bersama cakupannya.
     *
     * ATURAN YANG PALING PENTING DI BERKAS INI: baris penawaran tanpa satu pun
     * baris biaya BELUM DINILAI. Ia tidak masuk pembilang maupun penyebut
     * persentase, dan nilainya dilaporkan terpisah sebagai unassessed_value.
     * Menghitungnya 0% akan menurunkan angka yang kita klaim tanpa ada yang
     * pernah memeriksa barisnya; menghitungnya 100% akan menaikkannya. Keduanya
     * bohong, dan yang kedua bohong ke arah yang menguntungkan kita — yang
     * justru jenis kebohongan yang tidak pernah ada yang melaporkannya.
     *
     * Karena itu tkdn_pct dan coverage_pct selalu berjalan berpasangan, dan
     * lembar cetak wajib memuat keduanya. tkdn_pct null (bukan 0) bila tidak
     * ada satu pun baris yang dinilai: sel bergaris, bukan angka nol.
     *
     * ------------------------------------------- TIGA KEADAAN, BUKAN DUA
     *
     * Aturan di atas dahulu diukur dengan uji KEBERADAAN — "baris ini punya
     * setidaknya satu baris biaya" — dan uji keberadaan bisa dikalahkan dengan
     * Rp 1. Satu baris biaya Rp 1 pada baris penawaran Rp 100 juta menjawab
     * cakupan 100,00% dan fully_assessed true: lembar yang terbaca bersih dan
     * tidak pernah ada yang menguraikan isinya. Karena itu cakupan sekarang
     * membandingkan BESARAN biaya yang diuraikan dengan NILAI baris penawaran
     * itu sendiri, dan setiap baris berada di salah satu dari tiga keadaan:
     *
     *   belum     tidak ada baris biaya sama sekali
     *   sebagian  ada, tetapi jumlahnya di bawah ambang rumah terhadap
     *             nilai barisnya — DIUNGKAPKAN, tidak ditolak
     *   penuh     ada dan mencapai ambang itu
     *
     * MENGAPA MENGUNGKAPKAN DAN BUKAN MENOLAK. Permenperin 35/2025 tidak
     * menyebut satu pun pecahan antara biaya dan nilai penawaran: Pasal 14
     * berbicara tentang biaya keseluruhan, sementara nilai baris penawaran
     * adalah HARGA, yang memuat margin yang tidak diatur peraturan mana pun.
     * Sebuah ambang karenanya tidak bisa disandarkan pada Permen — dan paket
     * ini sudah menolak mengarang angka di tempat lain (ambang TKDN minimum
     * tidak ditegakkan karena sumbernya tidak dibaca). Jadi ambangnya berdiri
     * sebagai ANGKA RUMAH yang diumumkan sendiri di dalam muatan
     * (min_cost_to_value_pct, basis_cakupan), dipegang pemilik di
     * config('erp.tender.tkdn_min_cost_to_value_pct'), dan ia TIDAK PERNAH
     * menolak simpanan: replaceItems() tetap menerima baris Rp 1, karena
     * lembar yang sedang dikerjakan separuh jalan memang belum lengkap dan
     * menolaknya akan mengusir pekerjaan yang sah. Yang tidak boleh terjadi
     * hanyalah satu hal: baris seperti itu MENGAKU dinilai penuh.
     *
     * Biaya baris "sebagian" tetap masuk pembilang dan penyebut tkdn_pct — ia
     * biaya nyata yang benar-benar diuraikan seseorang — tetapi NILAI baris
     * penawarannya tidak masuk cakupan, dan berdiri sendiri sebagai
     * partially_assessed_value di sebelahnya. Ketiga ember itu menjumlah persis
     * quotation_value: assessed + partially_assessed + unassessed.
     *
     * @return array<string, mixed>
     */
    public function summary(TkdnWorksheet $worksheet): array
    {
        $quotation = $worksheet->quotation;
        $quotationItems = $quotation?->items ?? collect();
        // Pakai relasi yang SUDAH dimuat bila ada: daftar lembar TKDN memanggil
        // summary() sekali per baris, dan query per baris di dalam sebuah
        // Resource adalah N+1 yang tidak terlihat sampai datanya banyak.
        $rows = $worksheet->relationLoaded('items') ? $worksheet->items : $worksheet->items()->get();
        $byQuotationItem = $rows->groupBy('quotation_item_id');

        $minCostPct = Erp::float('tender.tkdn_min_cost_to_value_pct', 50.0);

        $items = [];
        $costTotal = 0.0;
        $costDomestic = 0.0;
        $quotationValue = 0.0;
        $assessedValue = 0.0;
        $partialValue = 0.0;
        $unassessedValue = 0.0;
        $unassessed = [];
        $partial = [];

        foreach ($quotationItems as $quotationItem) {
            $amount = (float) $quotationItem->amount;
            $quotationValue += $amount;

            $itemRows = $byQuotationItem->get($quotationItem->id, collect());
            $assessed = $itemRows->isNotEmpty();

            $itemCost = 0.0;
            $itemDomestic = 0.0;

            foreach ($itemRows as $row) {
                $rowAmount = (float) $row->amount;
                $itemCost += $rowAmount;
                $itemDomestic += $rowAmount * $this->domesticFactor($row);
            }

            if ($assessed) {
                $costTotal += $itemCost;
                $costDomestic += $itemDomestic;
            }

            $state = $this->assessmentState($assessed, $itemCost, $amount, $minCostPct);

            $bucket = [
                'quotation_item_id' => $quotationItem->id,
                'description' => $quotationItem->description,
                'amount' => $amount,
            ];

            if ($state === 'penuh') {
                $assessedValue += $amount;
            } elseif ($state === 'sebagian') {
                $partialValue += $amount;
                // Ember ini membawa biayanya sekalian: "Rp 100 juta baru
                // diuraikan Rp 1" adalah kalimat yang perlu dibaca utuh, bukan
                // dua angka dari dua tempat.
                $partial[] = $bucket + [
                    'cost_total' => round($itemCost, 2),
                    'cost_to_value_pct' => $this->costToValuePct($itemCost, $amount),
                ];
            } else {
                $unassessedValue += $amount;
                $unassessed[] = $bucket;
            }

            $items[] = [
                'quotation_item_id' => $quotationItem->id,
                'description' => $quotationItem->description,
                'amount' => $amount,
                // "assessed" tetap berarti KEBERADAAN uraian biaya — layar
                // memakainya untuk memutuskan mencetak persen atau menggarisi
                // sel, dan baris "sebagian" punya persen yang sah. Penilaian
                // cakupannya ada di "assessment", tiga keadaan.
                'assessed' => $assessed,
                'assessment' => $state,
                'cost_total' => round($itemCost, 2),
                'cost_domestic' => round($itemDomestic, 2),
                'cost_foreign' => round($itemCost - $itemDomestic, 2),
                'cost_to_value_pct' => $this->costToValuePct($itemCost, $amount),
                // Biaya nol yang benar-benar tercatat tetap "dinilai", tetapi
                // rasionya tidak terdefinisi — dan sel yang tidak terdefinisi
                // bergaris, tidak nol.
                'tkdn_pct' => $assessed && $itemCost > 0.0
                    ? round($itemDomestic / $itemCost * 100, 2)
                    : null,
            ];
        }

        $describedValue = $assessedValue + $partialValue;

        return [
            'worksheet_id' => $worksheet->id,
            'worksheet_code' => $worksheet->code,
            'quotation_id' => $worksheet->quotation_id,
            'quotation_code' => $quotation?->code,
            'basis' => 'TKDN Jasa — Permenperin 35/2025 Pasal 14 & Lampiran IV huruf B',
            // Cakupannya punya dasarnya sendiri, dan dasarnya BUKAN peraturan.
            // Angka rumah yang menumpang nama Permen adalah persis pemalsuan
            // yang kelas ini dibuat untuk menghindari.
            'basis_cakupan' => 'Ambang cakupan biaya per baris adalah ambang rumah, bukan ketentuan '
                .'Permenperin 35/2025 — peraturan itu tidak menyebut pecahan apa pun antara biaya dan '
                .'nilai penawaran. Ambang ini mengungkapkan, tidak menolak.',
            'cost_total' => round($costTotal, 2),
            'cost_domestic' => round($costDomestic, 2),
            'cost_foreign' => round($costTotal - $costDomestic, 2),
            'tkdn_pct' => $costTotal > 0.0 ? round($costDomestic / $costTotal * 100, 2) : null,
            'quotation_value' => round($quotationValue, 2),
            // Biaya yang diuraikan terhadap nilai baris-baris yang menguraikannya.
            // Inilah angka yang berteriak pada baris Rp 1: 0,00%.
            'cost_to_value_pct' => $this->costToValuePct($costTotal, $describedValue),
            'min_cost_to_value_pct' => $minCostPct,
            'assessed_value' => round($assessedValue, 2),
            'partially_assessed_value' => round($partialValue, 2),
            'unassessed_value' => round($unassessedValue, 2),
            'coverage_pct' => $quotationValue > 0.0 ? round($assessedValue / $quotationValue * 100, 2) : 0.0,
            'fully_assessed' => $quotationItems->isNotEmpty() && $unassessed === [] && $partial === [],
            'unassessed_items' => $unassessed,
            'partially_assessed_items' => $partial,
            'items' => $items,
        ];
    }

    // ------------------------------------------------------------- internals

    /**
     * Rasio biaya yang diuraikan terhadap nilai yang diuraikannya, 0..∞.
     *
     * Penyebut nol tidak menjawab nol: baris penawaran bernilai Rp 0 tidak
     * punya rasio, dan sel yang tidak terdefinisi bergaris — aturan yang sama
     * yang dipakai tkdn_pct pada biaya nol.
     */
    private function costToValuePct(float $cost, float $value): ?float
    {
        return $value > 0.0 ? round($cost / $value * 100, 2) : null;
    }

    /**
     * Keadaan cakupan satu baris penawaran: belum, sebagian, atau penuh.
     *
     * Baris bernilai Rp 0 yang punya uraian biaya dihitung "penuh": ia
     * menyumbang nol ke kedua sisi cakupan, jadi menandainya "sebagian" hanya
     * akan membunyikan alarm yang tidak menggerakkan satu angka pun — dan
     * penjaga yang berbunyi tanpa sebab adalah penjaga yang diabaikan.
     */
    private function assessmentState(bool $assessed, float $cost, float $value, float $minCostPct): string
    {
        if (! $assessed) {
            return 'belum';
        }

        if ($value <= 0.0) {
            return 'penuh';
        }

        // round() pada ambangnya, bukan pada rasionya: membandingkan rupiah
        // dengan rupiah menghindari baris yang jatuh di sisi salah karena
        // pembulatan persen ke dua desimal. Preseden: EvmService.
        return $cost >= round($value * $minCostPct / 100, 2) ? 'penuh' : 'sebagian';
    }

    private function toolFactor(TkdnWorksheetItem $row): float
    {
        if ($row->made_in === TkdnOrigin::Dn) {
            return 1.0; // tiga baris pertama tabel B.2, apa pun kepemilikannya
        }

        return match ($row->owned_by) {
            TkdnOwnership::Dn => 0.5,
            TkdnOwnership::Campuran => 0.5 * ((float) $row->domestic_share_pct / 100),
            default => 0.0,
        };
    }

    /**
     * @param  array<int, int>  $allowedQuotationItemIds
     * @return array<string, mixed>
     */
    private function prepareRow(TkdnWorksheet $worksheet, array $row, int $index, array $allowedQuotationItemIds): array
    {
        $line = $index + 1;

        $group = TkdnCostGroup::tryFrom((string) ($row['cost_group'] ?? ''));

        if ($group === null) {
            throw ValidationException::withMessages([
                "items.{$index}.cost_group" => ["Baris {$line}: kelompok biaya harus tenaga_kerja, alat_kerja, atau jasa_umum."],
            ]);
        }

        $quotationItemId = (int) ($row['quotation_item_id'] ?? 0);

        if (! in_array($quotationItemId, array_map('intval', $allowedQuotationItemIds), true)) {
            throw ValidationException::withMessages([
                "items.{$index}.quotation_item_id" => [
                    "Baris {$line}: baris penawaran tidak dikenali pada penawaran lembar ini.",
                ],
            ]);
        }

        $amount = (float) ($row['amount'] ?? 0);

        if ($amount < 0) {
            throw ValidationException::withMessages([
                "items.{$index}.amount" => ["Baris {$line}: biaya komponen tidak boleh negatif."],
            ]);
        }

        $attributes = [
            'quotation_item_id' => $quotationItemId,
            'sort_order' => (int) ($row['sort_order'] ?? $line),
            'cost_group' => $group->value,
            'description' => (string) ($row['description'] ?? ''),
            'amount' => $amount,
            'nationality' => null,
            'made_in' => null,
            'owned_by' => null,
            'domestic_share_pct' => null,
            'provider_origin' => null,
        ];

        // array_merge, NOT `+`: the left operand of `+` wins on duplicate keys,
        // so the nulls above would have swallowed every determinant column and
        // every row would have computed as 0% domestic.
        return array_merge($attributes, $this->groupAttributes($group, $row, $index, $line));
    }

    /**
     * Kolom penentu KDN untuk kelompoknya — dan penolakan bila tak lengkap.
     *
     * Tidak ada bawaan diam-diam di sini. Baris tenaga kerja tanpa
     * kewarganegaraan bukan "anggap saja WNI": ia adalah baris yang belum
     * dinilai, dan menyimpannya berarti mengunci tebakan ke dalam penyebut
     * sebuah klaim hukum.
     *
     * @return array<string, mixed>
     */
    private function groupAttributes(TkdnCostGroup $group, array $row, int $index, int $line): array
    {
        if ($group === TkdnCostGroup::TenagaKerja) {
            $nationality = TkdnNationality::tryFrom((string) ($row['nationality'] ?? ''));

            if ($nationality === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.nationality" => [
                        "Baris {$line}: biaya tenaga kerja wajib menyebut kewarganegaraan (wni atau wna).",
                    ],
                ]);
            }

            return ['nationality' => $nationality->value];
        }

        if ($group === TkdnCostGroup::JasaUmum) {
            $origin = TkdnOrigin::tryFrom((string) ($row['provider_origin'] ?? ''));

            if ($origin === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.provider_origin" => [
                        "Baris {$line}: biaya jasa umum wajib menyebut asal penyedia (dn atau ln).",
                    ],
                ]);
            }

            return ['provider_origin' => $origin->value];
        }

        $madeIn = TkdnOrigin::tryFrom((string) ($row['made_in'] ?? ''));
        $ownedBy = TkdnOwnership::tryFrom((string) ($row['owned_by'] ?? ''));

        if ($madeIn === null || $ownedBy === null) {
            throw ValidationException::withMessages([
                "items.{$index}.made_in" => [
                    "Baris {$line}: biaya alat kerja wajib menyebut negara pembuat (dn/ln) dan kepemilikan (dn/ln/campuran).",
                ],
            ]);
        }

        $share = null;

        if ($madeIn === TkdnOrigin::Ln && $ownedBy === TkdnOwnership::Campuran) {
            $share = $row['domestic_share_pct'] ?? null;

            if ($share === null || $share === '' || (float) $share < 0 || (float) $share > 100) {
                throw ValidationException::withMessages([
                    "items.{$index}.domestic_share_pct" => [
                        "Baris {$line}: alat buatan luar negeri dengan kepemilikan campuran wajib menyebut "
                            .'proporsi saham dalam negeri (0–100).',
                    ],
                ]);
            }

            $share = (float) $share;
        }

        return [
            'made_in' => $madeIn->value,
            'owned_by' => $ownedBy->value,
            'domestic_share_pct' => $share,
        ];
    }
}
