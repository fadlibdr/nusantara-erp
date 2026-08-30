<?php

namespace Tests\Feature\Crm;

use Illuminate\Support\Carbon;
use Modules\Assets\Enums\AssetOwnership;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\TenderPackage;
use Modules\Crm\Services\RkkService;
use Modules\Crm\Services\TenderPackageService;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\BoqSection;
use Modules\HrPayroll\Models\Certificate;
use Modules\HrPayroll\Models\Employee;
use Modules\Procurement\Enums\VendorType;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\RiskRegisterService;
use Tests\ErpTestCase;

/**
 * P7 — the three tender sheets: F/RKK, F/SBD and F/DA.
 *
 * What is tested here is not "does it render" but WHICH CELLS ARE FILLED AND
 * WHICH ARE RULED, because these sheets go into a bid envelope over three
 * signatures and the declarative print path puts a plausible-looking default
 * one keystroke away.
 */
class TenderFormPrintTest extends ErpTestCase
{
    private const TODAY = '2026-09-01';

    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->forms = app(FormPrintService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function package(array $overrides = []): TenderPackage
    {
        $lead = Lead::query()->create(['name' => 'Panitia Pengadaan', 'status' => 'new']);

        return app(TenderPackageService::class)->create($overrides + [
            'lead_id' => $lead->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'owner_name' => 'PT Graha Sentosa Propertindo',
            'tender_number' => '027/PPBJ/GSP/2026',
        ]);
    }

    // ------------------------------------------------------------- F/RKK

    public function test_the_rkk_sheet_prints_live_ibprp_rows_and_derived_smkk_money(): void
    {
        $project = Project::query()->create([
            'code' => 'PRJ-2026-910', 'name' => 'Gedung Kantor', 'type' => 'construction', 'status' => 'preparation',
        ]);

        $entry = app(RiskRegisterService::class)->create([
            'project_id' => $project->id,
            'activity' => 'Pekerjaan galian basement',
            'hazard' => 'Longsoran dinding galian',
            'likelihood' => 3,
            'severity' => 4,
            'controls' => 'Sheet pile dan dewatering',
        ], $this->adminUser());

        $boq = Boq::query()->create(['code' => 'BOQ/2026/9101', 'title' => 'RAB Gedung', 'status' => 'draft']);
        $section = BoqSection::query()->create(['boq_id' => $boq->id, 'section_no' => 'X', 'name' => 'Penerapan SMKK']);
        $item = BoqItem::query()->create([
            'boq_id' => $boq->id, 'section_id' => $section->id, 'wbs_code' => 'X.1',
            'description' => 'APD dan rambu keselamatan', 'qty' => 1, 'unit' => 'ls',
            'unit_price' => 125_000_000, 'amount' => 125_000_000,
        ]);

        $rkk = app(RkkService::class)->create([
            'tender_package_id' => $this->package()->id,
            'project_id' => $project->id,
            'boq_id' => $boq->id,
            'title' => 'RKK Penawaran Gedung Kantor',
            'policy' => 'Perusahaan berkomitmen nihil kecelakaan kerja.',
            'program' => 'Induksi K3, toolbox harian, inspeksi mingguan.',
        ]);

        app(RkkService::class)->syncIbprpLinks($rkk, [$entry->id]);
        app(RkkService::class)->syncSmkkCosts($rkk, [['boq_item_id' => $item->id, 'category' => 'APD & rambu']]);

        $html = $this->forms->html('rkk', ['id' => $rkk->id]);

        $this->assertStringContainsString('RENCANA KESELAMATAN KONSTRUKSI', $html);
        $this->assertStringContainsString('Form F/RKK', $html);
        $this->assertStringContainsString('Perusahaan berkomitmen nihil kecelakaan kerja.', $html);
        $this->assertStringContainsString('Longsoran dinding galian', $html);
        // Skor dibaca dari register, tidak disalin saat menaut: 3 × 4.
        $this->assertStringContainsString('12', $html);
        $this->assertStringContainsString('APD dan rambu keselamatan', $html);
        // Rupiahnya TURUNAN dari baris RAB — tidak ada angka kedua yang disimpan.
        $this->assertStringContainsString('125.000.000,00', $html);
    }

    /**
     * RKK tanpa tautan apa pun mencetak kalimat kosongnya, BUKAN nol rupiah:
     * "belum menaut baris biaya" dan "tidak berbiaya" adalah dua pernyataan
     * berbeda, dan yang kedua tidak kita ketahui.
     */
    public function test_an_rkk_with_no_links_prints_its_empty_sentences_not_zeroes(): void
    {
        $rkk = app(RkkService::class)->create([
            'tender_package_id' => $this->package()->id,
            'title' => 'RKK Penawaran tanpa tautan',
        ]);

        $html = $this->forms->html('rkk', ['id' => $rkk->id]);

        $this->assertStringContainsString('belum menaut satu pun baris IBPRP', $html);
        $this->assertStringContainsString('belum menaut satu pun baris biaya SMKK', $html);
        $this->assertStringContainsString('Kebijakan keselamatan konstruksi belum diisi.', $html);
    }

    // ------------------------------------------------------------- F/SBD

    /**
     * Lembar personil hanya memuat sertifikat yang masih berlaku — dan
     * menyebutkan berapa yang kedaluwarsa, supaya tidak ada yang mengira tidak
     * ada.
     */
    public function test_the_personnel_sheet_omits_a_lapsed_certificate_but_discloses_the_count(): void
    {
        $valid = $this->employee('EMP-9101', 'Rina Wijaya');
        $lapsed = $this->employee('EMP-9102', 'Agus Prasetyo');

        $this->certificate($valid, '2027-06-30', 'SKK Ahli Madya Teknik Bangunan Gedung');
        $this->certificate($lapsed, '2026-03-31', 'SKK Ahli Muda K3 Konstruksi');

        $html = $this->forms->html('daftar-personil', ['id' => $this->package()->id]);

        $this->assertStringContainsString('Form F/SBD', $html);
        $this->assertStringContainsString('Rina Wijaya', $html);
        $this->assertStringNotContainsString('Agus Prasetyo', $html);
        $this->assertStringNotContainsString('SKK Ahli Muda K3 Konstruksi', $html);
        // Diungkapkan, bukan dibuang diam-diam.
        $this->assertStringContainsString('SERTIFIKAT KEDALUWARSA TIDAK DIDAFTAR', $html);
        $this->assertStringContainsString('PERSONIL PER TANGGAL', $html);
    }

    /**
     * Kolom JENIS SERTIFIKAT mencetak LABELNYA, bukan nilai enum mentahnya:
     * lampiran tender bertanda tangan tidak menulis 'skk' — cara yang sama
     * F/DA menulis 'Sewa', bukan 'rented'.
     */
    public function test_the_personnel_sheet_prints_the_certificate_type_label_not_the_enum_value(): void
    {
        $employee = $this->employee('EMP-9103', 'Rina Wijaya');
        $this->certificate($employee, '2027-06-30', 'SKK Ahli Madya Teknik Bangunan Gedung');

        $html = $this->forms->html('daftar-personil', ['id' => $this->package()->id]);

        $this->assertStringContainsString('SKK Konstruksi', $html);
        // Tidak ada sel yang tinggal berisi nilai mentah 'skk'.
        $this->assertDoesNotMatchRegularExpression('/>\s*skk\s*</', $html);
    }

    // -------------------------------------------------------------- F/DA

    /** Alat sewa tercetak — dan tercetak SEBAGAI sewa, dengan lessornya. */
    public function test_the_equipment_sheet_discloses_a_rented_machine_as_rented(): void
    {
        $category = AssetCategory::query()->create(['code' => 'ALB9', 'name' => 'Alat Berat']);

        $lessor = Vendor::query()->create([
            'code' => 'VND-9101', 'name' => 'PT Alat Berat Nusantara', 'classification' => 'jasa',
            'vendor_type' => VendorType::Rental->value, 'status' => 'active',
        ]);

        Asset::query()->create([
            'code' => 'AST-2026-9101', 'name' => 'Concrete Mixer Truck', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Owned->value, 'acquisition_date' => '2024-02-01',
            'acquisition_cost' => 900_000_000, 'useful_life_months' => 96, 'status' => 'available',
        ]);

        Asset::query()->create([
            'code' => 'AST-2026-9102', 'name' => 'Excavator Doosan DX225LCA', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Rented->value, 'vendor_id' => $lessor->id,
            'rental_rate' => 400_000, 'rate_basis' => 'per_jam',
            'rental_start' => '2026-06-01', 'rental_end' => '2026-12-31',
            'useful_life_months' => 0, 'status' => 'available',
        ]);

        $html = $this->forms->html('dukungan-alat', ['id' => $this->package()->id]);

        $this->assertStringContainsString('Form F/DA', $html);
        $this->assertStringContainsString('Excavator Doosan DX225LCA', $html);
        // Kata "Sewa" dan nama lessornya ada DI BARIS itu, bukan di catatan kaki.
        $this->assertStringContainsString('Sewa', $html);
        $this->assertStringContainsString('PT Alat Berat Nusantara', $html);
        $this->assertStringContainsString('31 Desember 2026', $html);
        // Alat milik sendiri tetap tercetak dan berkata begitu.
        $this->assertStringContainsString('Concrete Mixer Truck', $html);
        $this->assertStringContainsString('Milik sendiri', $html);
    }

    // ------------------------------------------- tanggal lembar, kedua lembar

    /**
     * JANJI YANG DITAGIH DI SINI: lembar kualifikasi menjawab per TANGGAL
     * LEMBARNYA, bukan per hari cetak. Tanggal lembar sebuah paket tender
     * adalah BATAS PEMASUKAN PENAWARAN — itulah tanggal panitia menilai berkas
     * yang dimasukkan, dan itu pula tanggal yang tercetak di kepala lembar
     * (registry 'date' => submission_deadline).
     *
     * Hari cetak 1 September; batas pemasukan 15 Oktober. Sertifikat yang lewat
     * 1 Oktober masih berlaku HARI INI tetapi sudah lewat pada saat berkasnya
     * dinilai — ia tidak boleh berdiri di lembar itu. Alat sewa yang habis
     * 30 September sama: sudah kembali ke lessor sebelum berkasnya dinilai.
     */
    public function test_both_qualification_sheets_answer_as_at_the_packages_submission_deadline(): void
    {
        $package = $this->package(['submission_deadline' => '2026-10-15']);

        $safe = $this->employee('EMP-9111', 'Rina Wijaya');
        $lapsesBeforeDeadline = $this->employee('EMP-9112', 'Agus Prasetyo');

        $this->certificate($safe, '2027-06-30', 'SKK Ahli Madya Teknik Bangunan Gedung');
        $this->certificate($lapsesBeforeDeadline, '2026-10-01', 'SKK Ahli Muda K3 Konstruksi');

        $category = AssetCategory::query()->create(['code' => 'ALB8', 'name' => 'Alat Berat']);

        Asset::query()->create([
            'code' => 'AST-2026-9201', 'name' => 'Excavator sewa berjalan', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Rented->value,
            'rental_start' => '2026-06-01', 'rental_end' => '2026-12-31',
            'useful_life_months' => 0, 'status' => 'available',
        ]);
        Asset::query()->create([
            'code' => 'AST-2026-9202', 'name' => 'Tower crane sewa habis', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Rented->value,
            'rental_start' => '2026-01-01', 'rental_end' => '2026-09-30',
            'useful_life_months' => 0, 'status' => 'available',
        ]);

        $sbd = $this->forms->html('daftar-personil', ['id' => $package->id]);

        // Lembar menyebut tanggal yang dijawabnya, dan tanggal itu BUKAN hari
        // cetak. (Hari cetak tetap tercetak di kaki lembar sebagai "Dicetak …",
        // dan memang harus — itu fakta lain tentang lembar yang sama.)
        $this->assertSame('15 Oktober 2026', $this->identityValue($sbd, 'PERSONIL PER TANGGAL'));
        $this->assertStringContainsString('Rina Wijaya', $sbd);
        // Masih berlaku hari ini, sudah lewat pada tanggal lembar — tidak dicetak,
        // tetapi diungkapkan.
        $this->assertStringNotContainsString('Agus Prasetyo', $sbd);
        $this->assertStringContainsString('SERTIFIKAT KEDALUWARSA TIDAK DIDAFTAR', $sbd);

        $da = $this->forms->html('dukungan-alat', ['id' => $package->id]);

        $this->assertSame('15 Oktober 2026', $this->identityValue($da, 'ALAT PER TANGGAL'));
        $this->assertStringContainsString('Excavator sewa berjalan', $da);
        $this->assertStringNotContainsString('Tower crane sewa habis', $da);
        // Dibuang dari daftar, tidak dibuang diam-diam.
        $this->assertStringContainsString('SEWA BERAKHIR TIDAK DIDAFTAR', $da);
    }

    /**
     * Paket yang belum mencatat batas pemasukan tetap harus mencetak: lembarnya
     * jatuh ke hari cetak, dan MENGATAKAN tanggal itu di blok identitas —
     * sebuah lembar yang tidak menyebut tanggal acuannya menjawab pertanyaan
     * yang berbeda setiap kali dicetak.
     */
    public function test_a_package_without_a_deadline_falls_back_to_the_print_date_and_says_so(): void
    {
        $employee = $this->employee('EMP-9121', 'Joko Susilo');
        $this->certificate($employee, '2027-01-31', 'SKK Teknisi ELV');

        $html = $this->forms->html('daftar-personil', ['id' => $this->package()->id]);

        $this->assertSame('01 September 2026', $this->identityValue($html, 'PERSONIL PER TANGGAL'));
        $this->assertStringContainsString('Joko Susilo', $html);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Nilai satu baris blok identitas, dibaca dari markupnya sendiri.
     *
     * Diperlukan karena kaki lembar juga mencetak sebuah tanggal ("Dicetak
     * …"), jadi assertStringContainsString atas sebuah tanggal tidak bisa
     * membedakan tanggal yang DIJAWAB lembar dari hari orang menekan cetak —
     * dan justru perbedaan itulah yang diuji di sini.
     */
    private function identityValue(string $html, string $label): ?string
    {
        $pattern = '#<td class="k">'.preg_quote($label, '#')
            .'</td>\s*<td class="s">:</td>\s*<td class="v">\s*([^<]*?)\s*<#s';

        return preg_match($pattern, $html, $matches) === 1 ? trim($matches[1]) : null;
    }

    private function employee(string $code, string $name): Employee
    {
        return Employee::query()->create([
            'code' => $code, 'name' => $name,
            'nik_ktp' => str_pad((string) crc32($code), 16, '0', STR_PAD_LEFT),
            'gender' => 'male', 'birth_date' => '1985-05-05', 'ptkp_status' => 'K/1',
            'join_date' => '2015-01-05', 'employment_type' => 'tetap',
            'position' => 'Site Manager', 'department' => 'proyek',
            'base_salary' => 15_000_000, 'status' => 'active',
        ]);
    }

    private function certificate(Employee $employee, string $expiry, string $name): Certificate
    {
        return Certificate::query()->create([
            'employee_id' => $employee->id, 'certificate_type' => 'skk', 'name' => $name,
            'number' => 'SKK-'.$employee->code, 'issuer' => 'LPJK',
            'issued_date' => '2022-01-01', 'expiry_date' => $expiry,
        ]);
    }
}
