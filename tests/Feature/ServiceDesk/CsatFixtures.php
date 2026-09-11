<?php

namespace Tests\Feature\ServiceDesk;

use App\Models\User;
use Modules\Crm\Models\Customer;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\ServiceDesk\Enums\TicketStatus;
use Modules\ServiceDesk\Models\ServiceContract;
use Modules\ServiceDesk\Models\Ticket;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixture bersama uji CSAT (F-9). Statis, bukan trait berkeadaan: setiap uji
 * membangun apa yang dibutuhkannya dan tidak mewarisi keadaan uji sebelumnya.
 */
final class CsatFixtures
{
    public static function customer(string $name = 'RS Medika Husada'): Customer
    {
        return Customer::query()->firstOrCreate(['name' => $name], [
            'is_pkp' => true,
            'status' => 'active',
        ]);
    }

    public static function contract(): ServiceContract
    {
        return ServiceContract::query()->firstOrCreate(
            ['name' => 'Kontrak Pemeliharaan CCTV & Akses Kontrol'],
            [
                'customer_id' => self::customer()->id,
                'period_start' => '2026-04-01',
                'period_end' => '2027-03-31',
                'contract_value' => 480_000_000,
                'sla_response_hours' => 4,
                'sla_resolution_hours' => 24,
                'status' => 'active',
            ],
        );
    }

    public static function technician(string $name = 'Joko Susilo'): Employee
    {
        return Employee::query()->firstOrCreate(['name' => $name], [
            'code' => 'EMP-'.substr(md5($name), 0, 4),
            'nik_ktp' => substr(preg_replace('/\D/', '', md5($name)).'0000000000000000', 0, 16),
            'gender' => 'male',
            'birth_date' => '1990-04-11',
            'ptkp_status' => 'K/1',
            'join_date' => '2024-01-05',
            'employment_type' => 'tetap',
            'position' => 'Teknisi ELV',
            'department' => 'servis',
            'status' => 'active',
        ]);
    }

    /** Tiket yang SUDAH selesai — universe CSAT yang sah. */
    public static function ticket(
        TicketStatus $status = TicketStatus::Resolved,
        ?int $assignedTo = null,
        string $title = 'CCTV lobi mati total',
    ): Ticket {
        $contract = self::contract();

        return Ticket::query()->create([
            'service_contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'title' => $title,
            'description' => 'Kamera lobi utama tidak menyala sejak pagi.',
            'category' => 'incident',
            'priority' => 'high',
            'status' => $status,
            'channel' => 'phone',
            'reported_by_name' => 'Satpam Rudi',
            'reported_at' => now()->subDays(3),
            'assigned_to' => $assignedTo,
            'resolved_at' => $status === TicketStatus::Open ? null : now()->subDay(),
            'closed_at' => $status === TicketStatus::Closed ? now()->subHours(2) : null,
        ]);
    }

    /** @param  list<string>  $permissions */
    public static function userWith(array $permissions, ?int $employeeId = null, ?string $email = null): User
    {
        (new PermissionSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $key = substr(md5(implode('|', $permissions).'|'.$employeeId.'|'.$email), 0, 10);
        $role = Role::findOrCreate('peran-'.$key, 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->firstOrCreate(['email' => $email ?? ($key.'@test.local')], [
            'name' => 'Pemegang '.implode(' ', $permissions),
            'password' => 'password',
            'is_active' => true,
            'employee_id' => $employeeId,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
