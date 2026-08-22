<?php

namespace Modules\Iam\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserSeeder extends Seeder
{
    /**
     * One demo user per role: <role>@nusantara.test / password "password".
     * Users are linked to canonical HrPayroll employees (EMP-xxxx) where a
     * matching employee exists in the seed canon.
     */
    public function run(): void
    {
        $users = [
            ['role' => 'admin',           'name' => 'Administrator Sistem', 'employee_code' => null],
            ['role' => 'direktur',        'name' => 'Budi Santoso',         'employee_code' => 'EMP-0001'],
            ['role' => 'project-manager', 'name' => 'Rina Wijaya',          'employee_code' => 'EMP-0002'],
            ['role' => 'site-manager',    'name' => 'Agus Prasetyo',        'employee_code' => 'EMP-0003'],
            ['role' => 'estimator',       'name' => 'Made Wirawan',         'employee_code' => 'EMP-0008'],
            ['role' => 'procurement',     'name' => 'Andi Kurniawan',       'employee_code' => 'EMP-0005'],
            ['role' => 'warehouse',       'name' => 'Hendra Gunawan',       'employee_code' => null],
            ['role' => 'finance',         'name' => 'Dewi Lestari',         'employee_code' => 'EMP-0004'],
            // The second pair of eyes on finance, out of the box: without a
            // finance-manager login a fresh install has only direktur and admin
            // able to approve a bill Dewi raised, and the first thing an
            // operator does about that is share a password.
            ['role' => 'finance-manager', 'name' => 'Ratna Kusumawardani',  'employee_code' => null],
            ['role' => 'hr',              'name' => 'Siti Rahayu',          'employee_code' => 'EMP-0006'],
            ['role' => 'sales',           'name' => 'Maya Puspita',         'employee_code' => null],
            ['role' => 'teknisi',         'name' => 'Joko Susilo',          'employee_code' => 'EMP-0007'],
        ];

        $hasEmployees = Schema::hasTable('hr_employees');

        foreach ($users as $definition) {
            $employeeId = null;

            if ($hasEmployees && $definition['employee_code'] !== null) {
                // Cross-module lookup by canon code; null when HrPayroll
                // hasn't been seeded yet (skip gracefully).
                $employeeId = DB::table('hr_employees')
                    ->where('code', $definition['employee_code'])
                    ->whereNull('deleted_at')
                    ->value('id');
            }

            /** @var User $user */
            $user = User::query()->updateOrCreate(
                ['email' => "{$definition['role']}@nusantara.test"],
                [
                    'name' => $definition['name'],
                    'password' => 'password', // hashed by the model's "hashed" cast
                    'employee_id' => $employeeId,
                    'is_active' => true,
                ],
            );

            // email_verified_at is not mass-assignable on User; set explicitly.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->syncRoles([$definition['role']]);
        }
    }
}
