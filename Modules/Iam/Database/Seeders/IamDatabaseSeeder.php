<?php

namespace Modules\Iam\Database\Seeders;

use Illuminate\Database\Seeder;

class IamDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
        ]);
    }
}
