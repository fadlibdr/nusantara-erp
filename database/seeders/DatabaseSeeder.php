<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed order matters: master data (customers, employees, items) must exist
     * before the documents that reference them. Modules not listed here run last.
     */
    protected array $moduleOrder = [
        'Core',
        'Iam',
        'Crm',
        'HrPayroll',
        'Inventory',
        'Estimation',
        'Projects',
        'Procurement',
        'Assets',
        'Subcontract',
        'Finance',
        'ServiceDesk',
    ];

    public function run(): void
    {
        $onDisk = collect(glob(base_path('Modules/*'), GLOB_ONLYDIR))
            ->map(fn ($dir) => basename($dir));

        $ordered = collect($this->moduleOrder)
            ->filter(fn ($module) => $onDisk->contains($module))
            ->merge($onDisk->reject(fn ($module) => in_array($module, $this->moduleOrder)));

        foreach ($ordered as $module) {
            $class = "Modules\\{$module}\\Database\\Seeders\\{$module}DatabaseSeeder";

            if (class_exists($class)) {
                $this->call($class);
            }
        }
    }
}
