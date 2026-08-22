<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\ErpTestCase;

/**
 * The HTTP contract around the payroll math: the {data, message} envelope on success
 * and a 422 carrying the service's LogicException message on a business-rule breach.
 */
class PayrollRunApiTest extends ErpTestCase
{
    use PayrollFixtures;

    private function actAsAdmin(): User
    {
        $user = $this->adminUser();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A second pair of eyes for the approve step. Whoever submits a payroll run
     * may not approve it, so the acting admin cannot do both ends.
     */
    private function payrollApprover(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'manajer-hr@test.local'],
            ['name' => 'Manajer HR', 'password' => 'password', 'is_active' => true],
        );
    }

    public function test_calculate_returns_the_run_with_its_payslips(): void
    {
        $this->actAsAdmin();
        // 8.000.000 + 1.000.000 = 9.000.000 gross; TER A 1,75% = 157.500;
        // BPJS employee 360.000; net = 9.000.000 - 517.500 = 8.482.500
        $this->makeEmployee([
            'base_salary' => 8_000_000,
            'fixed_allowances' => ['transport' => 500_000, 'makan' => 500_000],
        ]);
        $run = $this->makeRun();

        $response = $this->postJson("/api/hr/payroll-runs/{$run->id}/calculate");

        $response->assertOk();
        $this->assertSame('Payroll calculated for 1 employees.', $response->json('message'));
        $this->assertMoney(9_000_000.0, $response->json('data.total_gross'));
        $this->assertMoney(517_500.0, $response->json('data.total_deductions'));
        $this->assertMoney(8_482_500.0, $response->json('data.total_net'));
    }

    public function test_calculating_an_approved_run_answers_422_with_the_business_message(): void
    {
        $admin = $this->actAsAdmin();
        $this->makeEmployee(['base_salary' => 9_000_000]);
        $run = $this->makeRun();
        $this->payrollService()->calculate($run);
        $run->submit($admin);
        $run->approve($this->payrollApprover());

        $response = $this->postJson("/api/hr/payroll-runs/{$run->id}/calculate");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'cannot be modified while status is approved',
            (string) $response->json('message'),
        );

        // The approved figures survived the refused call.
        $run->refresh();
        $this->assertSame(1, $run->payslips()->count());
        $this->assertMoney(9_000_000.0, $run->total_gross);
    }

    public function test_updating_an_approved_run_answers_422(): void
    {
        $admin = $this->actAsAdmin();
        $this->makeEmployee(['base_salary' => 9_000_000]);
        $run = $this->makeRun();
        $this->payrollService()->calculate($run);
        $run->submit($admin);
        $run->approve($this->payrollApprover());

        $response = $this->putJson("/api/hr/payroll-runs/{$run->id}", [
            'period_year' => 2026,
            'period_month' => 5,
            'run_type' => 'regular',
        ]);

        $response->assertStatus(422);
        $run->refresh();
        $this->assertSame(6, $run->period_month);
        $this->assertSame(1, $run->payslips()->count());
    }

    public function test_a_run_without_payslips_cannot_be_submitted(): void
    {
        $this->actAsAdmin();
        $run = $this->makeRun();

        $response = $this->postJson("/api/hr/payroll-runs/{$run->id}/submit");

        $response->assertStatus(422);
        $this->assertStringContainsString('calculate it first', (string) $response->json('message'));
    }
}
