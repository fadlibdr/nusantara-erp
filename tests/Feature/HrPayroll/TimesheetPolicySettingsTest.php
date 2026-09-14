<?php

namespace Tests\Feature\HrPayroll;

use Modules\Core\Models\AuditLog;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\Erp;
use Tests\ErpTestCase;

/**
 * KEBIJAKAN SEBAGAI SETELAN, BUKAN ANGKA DI KODE (F-5, T5.1).
 *
 * Baris roadmap F-5 menunda paket ini karena "tanpa [satu bulan data] aturan
 * pembulatan lembur dikarang". Pemilik mencabut penundaan itu dengan menyebut
 * aturannya sendiri pada 14 September 2026. Berkas ini adalah tempat angka-angka
 * yang ia sebut dipaku, supaya sebuah suntingan yang menggesernya diam-diam
 * jatuh merah — dan supaya bentuk pengirimannya (SETELAN, bukan konstanta)
 * tidak bisa dibatalkan tanpa satu uji berubah warna.
 *
 * Dua hal yang dipaku di sini dan tidak di tempat lain:
 *
 *  - setiap kunci BISA DISUNTING operator lewat registri Pengaturan. Sebuah
 *    konstanta di kelas layanan akan lulus setiap uji perhitungan di
 *    TimesheetDerivationTest dan tetap salah menurut perintah paket ini.
 *  - kalimat pembuka grup `hr` tidak boleh lagi berbunyi "TIDAK satu pun dari
 *    nilai di sini yang langsung menggerakkan payroll". Kalimat itu BENAR
 *    sebelum F-5 dan menjadi BOHONG sesudahnya, dan kebohongan itu berada tepat
 *    di atas kotak isian yang menggeser upah lembur setiap orang.
 */
class TimesheetPolicySettingsTest extends ErpTestCase
{
    /**
     * Kebijakan pemilik, 14 Sep 2026 — kunci => bawaan.
     *
     * Ditulis di sini sebagai angka LITERAL, bukan dibaca dari config: sebuah
     * uji yang membaca harapannya sendiri dari kode produksi tidak pernah bisa
     * merah (pelajaran Fase 2, "pin yang membaca harapannya sendiri").
     */
    private const POLICY = [
        'hr.timesheet.day_start' => '08:00',
        'hr.timesheet.late_tolerance_minutes' => 10,
        'hr.timesheet.normal_hours_per_day' => 8,
        'hr.timesheet.rounding_minutes' => 15,
        'hr.timesheet.overtime_minimum_minutes' => 30,
        'hr.timesheet.overtime_daily_cap_hours' => 3,
        'hr.timesheet.overtime_weekly_cap_hours' => 14,
        'hr.timesheet.overtime_first_hour_pct' => 150,
        'hr.timesheet.overtime_next_hours_pct' => 200,
    ];

    public function test_the_owner_named_defaults_are_what_ships(): void
    {
        foreach (self::POLICY as $key => $expected) {
            $this->assertSame(
                $expected,
                config("erp.{$key}"),
                "Bawaan {$key} bukan angka yang disebut pemilik 14 Sep 2026.",
            );
        }
    }

    public function test_every_policy_value_is_an_editable_setting_and_not_a_constant(): void
    {
        $editable = app(SettingService::class)->editableKeys();

        foreach (array_keys(self::POLICY) as $key) {
            $this->assertArrayHasKey(
                $key,
                $editable,
                "{$key} tidak ada di registri Pengaturan. Kebijakan yang hanya hidup di config/erp.php "
                .'adalah kebijakan yang menuntut deploy untuk diubah — persis bentuk yang perintah '
                .'paket ini tolak.',
            );
            $this->assertSame('hr', $editable[$key]['group'], "{$key} harus berada di grup Pengaturan 'hr'.");
            $this->assertNotSame('', trim((string) ($editable[$key]['help'] ?? '')), "{$key} tanpa teks bantuan.");
        }
    }

