<?php

namespace Tests\Feature\Crm;

use Illuminate\Validation\ValidationException;
use Modules\Crm\Enums\TkdnCostGroup;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Models\TkdnWorksheet;
use Modules\Crm\Services\TkdnService;
use Tests\ErpTestCase;
use Tests\Unit\Crm\CrmFixtures;

/**
 * P7 — lembar hitung TKDN atas satu penawaran.
 *
 * Angka TKDN yang dikutip di dokumen penawaran adalah KLAIM HUKUM. Dua uji di
 * bawah ini menjaga satu-satunya hal yang bisa membuatnya bohong tanpa ada
 * yang salah ketik: baris penawaran yang komponennya belum diuraikan. Ia
 * bukan 0% dan bukan 100%; ia BELUM DINILAI, dan cakupannya harus ikut
 * terbaca di sebelah persentasenya.
 *
 * Aritmetikanya mengikuti Permenperin 35/2025 — lihat TkdnService.
 */
class TkdnWorksheetTest extends ErpTestCase
{
    use CrmFixtures;

    private function tkdn(): TkdnService
    {
        return app(TkdnService::class);
    }

    /** Penawaran dua baris, masing-masing Rp 100 juta. */
    private function makeQuotation(): Quotation
    {
        $customer = $this->makeCustomer();

        return $this->quotations()->create([
            'customer_id' => $customer->id,
            'title' => 'Pembangunan gedung kantor',
            'scope_type' => 'construction',
            'valid_until' => '2026-12-31',
            'items' => [
                ['description' => 'Pekerjaan struktur', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 100_000_000],
                ['description' => 'Pekerjaan arsitektur', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 100_000_000],
            ],
        ]);
    }

    private function worksheetFor(Quotation $quotation): TkdnWorksheet
    {
        return $this->tkdn()->createWorksheet(['quotation_id' => $quotation->id]);
    }

    // ------------------------------------------------ the arithmetic itself

