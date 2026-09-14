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

    /**
     * "DARI" ADALAH SEPARUH YANG PENTING, dan ia hilang justru pada perubahan
     * PERTAMA.
     *
     * Kesepuluh kunci `hr.timesheet.*` dikirim sebagai bawaan config tanpa
     * baris `core_settings`. Perubahan pertamanya karena itu tercatat pengamat
     * sebagai `created` dengan `value: {from: null, to: "175"}` — nol jejak
     * bahwa yang berlaku sebelumnya adalah 150% yang disebut pemilik. Padahal
     * itulah satu-satunya perubahan yang meninggalkan kebijakan pemilik.
     *
     * Rumah ini SUDAH tahu cacat ini: komentar `SettingService::set()`
     * menuliskannya kata demi kata untuk `approvals.*` dan membangun jalur
     * audit "efektif dari→ke" untuk menutupnya. F-5 menambahkan sepuluh kunci
     * yang menggerakkan upah lembur setiap orang dan tidak memperluasnya;
     * PANDUAN-ADMINISTRATOR §14 menjanjikan "nilai dari→ke".
     *
     * Uji lama hanya memeriksa BARISNYA ada. Ini memeriksa ISINYA.
     */
    public function test_the_first_change_of_a_timesheet_key_records_the_value_it_replaced(): void
    {
        $admin = $this->adminUser();

        $this->assertFalse(
            app(SettingService::class)->isOverridden('hr.timesheet.overtime_first_hour_pct'),
            'Prasyarat: kuncinya belum punya baris core_settings sama sekali.',
        );

        $this->actingAs($admin, 'sanctum')
            ->putJson('api/core/settings', ['settings' => ['hr.timesheet.overtime_first_hour_pct' => 175]])
            ->assertOk();

        $first = AuditLog::query()
            ->where('auditable_type', Setting::class)
            ->where('auditable_label', 'hr.timesheet.overtime_first_hour_pct')
            ->orderBy('id')
            ->get();

        $effective = $first->pluck('changes')->filter(fn ($changes): bool => isset($changes['effective']))->first();

        $this->assertNotNull(
            $effective,
            'Perubahan pertama sebuah kunci timesheet tidak mencatat nilai efektif yang ia gantikan. '
            .'Penyelidikan atas "kenapa upah lembur turun bulan ini" lalu membaca satu baris log yang '
            .'tidak menyebut angka sebelumnya.',
        );
        $this->assertSame(150, (int) $effective['effective']['from'], 'Bawaan config 150%, bukan null.');
        $this->assertSame(175, (int) $effective['effective']['to']);
    }

    /** ...dan perubahan berikutnya membawa nilai yang benar-benar berlaku sebelumnya. */
    public function test_a_later_change_records_the_value_that_was_actually_in_force(): void
    {
        $admin = $this->adminUser();

        foreach ([175, 190] as $value) {
            $this->actingAs($admin, 'sanctum')
                ->putJson('api/core/settings', ['settings' => ['hr.timesheet.overtime_first_hour_pct' => $value]])
                ->assertOk();
        }

        $effective = AuditLog::query()
            ->where('auditable_type', Setting::class)
            ->where('auditable_label', 'hr.timesheet.overtime_first_hour_pct')
            ->orderByDesc('id')
            ->get()
            ->pluck('changes')
            ->filter(fn ($changes): bool => isset($changes['effective']))
            ->first();

        $this->assertSame(175, (int) $effective['effective']['from']);
        $this->assertSame(190, (int) $effective['effective']['to']);
    }

    /* --------------------------------------------------- putaran penutup (V-1) */

    /**
     * V-1 — KUNCI CUTI YANG MENGGESER UANG HARUS MENGATAKANNYA.
     *
     * Putaran verifikasi menutup C-2 dengan mengganti penyangkalan menyeluruh
     * menjadi PEMBEDAAN: "cuti dan radius absensi TIDAK menggerakkan payroll —
     * kebijakan timesheet BERBEDA". Pembedaan itu sendiri tidak benar.
     * `hr.leave.workweek_days` adalah kunci cuti, dan sejak F-5 ia memutuskan
     * hari mana NON-KERJA — dan lembur hari non-kerja ditahan. Akibatnya satu
     * kotak isian di bagian Cuti menghentikan jam lembur Sabtu menjadi uang
     * (TimesheetDerivationTest memakunya dengan angka).
     *
     * Sebuah kalimat yang menenangkan orang tentang kunci yang sebenarnya
     * berbahaya lebih buruk daripada tidak ada kalimat: ia MEMBUAT orang berani
     * menyuntingnya.
     */
    public function test_the_leave_workweek_key_is_named_as_one_that_moves_money(): void
    {
        $group = app(SettingService::class)->definitions()['hr'];
        $description = (string) ($group['description'] ?? '');

        $this->assertStringNotContainsString(
            'Cuti dan radius absensi TIDAK menggerakkan payroll',
            $description,
            'Kalimat itu menyatakan seluruh bagian Cuti aman disunting. Satu kuncinya tidak aman.',
        );
        $this->assertStringContainsString(
            'Hari kerja per pekan',
            $description,
            'Deskripsi grup harus MENYEBUT kunci cuti yang menggeser uang, bukan menyapu bersih '
            .'seluruh bagian cuti ke sisi yang aman.',
        );

        $workweek = collect($group['settings'] ?? [])->firstWhere('key', 'hr.leave.workweek_days');
        $this->assertNotNull($workweek, 'Kunci hr.leave.workweek_days hilang dari grup hr.');
        $this->assertIsArray($workweek);

        $this->assertStringContainsString(
            'Timesheet',
            (string) ($workweek['help'] ?? ''),
            'Teks bantuan kunci ini hanya berbicara tentang saldo cuti. Orang yang membacanya tidak '
            .'akan tahu bahwa ia sedang memegang jam lembur Sabtu milik setiap orang.',
        );
    }

    /**
     * V-1 — dan perubahannya meninggalkan jejak.
     *
     * `auditsEffectiveChange()` adalah daftar kunci yang menggeser uang. Nama
     * kunci tidak menentukan keanggotaannya; akibatnya yang menentukan.
     */
    public function test_changing_the_leave_workweek_key_is_audited_like_a_money_change(): void
    {
        $this->assertTrue(
            SettingService::auditsEffectiveChange('hr.leave.workweek_days'),
            'Kunci yang menghentikan jam lembur Sabtu menjadi uang harus meninggalkan jejak siapa yang '
            .'menggesernya, sama seperti setiap kunci hr.timesheet.*.',
        );

        // Dua arah, supaya pin ini tidak lulus hanya karena SETIAP suntingan
        // Pengaturan kebetulan diaudit: hak cuti tahunan adalah kunci cuti yang
        // TIDAK menggeser uang lembur, dan ia tidak boleh meninggalkan jejak
        // "perubahan efektif" yang sama.
        $this->assertFalse(
            SettingService::auditsEffectiveChange('hr.leave.annual_days'),
            'Hak cuti tahunan tidak menggeser upah lembur; memasukkannya ke daftar ini membuat daftarnya '
            .'berhenti berarti apa-apa.',
        );

        // Yang dihitung adalah baris PERUBAHAN EFEKTIF (`updated` + kunci
        // `effective`), bukan sembarang baris audit: menyimpan override
        // Pengaturan pertama kali juga menulis satu baris `created` dari
        // observer model, dan menghitung keduanya membuat pin ini lulus walau
        // jejak uangnya hilang.
        $efektif = fn (): int => AuditLog::query()
            ->where('auditable_label', 'hr.leave.workweek_days')
            ->where('event', 'updated')
            ->count();

        $sebelum = $efektif();
        app(SettingService::class)->set('hr.leave.workweek_days', 5);

        $this->assertSame(
            $sebelum + 1,
            $efektif(),
            'Menyunting kunci yang menghentikan jam lembur Sabtu menjadi uang tidak meninggalkan baris '
            .'"perubahan efektif": tidak ada yang bisa menjawab "siapa yang mengubahnya, dari berapa '
            .'ke berapa, dan kapan".',
        );
    }
}