    public function test_the_hr_group_no_longer_claims_that_nothing_in_it_moves_payroll(): void
    {
        $group = app(SettingService::class)->definitions()['hr'];

        $this->assertStringNotContainsString(
            'TIDAK satu pun dari nilai di sini yang langsung menggerakkan payroll',
            $group['description'],
            'Kalimat itu benar sampai 13 Sep 2026 dan menjadi bohong pada 14 Sep: sembilan kunci '
            .'hr.timesheet.* di grup ini menentukan berapa menit dihitung lembur dan dengan tarif '
            .'berapa dibayar. Kalimat yang menyangkal itu berdiri tepat di atas kotak isiannya.',
        );
        $this->assertStringContainsString(
            'menggeser uang',
            $group['description'],
            'Pembaca layar Pengaturan harus diberi tahu bahwa bagian timesheet grup ini menggerakkan upah.',
        );
    }

    public function test_an_operator_can_change_a_policy_value_and_the_engine_reads_the_new_one(): void
    {
        app(SettingService::class)->set('hr.timesheet.rounding_minutes', 30);

        $this->assertSame(30, Erp::int('hr.timesheet.rounding_minutes', 15));
    }

    /**
     * Jam mulai kerja adalah JAM, bukan teks bebas.
     *
     * Tipe 'time' baru di registri Pengaturan memakai date_format:H:i. Yang
     * diuji di sini bukan Laravel-nya, melainkan bahwa kuncinya benar-benar
     * memakai tipe itu: dengan tipe bawaan (string max:255) '25:61' tersimpan
     * apa adanya dan layar timesheet kemudian membaca jam mulai yang tidak ada.
     */
    public function test_the_day_start_refuses_anything_that_is_not_a_wall_clock(): void
    {
        $settings = app(SettingService::class);

        foreach (['8:00', '25:00', '08:00:00', 'delapan pagi', '0800'] as $bad) {
            try {
                $settings->set('hr.timesheet.day_start', $bad);
                $this->fail("Jam mulai '{$bad}' diterima. Jam mulai yang tidak bisa dibaca membuat "
                    .'setiap penilaian "terlambat" di layar timesheet tidak punya acuan.');
            } catch (\InvalidArgumentException) {
                // benar
            }
        }

        $settings->set('hr.timesheet.day_start', '07:30');
        $this->assertSame('07:30', Erp::string('hr.timesheet.day_start', '08:00'));
    }

    public function test_the_api_refuses_a_malformed_day_start_with_a_sentence_about_clocks(): void
    {
        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson('api/core/settings', ['settings' => ['hr.timesheet.day_start' => '8 pagi']])
            ->assertStatus(422);

        $this->assertSame(
            'Nilai Jam mulai kerja (acuan terlambat) harus berupa jam 24 dan menit, mis. 08:00 atau 17:30.',
            $response->json('errors')['settings.hr.timesheet.day_start'][0],
            'Kalimat galat jam mulai tidak boleh jatuh ke kalimat aturan regex milik format penomoran '
            .'dokumen — ia akan menyuruh orang menambahkan token {N4} ke dalam sebuah jam.',
        );
    }

    /**
     * T5.6 — perubahan kebijakan masuk audit, karena ia menggeser uang.
     *
     * Baris core_settings sudah diamati AuditedModels sejak P0; yang dipaku di
     * sini adalah bahwa kunci-kunci BARU ini benar-benar menempuh jalur tulis
     * itu (SettingService::set → Setting::updateOrCreate), bukan jalur pintas
     * yang melewati pengamatnya.
     */
    public function test_changing_the_overtime_rate_leaves_an_audit_row(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson('api/core/settings', ['settings' => ['hr.timesheet.overtime_next_hours_pct' => 250]])
            ->assertOk();

        $row = AuditLog::query()
            ->where('auditable_type', Setting::class)
            ->where('auditable_label', 'hr.timesheet.overtime_next_hours_pct')
            ->first();

        $this->assertNotNull(
            $row,
            'Menaikkan tarif lembur jam kedua dari 200% ke 250% tidak meninggalkan jejak. '
            .'Perubahan yang menggeser upah setiap orang harus punya nama pelaku dan tanggal.',
        );
    }
}
