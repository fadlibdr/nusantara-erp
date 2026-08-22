<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\ItemCategory;

/**
 * Master data: item categories only — no items, warehouses or stock
 * documents. Safe for production; extracted from InventoryDatabaseSeeder so
 * ProductionSeeder can reuse it. Idempotent (updateOrCreate by code).
 */
class ItemCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'SIPIL', 'name' => 'Material Sipil'],
            ['code' => 'ME', 'name' => 'Material ME'],
            ['code' => 'ICT', 'name' => 'Perangkat ICT'],
            ['code' => 'SPAREPART', 'name' => 'Sparepart'],
            ['code' => 'ALAT', 'name' => 'Alat Bantu'],
        ];

        foreach ($categories as $category) {
            ItemCategory::withTrashed()->updateOrCreate(
                ['code' => $category['code']],
                $category + ['parent_id' => null],
            );
        }
    }
}
