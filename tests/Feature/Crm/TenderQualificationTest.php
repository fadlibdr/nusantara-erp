<?php

namespace Tests\Feature\Crm;

use Illuminate\Support\Carbon;
use Modules\Assets\Enums\AssetOwnership;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Crm\Services\TenderQualificationService;
use Modules\HrPayroll\Enums\CertificateType;
use Modules\HrPayroll\Models\Certificate;
use Modules\HrPayroll\Models\Employee;
use Modules\Procurement\Enums\VendorType;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * P7 — penyusun kualifikasi: personil (F/SBD), dukungan alat (F/DA), daftar
 * subkontraktor.
 */
class TenderQualificationTest extends ErpTestCase
{
    private function qualification(): TenderQualificationService
    {
        return app(TenderQualificationService::class);
    }

    private function makeEmployee(string $code, string $name): Employee
    {
        return Employee::query()->create([
            'code' => $code,
            'name' => $name,
            'nik_ktp' => str_pad((string) crc32($code), 16, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'birth_date' => '1985-05-05',
            'ptkp_status' => 'K/1',
            'join_date' => '2015-01-05',
            'employment_type' => 'tetap',
            'position' => 'Site Manager',
            'department' => 'proyek',
            'base_salary' => 15_000_000,
            'status' => 'active',
        ]);
    }

    private function makeCertificate(Employee $employee, ?string $expiry, string $name): Certificate
    {
        return Certificate::query()->create([
            'employee_id' => $employee->id,
            'certificate_type' => 'skk',
            'name' => $name,
            'number' => 'SKK-'.$employee->code,
            'issuer' => 'LPJK',
            'issued_date' => '2022-01-01',
            'expiry_date' => $expiry,
        ]);
    }

    // ----------------------------------------------------------- personil

    /**
     * ATURAN KEJUJURAN YANG PALING TAJAM DI PAKET INI: sertifikat yang sudah
     * lewat masa berlakunya TIDAK pernah masuk daftar yang memenuhi — dan juga
     * tidak dibuang diam-diam. Ia pindah ke ember "kedaluwarsa" bersama
     * tanggal lewatnya, supaya masih sempat diperpanjang.
     */
    public function test_an_expired_certificate_never_counts_as_a_qualification_and_is_disclosed(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');

        $valid = $this->makeEmployee('EMP-9001', 'Rina Wijaya');
        $lapsed = $this->makeEmployee('EMP-9002', 'Agus Prasetyo');

        $this->makeCertificate($valid, '2027-06-30', 'SKK Ahli Madya Teknik Bangunan Gedung');
        $this->makeCertificate($lapsed, '2026-03-31', 'SKK Ahli Muda K3 Konstruksi');

        $result = $this->qualification()->personnel();

        $this->assertSame(['Rina Wijaya'], array_column($result['memenuhi'], 'employee_name'));
        $this->assertSame(['Agus Prasetyo'], array_column($result['kedaluwarsa'], 'employee_name'));
        $this->assertSame('2026-03-31', $result['kedaluwarsa'][0]['expiry_date']);
        $this->assertTrue($result['kedaluwarsa'][0]['expired']);
        $this->assertLessThan(0, $result['kedaluwarsa'][0]['days_to_expiry']);

        Carbon::setTestNow();
    }

    public function test_a_certificate_that_never_expires_is_qualified(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');

        $employee = $this->makeEmployee('EMP-9003', 'Budi Santoso');
        $this->makeCertificate($employee, null, 'Sertifikasi Principal Honeywell');

        $result = $this->qualification()->personnel();

        $this->assertCount(1, $result['memenuhi']);
        $this->assertNull($result['memenuhi'][0]['expiry_date']);
        $this->assertNull($result['memenuhi'][0]['days_to_expiry']);
        $this->assertSame([], $result['kedaluwarsa']);

        Carbon::setTestNow();
    }

    /**
     * PAKU LITERAL: certificate_type_label dieja BY VALUE di service (arah
     * panah yang membuat query-nya raw juga melarang mengimpor enum HrPayroll),
     * maka setiap case dipaku di sini terhadap CertificateType::label() —
     * kalau salah satu sisi bergeser, tes ini yang berbunyi. F/SBD mencetak
     * label ini, bukan nilai enum mentahnya: lampiran tender bertanda tangan
     * tidak menulis 'skk'.
     */
    public function test_certificate_type_labels_mirror_the_hr_enum_case_by_case(): void
    {
        foreach (array_values(CertificateType::cases()) as $index => $case) {
            $employee = $this->makeEmployee('EMP-91'.(30 + $index), 'Personil '.strtoupper($case->value));

            Certificate::query()->create([
                'employee_id' => $employee->id,
                'certificate_type' => $case->value,
                'name' => 'Sertifikat '.$case->value,
                'number' => 'CERT-'.$employee->code,
                'issuer' => 'LPJK',
                'issued_date' => '2022-01-01',
                'expiry_date' => null,
            ]);
        }

        $labels = collect($this->qualification()->personnel()['memenuhi'])
            ->mapWithKeys(fn (array $row): array => [$row['certificate_type'] => $row['certificate_type_label']])
            ->all();

        foreach (CertificateType::cases() as $case) {
            $this->assertSame($case->label(), $labels[$case->value] ?? null, "Label '{$case->value}' menyimpang dari enum HrPayroll.");
        }
    }

    /**
     * Lembar kualifikasi bertanggal 1 Maret menjawab sebagaimana pada 1 Maret,
     * berapa lama pun setelahnya ia dicetak ulang — bukan sebagaimana pada
     * hari orang menekan cetak.
     */
    public function test_the_answer_is_as_at_the_sheets_own_date_not_todays(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');

        $employee = $this->makeEmployee('EMP-9004', 'Joko Susilo');
        $this->makeCertificate($employee, '2026-05-31', 'SKK Teknisi ELV');

        $today = $this->qualification()->personnel();
        $this->assertCount(1, $today['kedaluwarsa']);

        $back = $this->qualification()->personnel(Carbon::parse('2026-03-01'));
        $this->assertCount(1, $back['memenuhi']);
        $this->assertSame([], $back['kedaluwarsa']);
        $this->assertSame('2026-03-01', $back['as_of']);

        Carbon::setTestNow();
    }

    // -------------------------------------------------------- dukungan alat

    /**
     * F/DA memuat alat sewa — dan menyebutnya sewa, lengkap dengan lessornya.
     * Alat sewa yang terbaca seperti milik sendiri adalah persis kolom yang
     * diperiksa panitia lelang.
     */
    public function test_the_equipment_list_covers_owned_and_rented_and_says_which(): void
    {
        $category = AssetCategory::query()->create([
            'code' => 'ALB', 'name' => 'Alat Berat',
        ]);

        $lessor = Vendor::query()->create([
            'code' => 'VND-9001', 'name' => 'PT Alat Berat Nusantara',
            'classification' => 'jasa', 'vendor_type' => VendorType::Rental->value, 'status' => 'active',
        ]);

        Asset::query()->create([
            'code' => 'AST-2026-9001', 'name' => 'Concrete Mixer Truck', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Owned->value, 'acquisition_date' => '2024-02-01',
            'acquisition_cost' => 900_000_000, 'useful_life_months' => 96, 'status' => 'available',
        ]);

        Asset::query()->create([
            'code' => 'AST-2026-9002', 'name' => 'Excavator Doosan DX225LCA', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Rented->value, 'vendor_id' => $lessor->id,
            'rental_rate' => 400_000, 'rate_basis' => 'per_jam',
            'rental_start' => '2026-06-01', 'rental_end' => '2026-12-31',
            'useful_life_months' => 0, 'status' => 'available',
        ]);

        $rows = collect($this->qualification()->equipment()['memenuhi'])->keyBy('code');

        $this->assertCount(2, $rows);

        $this->assertFalse($rows['AST-2026-9001']['rented']);
        $this->assertSame('Milik sendiri', $rows['AST-2026-9001']['ownership_label']);
        $this->assertNull($rows['AST-2026-9001']['lessor_name']);

        $this->assertTrue($rows['AST-2026-9002']['rented']);
        $this->assertSame('Sewa', $rows['AST-2026-9002']['ownership_label']);
        $this->assertSame('PT Alat Berat Nusantara', $rows['AST-2026-9002']['lessor_name']);
        $this->assertSame('2026-12-31', substr((string) $rows['AST-2026-9002']['rental_end'], 0, 10));
    }

    public function test_a_disposed_asset_cannot_support_a_bid(): void
    {
        $category = AssetCategory::query()->create([
            'code' => 'ALB2', 'name' => 'Alat Berat',
        ]);

        Asset::query()->create([
            'code' => 'AST-2026-9003', 'name' => 'Bulldozer terjual', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Owned->value, 'acquisition_date' => '2018-02-01',
            'acquisition_cost' => 500_000_000, 'useful_life_months' => 96, 'status' => 'disposed',
        ]);

        $this->assertSame([], $this->qualification()->equipment()['memenuhi']);
        $this->assertSame([], $this->qualification()->equipment()['kedaluwarsa']);
    }

    /**
     * CERMIN dari aturan sertifikat lewat, pada alat: sebuah alat SEWA yang
     * masa sewanya SUDAH BERAKHIR bukan dukungan alat. Tidak ada apa pun di
     * modul Aset yang memindahkan statusnya saat sewanya habis — status masih
     * `available` selamanya — jadi tanpa aturan ini F/DA mencetak excavator
     * yang sudah dikembalikan ke lessor dan menghitungnya di JUMLAH ALAT
     * DIDAFTAR.
     *
     * Dua ember, seperti personil: dibuang dari daftar dukungan, TETAPI
     * diungkapkan bersama tanggal berakhirnya — supaya lubangnya bisa ditutup
     * (perpanjang sewa) sebelum batas pemasukan, bukan ditemukan setelah kalah.
     */
    public function test_a_rented_asset_whose_lease_has_ended_is_not_plant_support_and_is_disclosed(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');

        $category = AssetCategory::query()->create(['code' => 'ALB3', 'name' => 'Alat Berat']);

        $lessor = Vendor::query()->create([
            'code' => 'VND-9020', 'name' => 'PT Alat Berat Nusantara',
            'classification' => 'jasa', 'vendor_type' => VendorType::Rental->value, 'status' => 'active',
        ]);

        Asset::query()->create([
            'code' => 'AST-2026-9010', 'name' => 'Concrete Mixer Truck', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Owned->value, 'acquisition_date' => '2024-02-01',
            'acquisition_cost' => 900_000_000, 'useful_life_months' => 96, 'status' => 'available',
        ]);

        Asset::query()->create([
            'code' => 'AST-2026-9011', 'name' => 'Excavator sewa berjalan', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Rented->value, 'vendor_id' => $lessor->id,
            'rental_rate' => 400_000, 'rate_basis' => 'per_jam',
            'rental_start' => '2026-06-01', 'rental_end' => '2026-12-31',
            'useful_life_months' => 0, 'status' => 'available',
        ]);

        // Sewanya habis 31 Juli; statusnya tetap `available` karena tidak ada
        // yang memindahkannya — persis keadaan yang membuat aturan ini perlu.
        Asset::query()->create([
            'code' => 'AST-2026-9012', 'name' => 'Tower crane sewa habis', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Rented->value, 'vendor_id' => $lessor->id,
            'rental_rate' => 25_000_000, 'rate_basis' => 'per_bulan',
            'rental_start' => '2026-01-01', 'rental_end' => '2026-07-31',
            'useful_life_months' => 0, 'status' => 'available',
        ]);

        $result = $this->qualification()->equipment();

        $this->assertSame('2026-09-01', $result['as_of']);
        $this->assertSame(
            ['AST-2026-9010', 'AST-2026-9011'],
            array_column($result['memenuhi'], 'code'),
        );

        $this->assertSame(['AST-2026-9012'], array_column($result['kedaluwarsa'], 'code'));
        $this->assertSame('2026-07-31', $result['kedaluwarsa'][0]['rental_end']);
        $this->assertTrue($result['kedaluwarsa'][0]['rental_expired']);
        $this->assertLessThan(0, $result['kedaluwarsa'][0]['days_to_rental_end']);

        // Alat milik sendiri tidak punya masa sewa untuk lewat.
        $owned = collect($result['memenuhi'])->firstWhere('code', 'AST-2026-9010');
        $this->assertFalse($owned['rental_expired']);
        $this->assertNull($owned['days_to_rental_end']);

        Carbon::setTestNow();
    }

    /**
     * Tanggal pembandingnya adalah tanggal LEMBAR, bukan hari cetak — aturan
     * yang sama dengan personil. Dijawab per 30 Juni, tower crane itu masih
     * dukungan alat yang sah.
     */
    public function test_the_equipment_answer_is_as_at_the_sheets_own_date(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');

        $category = AssetCategory::query()->create(['code' => 'ALB4', 'name' => 'Alat Berat']);

        Asset::query()->create([
            'code' => 'AST-2026-9013', 'name' => 'Tower crane sewa habis', 'category_id' => $category->id,
            'ownership' => AssetOwnership::Rented->value,
            'rental_start' => '2026-01-01', 'rental_end' => '2026-07-31',
            'useful_life_months' => 0, 'status' => 'available',
        ]);

        $today = $this->qualification()->equipment();
        $this->assertCount(1, $today['kedaluwarsa']);

        $back = $this->qualification()->equipment(null, Carbon::parse('2026-06-30'));
        $this->assertSame('2026-06-30', $back['as_of']);
        $this->assertCount(1, $back['memenuhi']);
        $this->assertSame([], $back['kedaluwarsa']);

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------- subkontraktor

    public function test_only_subcontractor_vendors_reach_the_subcontractor_list(): void
    {
        Vendor::query()->create([
            'code' => 'VND-9010', 'name' => 'CV Karya Sipil Sejahtera', 'classification' => 'sipil',
            'vendor_type' => VendorType::Subcontractor->value, 'status' => 'active',
        ]);
        Vendor::query()->create([
            'code' => 'VND-9011', 'name' => 'PT Semen Distribusi Utama', 'classification' => 'material',
            'vendor_type' => VendorType::Supplier->value, 'status' => 'active',
        ]);
        Vendor::query()->create([
            'code' => 'VND-9012', 'name' => 'CV Subkon Nonaktif', 'classification' => 'me',
            'vendor_type' => VendorType::Subcontractor->value, 'status' => 'inactive',
        ]);

        $names = array_column($this->qualification()->subcontractors(), 'name');

        $this->assertSame(['CV Karya Sipil Sejahtera'], $names);
    }
}
