<?php

namespace Tests\Feature\Crm;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Rules\ValidNpwp;
use Modules\Crm\Models\Customer;
use Tests\ErpTestCase;

/**
 * P-3b T3b.1 — pintu tulis NPWP pelanggan (POST/PUT crm/customers).
 *
 * Aturan yang sama dengan vendor, pegawai dan profil perusahaan; berkas ini
 * ada supaya "ditegakkan di satu pintu, bocor di pintu lain" tidak terjadi
 * di pintu ini. Nilai lama tidak disentuh: dibaca apa adanya, dan dikirim
 * kembali tanpa perubahan bukan penulisan baru.
 */
class CustomerNpwpGateTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->adminUser());
    }

    public function test_a_new_customer_with_a_malformed_npwp_is_refused_with_the_sentence(): void
    {
        $this->postJson('/api/crm/customers', ['name' => 'PT Calon Pelanggan', 'npwp' => '01.234.567.8-011'])
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_the_three_accepted_forms_are_stored_as_typed(): void
    {
        foreach (['01.234.567.8-011.000', '0012345678011000', '0012345678011000000001'] as $i => $npwp) {
            $response = $this->postJson('/api/crm/customers', ['name' => "PT Pelanggan {$i}", 'npwp' => $npwp])
                ->assertCreated();

            $this->assertSame($npwp, $response->json('data.npwp'));
            $this->assertSame($npwp, Customer::query()->find($response->json('data.id'))->npwp);
        }
    }

    public function test_a_blank_npwp_is_still_allowed(): void
    {
        $this->postJson('/api/crm/customers', ['name' => 'PT Tanpa NPWP', 'npwp' => null])->assertCreated();
        $this->postJson('/api/crm/customers', ['name' => 'PT Tanpa NPWP Dua', 'npwp' => ''])->assertCreated();
    }

    public function test_updating_to_a_malformed_npwp_is_refused_and_the_row_is_untouched(): void
    {
        $customer = Customer::query()->create(['name' => 'PT Lama', 'npwp' => '01.234.567.8-011.000']);

        $this->putJson("/api/crm/customers/{$customer->id}", ['name' => 'PT Lama', 'npwp' => '123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);

        $this->assertSame('01.234.567.8-011.000', $customer->fresh()->npwp);
    }

    public function test_a_legacy_row_is_read_as_is_and_may_be_edited_without_touching_its_npwp(): void
    {
        $customer = Customer::query()->create(['name' => 'PT Warisan', 'npwp' => 'N/A']);

        $this->getJson("/api/crm/customers/{$customer->id}")->assertOk()->assertJsonPath('data.npwp', 'N/A');

        // Formulir SPA mengirim seluruh baris; NPWP lama ikut apa adanya.
        $this->putJson("/api/crm/customers/{$customer->id}", ['name' => 'PT Warisan', 'npwp' => 'N/A', 'city' => 'Bekasi'])
            ->assertOk();

        $this->assertSame('Bekasi', $customer->fresh()->city);
        $this->assertSame('N/A', $customer->fresh()->npwp);

        // Mengganti nilai lama dengan nilai lain yang tidak sah tetap ditolak…
        $this->putJson("/api/crm/customers/{$customer->id}", ['name' => 'PT Warisan', 'npwp' => 'N/B'])
            ->assertStatus(422);

        // …dan menggantinya dengan yang sah — atau mengosongkannya — diterima.
        $this->putJson("/api/crm/customers/{$customer->id}", ['name' => 'PT Warisan', 'npwp' => '0012345678011000'])
            ->assertOk();
        $this->assertSame('0012345678011000', $customer->fresh()->npwp);
    }
}
