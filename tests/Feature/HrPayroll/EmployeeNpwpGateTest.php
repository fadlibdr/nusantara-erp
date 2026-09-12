<?php

namespace Tests\Feature\HrPayroll;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Rules\ValidNpwp;
use Modules\HrPayroll\Models\Employee;
use Tests\ErpTestCase;

/**
 * P-3b T3b.1 — pintu tulis NPWP pegawai (POST/PUT hr/employees).
 *
 * Kolom npwp pegawai boleh memuat NIK-nya sendiri (16 digit berfungsi
 * sebagai NPWP sejak PMK 112/2022) — bentuk 16 digit diterima persis seperti
 * bentuk 15 dan 22. Kolom nik_ktp punya aturannya sendiri (digits:16) dan
 * tidak disentuh paket ini.
 */
class EmployeeNpwpGateTest extends ErpTestCase
{
    use PayrollFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->adminUser());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Karyawan Baru',
            'nik_ktp' => '3171012345678901',
            'gender' => 'male',
            'birth_date' => '1992-05-01',
            'ptkp_status' => 'TK/0',
            'join_date' => '2026-01-05',
            'employment_type' => 'tetap',
            'position' => 'Teknisi',
            'department' => 'servis',
            'base_salary' => 6_000_000,
        ], $overrides);
    }

    public function test_a_new_employee_with_a_malformed_npwp_is_refused_with_the_sentence(): void
    {
        $this->postJson('/api/hr/employees', $this->payload(['npwp' => '07.123.456.7-013']))
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);

        $this->assertSame(0, Employee::query()->count());
    }

    public function test_the_three_accepted_forms_are_stored_as_typed(): void
    {
        foreach (['07.123.456.7-013.000', '3171012345678902', '0071234567013000000001'] as $i => $npwp) {
            $response = $this->postJson('/api/hr/employees', $this->payload([
                'name' => "Karyawan {$i}",
                'nik_ktp' => '317101234567890'.$i,
                'npwp' => $npwp,
            ]))->assertCreated();

            $this->assertSame($npwp, Employee::query()->find($response->json('data.id'))->npwp);
        }
    }

    public function test_updating_to_a_malformed_npwp_is_refused_and_the_row_is_untouched(): void
    {
        $employee = $this->makeEmployee(['npwp' => '07.123.456.7-013.000']);

        $this->putJson("/api/hr/employees/{$employee->id}", ['npwp' => '07.123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);

        $this->assertSame('07.123.456.7-013.000', $employee->fresh()->npwp);
    }

    public function test_a_legacy_row_is_read_as_is_and_may_be_edited_without_touching_its_npwp(): void
    {
        $employee = $this->makeEmployee(['npwp' => 'BELUM']);

        $this->getJson("/api/hr/employees/{$employee->id}")->assertOk()->assertJsonPath('data.npwp', 'BELUM');

        $this->putJson("/api/hr/employees/{$employee->id}", ['npwp' => 'BELUM', 'position' => 'Mandor'])
            ->assertOk();
        $this->assertSame('Mandor', $employee->fresh()->position);
        $this->assertSame('BELUM', $employee->fresh()->npwp);

        $this->putJson("/api/hr/employees/{$employee->id}", ['npwp' => 'BELUM JUGA'])
            ->assertStatus(422);

        $this->putJson("/api/hr/employees/{$employee->id}", ['npwp' => '3174051506710001'])
            ->assertOk();
        $this->assertSame('3174051506710001', $employee->fresh()->npwp);
    }
}
