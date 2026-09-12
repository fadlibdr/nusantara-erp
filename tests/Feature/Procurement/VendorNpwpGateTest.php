<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Rules\ValidNpwp;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * P-3b T3b.1 — pintu tulis NPWP vendor (POST/PUT procurement/vendors).
 * Aturan yang sama dengan pelanggan, pegawai dan profil perusahaan.
 */
class VendorNpwpGateTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->adminUser());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'PT Vendor Baru', 'classification' => 'material', 'status' => 'active'], $overrides);
    }

    public function test_a_new_vendor_with_a_malformed_npwp_is_refused_with_the_sentence(): void
    {
        $this->postJson('/api/procurement/vendors', $this->payload(['npwp' => '01.334.556.7-007']))
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);

        $this->assertSame(0, Vendor::query()->count());
    }

    public function test_the_three_accepted_forms_are_stored_as_typed(): void
    {
        foreach (['01.334.556.7-007.000', '0013345567007000', '0013345567007000000001'] as $i => $npwp) {
            $response = $this->postJson('/api/procurement/vendors', $this->payload(['name' => "PT Vendor {$i}", 'npwp' => $npwp]))
                ->assertCreated();

            $this->assertSame($npwp, Vendor::query()->find($response->json('data.id'))->npwp);
        }
    }

    public function test_updating_to_a_malformed_npwp_is_refused_and_the_row_is_untouched(): void
    {
        $vendor = Vendor::query()->create($this->payload(['npwp' => '01.334.556.7-007.000']));

        $this->putJson("/api/procurement/vendors/{$vendor->id}", ['npwp' => '12.345'])
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);

        $this->assertSame('01.334.556.7-007.000', $vendor->fresh()->npwp);
    }

    public function test_a_legacy_row_is_read_as_is_and_may_be_edited_without_touching_its_npwp(): void
    {
        $vendor = Vendor::query()->create($this->payload(['name' => 'CV Warisan', 'npwp' => 'N/A']));

        $this->getJson("/api/procurement/vendors/{$vendor->id}")->assertOk()->assertJsonPath('data.npwp', 'N/A');

        $this->putJson("/api/procurement/vendors/{$vendor->id}", ['name' => 'CV Warisan', 'npwp' => 'N/A', 'city' => 'Bekasi'])
            ->assertOk();
        $this->assertSame('Bekasi', $vendor->fresh()->city);
        $this->assertSame('N/A', $vendor->fresh()->npwp);

        $this->putJson("/api/procurement/vendors/{$vendor->id}", ['name' => 'CV Warisan', 'npwp' => 'N/B'])
            ->assertStatus(422);

        $this->putJson("/api/procurement/vendors/{$vendor->id}", ['name' => 'CV Warisan', 'npwp' => '0013345567007000'])
            ->assertOk();
        $this->assertSame('0013345567007000', $vendor->fresh()->npwp);
    }
}