    /**
     * Hand-checkable, straight off Lampiran IV huruf B:
     *   tenaga kerja WNI  Rp 60.000.000 → KDN 100% → 60.000.000
     *   tenaga kerja WNA  Rp 20.000.000 → KDN   0% →          0
     *   alat LN/DN        Rp 10.000.000 → KDN  50% →  5.000.000
     *   jasa umum DN      Rp 10.000.000 → KDN 100% → 10.000.000
     *   ------------------------------------------------------
     *   biaya keseluruhan Rp 100.000.000, dalam negeri 75.000.000 → 75,00%
     */
    public function test_the_domestic_factors_follow_the_lampiran_iv_table(): void
    {
        $quotation = $this->makeQuotation();
        $item = $quotation->items->first();
        $worksheet = $this->worksheetFor($quotation);

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $item->id, 'cost_group' => TkdnCostGroup::TenagaKerja->value,
                'description' => 'Pelaksana & tukang', 'amount' => 60_000_000, 'nationality' => 'wni'],
            ['quotation_item_id' => $item->id, 'cost_group' => TkdnCostGroup::TenagaKerja->value,
                'description' => 'Tenaga ahli asing', 'amount' => 20_000_000, 'nationality' => 'wna'],
            ['quotation_item_id' => $item->id, 'cost_group' => TkdnCostGroup::AlatKerja->value,
                'description' => 'Tower crane impor milik sendiri', 'amount' => 10_000_000,
                'made_in' => 'ln', 'owned_by' => 'dn'],
            ['quotation_item_id' => $item->id, 'cost_group' => TkdnCostGroup::JasaUmum->value,
                'description' => 'Asuransi CAR', 'amount' => 10_000_000, 'provider_origin' => 'dn'],
        ]);

        $summary = $this->tkdn()->summary($worksheet->fresh());

        $this->assertSame(100_000_000.0, $summary['cost_total']);
        $this->assertSame(75_000_000.0, $summary['cost_domestic']);
        $this->assertSame(25_000_000.0, $summary['cost_foreign']);
        $this->assertSame(75.0, $summary['tkdn_pct']);
    }

    /**
     * Alat buatan luar negeri yang kepemilikannya campuran: 50% × proporsi
     * saham dalam negeri (Lampiran IV huruf B angka 2, baris terakhir sebelum
     * LN/LN). 40% saham DN → 50% × 40% = 20% KDN.
     */
    public function test_a_foreign_built_jointly_owned_tool_uses_the_share_proportion(): void
    {
        $quotation = $this->makeQuotation();
        $item = $quotation->items->first();
        $worksheet = $this->worksheetFor($quotation);

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $item->id, 'cost_group' => TkdnCostGroup::AlatKerja->value,
                'description' => 'Excavator sewa dari perusahaan patungan', 'amount' => 50_000_000,
                'made_in' => 'ln', 'owned_by' => 'campuran', 'domestic_share_pct' => 40],
        ]);

        $summary = $this->tkdn()->summary($worksheet->fresh());

        $this->assertSame(10_000_000.0, $summary['cost_domestic']);
        $this->assertSame(20.0, $summary['tkdn_pct']);
    }

    /**
     * Penghitungan paket = akumulasi berbobot BIAYA, bukan rata-rata polos
     * persentase baris. Dua baris penawaran:
     *   struktur   biaya Rp 80 juta, 100% DN
     *   arsitektur biaya Rp 20 juta,   0% DN
     * Rata-rata polos akan menjawab 50%. Jawaban yang benar adalah
     * (80 + 0) / 100 = 80,00%.
     */
    public function test_the_package_percentage_is_cost_weighted_not_a_plain_average(): void
    {
        $quotation = $this->makeQuotation();
        [$struktur, $arsitektur] = $quotation->items->all();
        $worksheet = $this->worksheetFor($quotation);

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $struktur->id, 'cost_group' => TkdnCostGroup::JasaUmum->value,
                'description' => 'Seluruh biaya dalam negeri', 'amount' => 80_000_000, 'provider_origin' => 'dn'],
            ['quotation_item_id' => $arsitektur->id, 'cost_group' => TkdnCostGroup::JasaUmum->value,
                'description' => 'Seluruh biaya luar negeri', 'amount' => 20_000_000, 'provider_origin' => 'ln'],
        ]);

        $summary = $this->tkdn()->summary($worksheet->fresh());

        $this->assertSame(80.0, $summary['tkdn_pct']);

        $perItem = collect($summary['items'])->keyBy('quotation_item_id');
        $this->assertSame(100.0, $perItem[$struktur->id]['tkdn_pct']);
        $this->assertSame(0.0, $perItem[$arsitektur->id]['tkdn_pct']);
    }

    // ---------------------------------------------- the unassessed item rule

    /**
     * ARAH PERTAMA: baris tanpa uraian komponen tidak boleh MENURUNKAN
     * persentase paket. Struktur 100% DN dinilai penuh, arsitektur belum
     * disentuh: jawabannya 100,00% — bukan 50,00% yang keluar bila baris
     * kedua diam-diam dihitung 0%.
     */
    public function test_an_unassessed_item_is_not_counted_as_zero_percent(): void
    {
        $quotation = $this->makeQuotation();
        [$struktur, $arsitektur] = $quotation->items->all();
        $worksheet = $this->worksheetFor($quotation);

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $struktur->id, 'cost_group' => TkdnCostGroup::TenagaKerja->value,
                'description' => 'Upah pekerja Indonesia', 'amount' => 70_000_000, 'nationality' => 'wni'],
        ]);

        $summary = $this->tkdn()->summary($worksheet->fresh());

        $this->assertSame(100.0, $summary['tkdn_pct']);

        $unassessed = collect($summary['items'])->firstWhere('quotation_item_id', $arsitektur->id);
        $this->assertFalse($unassessed['assessed']);
        $this->assertNull($unassessed['tkdn_pct'], 'Baris belum dinilai menggarisi selnya, tidak mencetak 0.');
    }

    /**
     * ARAH KEDUA: baris tanpa uraian komponen juga tidak boleh diam-diam
     * dianggap 100% dan ikut MEMBESARKAN cakupan. Nilai penawaran Rp 200 juta,
     * yang dinilai Rp 100 juta → cakupan 50,00% dan Rp 100 juta belum dinilai,
     * tertulis apa adanya di sebelah angka 100,00% itu.
     */
    public function test_an_unassessed_item_is_not_counted_as_fully_domestic_either(): void
    {
        $quotation = $this->makeQuotation();
        [$struktur, $arsitektur] = $quotation->items->all();
        $worksheet = $this->worksheetFor($quotation);

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $struktur->id, 'cost_group' => TkdnCostGroup::TenagaKerja->value,
                'description' => 'Upah pekerja Indonesia', 'amount' => 70_000_000, 'nationality' => 'wni'],
        ]);

        $summary = $this->tkdn()->summary($worksheet->fresh());

        $this->assertSame(200_000_000.0, $summary['quotation_value']);
        $this->assertSame(100_000_000.0, $summary['assessed_value']);
        $this->assertSame(100_000_000.0, $summary['unassessed_value']);
        $this->assertSame(50.0, $summary['coverage_pct']);
        $this->assertFalse($summary['fully_assessed']);
        $this->assertSame(
            [$arsitektur->id],
            array_column($summary['unassessed_items'], 'quotation_item_id'),
        );
    }

    /**
     * Lembar yang belum memuat satu baris komponen pun tidak menjawab 0%: ia
     * tidak menjawab. tkdn_pct null = sel bergaris pada formulirnya.
     */
    public function test_a_worksheet_with_no_rows_reports_no_percentage_at_all(): void
    {
        $worksheet = $this->worksheetFor($this->makeQuotation());

        $summary = $this->tkdn()->summary($worksheet);

        $this->assertNull($summary['tkdn_pct']);
        $this->assertSame(0.0, $summary['coverage_pct']);
        $this->assertFalse($summary['fully_assessed']);
    }

    // ------------------------------------- the token cost row rule (repair B1)

    /**
     * ARAH KETIGA, dan yang paling mudah dilakukan tanpa niat buruk sama
     * sekali: SATU baris biaya Rp 1 pada baris penawaran Rp 100 juta.
     *
     * Uji KEBERADAAN menjawab "dinilai" — cakupan 100,00%, fully_assessed
     * true, lembar bersih — padahal tak ada seorang pun yang menguraikan
     * biaya baris itu. Cakupan harus melihat BESARAN biaya yang diuraikan
     * terhadap nilai barisnya sendiri, bukan sekadar ada-tidaknya satu baris.
     */
    public function test_a_token_cost_row_cannot_claim_a_fully_assessed_sheet(): void
    {
        $quotation = $this->makeQuotation();
        [$struktur, $arsitektur] = $quotation->items->all();
        $worksheet = $this->worksheetFor($quotation);

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $struktur->id, 'cost_group' => TkdnCostGroup::TenagaKerja->value,
                'description' => 'Upah pekerja Indonesia', 'amount' => 70_000_000, 'nationality' => 'wni'],
            // Rp 1 atas baris penawaran Rp 100 juta.
            ['quotation_item_id' => $arsitektur->id, 'cost_group' => TkdnCostGroup::JasaUmum->value,
                'description' => 'Biaya cetak gambar', 'amount' => 1, 'provider_origin' => 'dn'],
        ]);

        $summary = $this->tkdn()->summary($worksheet->fresh());

        $this->assertFalse(
            $summary['fully_assessed'],
            'Satu baris Rp 1 tidak boleh membuat lembar menyatakan dirinya dinilai penuh.',
        );
        $this->assertSame(50.0, $summary['coverage_pct']);
        $this->assertSame(100_000_000.0, $summary['assessed_value']);
        $this->assertSame(100_000_000.0, $summary['partially_assessed_value']);
        $this->assertSame(0.0, $summary['unassessed_value']);
        $this->assertSame(
            [$arsitektur->id],
            array_column($summary['partially_assessed_items'], 'quotation_item_id'),
        );

        $perItem = collect($summary['items'])->keyBy('quotation_item_id');
        $this->assertSame('penuh', $perItem[$struktur->id]['assessment']);
        $this->assertSame('sebagian', $perItem[$arsitektur->id]['assessment']);
        $this->assertSame(70.0, $perItem[$struktur->id]['cost_to_value_pct']);
        $this->assertSame(0.0, $perItem[$arsitektur->id]['cost_to_value_pct']);

        // Barisnya tetap punya persennya sendiri: Rp 1 dari penyedia dalam
        // negeri memang 100% DN. Yang dibantah lembar ini adalah CAKUPANNYA,
        // bukan aritmetika barisnya.
        $this->assertTrue($perItem[$arsitektur->id]['assessed']);
        $this->assertSame(100.0, $perItem[$arsitektur->id]['tkdn_pct']);
    }

    /**
     * ARAH SEBALIKNYA — penjaga yang selalu berteriak tidak menjaga apa pun.
     * Kedua baris penawaran diuraikan biayanya sampai melewati ambang rumah,
     * dan lembarnya BOLEH menyatakan dirinya dinilai penuh.
     */
    public function test_a_sheet_whose_lines_are_really_described_is_fully_assessed(): void
    {
        $quotation = $this->makeQuotation();
        [$struktur, $arsitektur] = $quotation->items->all();
        $worksheet = $this->worksheetFor($quotation);

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $struktur->id, 'cost_group' => TkdnCostGroup::TenagaKerja->value,
                'description' => 'Upah pekerja Indonesia', 'amount' => 70_000_000, 'nationality' => 'wni'],
            ['quotation_item_id' => $arsitektur->id, 'cost_group' => TkdnCostGroup::JasaUmum->value,
                'description' => 'Subkontrak arsitektur dalam negeri', 'amount' => 80_000_000,
                'provider_origin' => 'dn'],
        ]);

        $summary = $this->tkdn()->summary($worksheet->fresh());

        $this->assertTrue($summary['fully_assessed']);
        $this->assertSame(100.0, $summary['coverage_pct']);
        $this->assertSame(0.0, $summary['partially_assessed_value']);
        $this->assertSame([], $summary['partially_assessed_items']);
        $this->assertSame(75.0, $summary['cost_to_value_pct']);
    }

    /**
     * Ambangnya adalah ANGKA RUMAH, bukan angka Permen — jadi ia diumumkan di
     * dalam muatan dan dipegang pemilik lewat config, dan menaikkannya harus
     * benar-benar mengubah jawaban lembarnya.
     */
    public function test_the_house_floor_is_published_and_owned_by_config(): void
    {
        $quotation = $this->makeQuotation();
        [$struktur] = $quotation->items->all();
        $worksheet = $this->worksheetFor($quotation);

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $struktur->id, 'cost_group' => TkdnCostGroup::TenagaKerja->value,
                'description' => 'Upah pekerja Indonesia', 'amount' => 70_000_000, 'nationality' => 'wni'],
        ]);

        $summary = $this->tkdn()->summary($worksheet->fresh());
        $this->assertSame(50.0, $summary['min_cost_to_value_pct']);
        $this->assertSame('penuh', collect($summary['items'])->firstWhere('quotation_item_id', $struktur->id)['assessment']);
        $this->assertStringContainsString('tidak menyebut', $summary['basis_cakupan']);

        $this->setSetting('tender.tkdn_min_cost_to_value_pct', 80);

        $summary = $this->tkdn()->summary($worksheet->fresh());
        $this->assertSame(80.0, $summary['min_cost_to_value_pct']);
        $this->assertSame('sebagian', collect($summary['items'])->firstWhere('quotation_item_id', $struktur->id)['assessment']);
        $this->assertFalse($summary['fully_assessed']);
    }

    // ------------------------------------------------------------ refusals

    public function test_a_labour_row_without_a_nationality_is_refused(): void
    {
        $quotation = $this->makeQuotation();
        $worksheet = $this->worksheetFor($quotation);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('kewarganegaraan');

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $quotation->items->first()->id,
                'cost_group' => TkdnCostGroup::TenagaKerja->value,
                'description' => 'Upah', 'amount' => 1_000_000],
        ]);
    }

    public function test_a_jointly_owned_foreign_tool_without_a_share_is_refused(): void
    {
        $quotation = $this->makeQuotation();
        $worksheet = $this->worksheetFor($quotation);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proporsi saham dalam negeri');

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $quotation->items->first()->id,
                'cost_group' => TkdnCostGroup::AlatKerja->value,
                'description' => 'Crane', 'amount' => 1_000_000,
                'made_in' => 'ln', 'owned_by' => 'campuran'],
        ]);
    }

    public function test_a_row_pointing_at_another_quotations_item_is_refused(): void
    {
        $mine = $this->makeQuotation();
        $other = $this->makeQuotation();
        $worksheet = $this->worksheetFor($mine);

        $this->expectException(ValidationException::class);

        $this->tkdn()->replaceItems($worksheet, [
            ['quotation_item_id' => $other->items->first()->id,
                'cost_group' => TkdnCostGroup::JasaUmum->value,
                'description' => 'Asuransi', 'amount' => 1_000_000, 'provider_origin' => 'dn'],
        ]);
    }

    // ------------------------------------------------------ provenance (B2/B3)

    /**
     * Kutipan peraturan di dalam docblock adalah bagian dari klaimnya.
     *
     * Dua hal dipaku di sini, keduanya diperiksa ulang terhadap
     * `pdftotext -layout` atas salinan resmi yang dikutip docblock-nya:
     *
     *   Pasal 17 berbunyi "Penghitungan nilai TKDN Jasa Industri ditetapkan
     *   oleh Sekretaris Jenderal." — SELURUH penghitungannya, bukan petunjuk
     *   teknisnya. Kata "Petunjuk teknis" milik Pasal 13, yang berbicara
     *   tentang Barang; meminjamnya ke Pasal 17 MENGECILKAN pendelegasian.
     *
     *   "Ditetapkan di Jakarta pada tanggal 11 September 2025" ADA di dalam
     *   PDF-nya dan benar. Sebuah pemeriksaan sebelumnya gagal menariknya
     *   keluar dan menyimpulkan tanggalnya tidak dapat diverifikasi; fakta
     *   terverifikasi tidak dibuang karena satu ekstraksi gagal.
     */
    public function test_the_service_docblock_quotes_pasal_17_and_keeps_the_verified_date(): void
    {
        // Sebuah kutipan yang dibungkus pada lebar 80 kolom tetap kutipan yang
        // sama: buang penanda komentar dan rapatkan spasinya sebelum membaca,
        // supaya uji ini memaku KATA-KATANYA dan bukan tempat barisnya patah.
        $source = preg_replace(
            '/\s+/u',
            ' ',
            str_replace(' * ', ' ', file_get_contents(base_path('Modules/Crm/Services/TkdnService.php'))),
        );

        $this->assertStringContainsString(
            'Penghitungan nilai TKDN Jasa Industri ditetapkan oleh Sekretaris Jenderal.',
            $source,
            'Pasal 17 dikutip kata demi kata, atau tidak dikutip sama sekali.',
        );

        $this->assertStringNotContainsString(
            'Petunjuk teknis penghitungan TKDN Jasa Industri',
            $source,
            '"Petunjuk teknis" adalah bunyi Pasal 13 (Barang). Menempelkannya pada Pasal 17 menyatakan '
            .'Sekretaris Jenderal hanya menetapkan panduan, padahal ia menetapkan penghitungannya.',
        );

        $this->assertStringContainsString(
            'Ditetapkan di Jakarta pada tanggal 11 September 2025',
            $source,
            'Tanggal penetapan terbaca di PDF resminya; ia terverifikasi dan tidak dibuang.',
        );
    }
}
