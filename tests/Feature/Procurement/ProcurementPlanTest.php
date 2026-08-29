<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\ProcurementPlan;
use Tests\ErpTestCase;

/**
 * P2 — Rencana Pengadaan / Pola Belanja (PBL).
 */
class ProcurementPlanTest extends ErpTestCase
{
    public function test_a_plan_is_created_with_its_package_rows(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->postJson('/api/procurement/procurement-plans', [
            'title' => 'Rencana pengadaan struktur lantai 1-3',
            'status' => 'active',
            'items' => [
                ['package' => 'Beton ready mix', 'method' => 'rfq', 'estimated_amount' => 850_000_000, 'target_contract_date' => '2026-09-01', 'pic' => 'Andi'],
                ['package' => 'Besi beton', 'method' => 'tender', 'estimated_amount' => 1_200_000_000, 'pic' => 'Andi'],
            ],
        ])->assertStatus(201);

        $this->assertStringStartsWith('PBL/', (string) $response->json('data.code'));

        $plan = ProcurementPlan::query()->with('items')->findOrFail((int) $response->json('data.id'));
        $this->assertCount(2, $plan->items);
        $this->assertSame('rfq', $plan->items->first()->method->value);
        $this->assertSame('tender', $plan->items->last()->method->value);
    }

    public function test_updating_a_plan_replaces_its_rows(): void
    {
        Sanctum::actingAs($this->adminUser());

        $id = (int) $this->postJson('/api/procurement/procurement-plans', [
            'title' => 'Rencana awal',
            'items' => [['package' => 'Paket lama', 'method' => 'rfq']],
        ])->assertStatus(201)->json('data.id');

        $this->putJson("/api/procurement/procurement-plans/{$id}", [
            'title' => 'Rencana direvisi',
            'items' => [
                ['package' => 'Paket baru A', 'method' => 'penunjukan_langsung'],
                ['package' => 'Paket baru B', 'method' => 'pembelian_langsung'],
            ],
        ])->assertOk();

        $plan = ProcurementPlan::query()->with('items')->findOrFail($id);
        $this->assertSame('Rencana direvisi', $plan->title);
        $this->assertCount(2, $plan->items);
        $this->assertSame('Paket baru A', $plan->items->first()->package);
    }

    public function test_an_unknown_method_is_refused(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/procurement/procurement-plans', [
            'title' => 'Rencana',
            'items' => [['package' => 'X', 'method' => 'sulap']],
        ])->assertStatus(422);
    }
}
