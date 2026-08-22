<?php

namespace Tests\Unit\Crm;

use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Http\Resources\ContractResource;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Tests\ErpTestCase;

/**
 * Contract (kontrak) arithmetic and the termin schedule:
 *
 *   ppn_amount     = value * ppn_rate / 100     (value is the DPP, excl. PPN)
 *   total_with_ppn = value + ppn_amount
 *   termin amount  = value * percent / 100, last termin absorbs the residue
 *
 * The termin percents must cover the contract exactly (sum == 100).
 */
class ContractTerminTest extends ErpTestCase
{
    use CrmFixtures;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->makeCustomer();
    }

    /**
     * Rp 10.000.000.000 konstruksi, DP 20 / progres 30 / progres 30 / BAST 15 / retensi 5.
     */
    private function makeContract(array $data = []): Contract
    {
        return $this->contracts()->create(array_merge([
            'customer_id' => $this->customer->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'scope_type' => 'construction',
            'value' => 10000000000,
            'sign_date' => '2026-01-15',
            'start_date' => '2026-02-01',
            'end_date' => '2026-12-31',
            'termins' => [
                ['name' => 'DP 20%', 'percent' => 20],
                ['name' => 'Progres 50%', 'percent' => 30],
                ['name' => 'Progres 90%', 'percent' => 30],
                ['name' => 'BAST I', 'percent' => 15],
                ['name' => 'Retensi', 'percent' => 5],
            ],
        ], $data));
    }

    // --------------------------------------------------------------- tax math

    public function test_ppn_and_total_with_ppn_are_derived_from_the_contract_value(): void
    {
        $contract = $this->makeContract();

        // 10.000.000.000 * 11 / 100 = 1.100.000.000
        $this->assertSame(11.0, (float) $contract->ppn_rate);
        $this->assertSame(1100000000.0, (float) $contract->ppn_amount);
        // 10.000.000.000 + 1.100.000.000 = 11.100.000.000
        $this->assertSame(11100000000.0, (float) $contract->total_with_ppn);
    }

    public function test_the_default_ppn_rate_comes_from_the_settings_layer(): void
    {
        $this->setSetting('tax.ppn_rate', 12.0);

        $contract = $this->makeContract();

        // 10.000.000.000 * 12 / 100 = 1.200.000.000 ; total 11.200.000.000
        $this->assertSame(12.0, (float) $contract->ppn_rate);
        $this->assertSame(1200000000.0, (float) $contract->ppn_amount);
        $this->assertSame(11200000000.0, (float) $contract->total_with_ppn);
    }

    public function test_the_terbilang_line_spells_the_total_including_ppn(): void
    {
        $contract = $this->makeContract();

        $payload = ContractResource::make($contract)->toArray(Request::create('/'));

        // 11.100.000.000 -> "Sebelas miliar seratus juta rupiah"
        $this->assertSame('Sebelas miliar seratus juta rupiah', $payload['total_terbilang']);
    }

    // ------------------------------------------------- retention and warranty

    public function test_retention_defaults_from_the_settings_layer_when_omitted(): void
    {
        $contract = $this->makeContract();

        $this->assertSame(5.0, (float) $contract->retention_pct);
        // 10.000.000.000 * 5 / 100 = 500.000.000
        $this->assertSame(500000000.0, $contract->retentionAmount());
    }

    public function test_an_overridden_retention_setting_is_picked_up(): void
    {
        $this->setSetting('projects.default_retention_pct', 7.5);

        $contract = $this->makeContract();

        $this->assertSame(7.5, (float) $contract->retention_pct);
        // 10.000.000.000 * 7,5 / 100 = 750.000.000
        $this->assertSame(750000000.0, $contract->retentionAmount());
    }

    public function test_an_explicit_retention_percent_beats_the_settings_default(): void
    {
        $this->setSetting('projects.default_retention_pct', 7.5);

        $contract = $this->makeContract(['retention_pct' => 3]);

        $this->assertSame(3.0, (float) $contract->retention_pct);
        // 10.000.000.000 * 3 / 100 = 300.000.000
        $this->assertSame(300000000.0, $contract->retentionAmount());
    }

    public function test_warranty_months_default_to_zero_and_carry_when_given(): void
    {
        $this->assertSame(0, (int) $this->makeContract()->warranty_months);

        $withWarranty = $this->makeContract(['warranty_months' => 12]);
        $this->assertSame(12, (int) $withWarranty->warranty_months);
    }

    // ----------------------------------------------------------- termin amount

    public function test_each_termin_amount_is_the_contract_value_times_its_percent(): void
    {
        $contract = $this->makeContract();

        $amounts = $contract->termins()->orderBy('termin_no')->pluck('amount')
            ->map(fn ($amount): float => (float) $amount)->all();

        // 10 M x 20/30/30/15/5 % = 2 M / 3 M / 3 M / 1,5 M / 0,5 M
        $this->assertSame([
            2000000000.0,
            3000000000.0,
            3000000000.0,
            1500000000.0,
            500000000.0,
        ], $amounts);

        $this->assertSame(10000000000.0, (float) $contract->termins()->sum('amount'));
    }

    public function test_termins_are_numbered_sequentially_from_one(): void
    {
        $contract = $this->makeContract();

        $this->assertSame([1, 2, 3, 4, 5], $contract->termins()->orderBy('termin_no')
            ->pluck('termin_no')->map(fn ($no): int => (int) $no)->all());
    }

    public function test_the_last_termin_absorbs_the_rounding_residue(): void
    {
        $contract = $this->makeContract([
            'value' => 999999,
            'termins' => [
                ['name' => 'Termin 1', 'percent' => 33.3333],
                ['name' => 'Termin 2', 'percent' => 33.3333],
                ['name' => 'Termin 3', 'percent' => 33.3334],
            ],
        ]);

        $amounts = $contract->termins()->orderBy('termin_no')->pluck('amount')
            ->map(fn ($amount): float => (float) $amount)->all();

        // 999.999 * 33,3333 / 100 = 333.332,666667 -> 333.332,67 (dua kali)
        // sisa: 999.999 - 666.665,34 = 333.333,66
        // (perhitungan naif 999.999 * 33,3334 / 100 = 333.333,67 -> meleset 1 sen)
        $this->assertSame([333332.67, 333332.67, 333333.66], $amounts);
        $this->assertSame(999999.0, (float) $contract->termins()->sum('amount'));
    }

    // ------------------------------------------------------ the 100% invariant

    public function test_a_schedule_summing_to_exactly_one_hundred_is_accepted(): void
    {
        $contract = $this->makeContract();

        $this->assertSame(5, $contract->termins()->count());
        $this->assertSame(100.0, round((float) $contract->termins()->sum('percent'), 4));
    }

    public function test_a_schedule_summing_to_99_99_throws_and_persists_nothing(): void
    {
        try {
            $this->makeContract([
                'termins' => [
                    ['name' => 'DP', 'percent' => 50],
                    ['name' => 'Pelunasan', 'percent' => 49.99],
                ],
            ]);
            $this->fail('Expected InvalidArgumentException for a 99.99% schedule.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must sum to 100', $e->getMessage());
        }

        $this->assertSame(0, Contract::query()->count());
        $this->assertSame(0, ContractTermin::query()->count());
    }

    public function test_a_schedule_summing_to_100_01_throws_and_persists_nothing(): void
    {
        try {
            $this->makeContract([
                'termins' => [
                    ['name' => 'DP', 'percent' => 50],
                    ['name' => 'Pelunasan', 'percent' => 50.01],
                ],
            ]);
            $this->fail('Expected InvalidArgumentException for a 100.01% schedule.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must sum to 100', $e->getMessage());
        }

        $this->assertSame(0, Contract::query()->count());
        $this->assertSame(0, ContractTermin::query()->count());
    }

    public function test_an_empty_schedule_throws_and_persists_nothing(): void
    {
        try {
            $this->makeContract(['termins' => []]);
            $this->fail('Expected InvalidArgumentException for an empty schedule.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('at least one termin', $e->getMessage());
        }

        $this->assertSame(0, Contract::query()->count());
    }

    public function test_replacing_the_schedule_with_a_bad_one_rolls_back_the_header_too(): void
    {
        $contract = $this->makeContract();

        try {
            $this->contracts()->update($contract, [
                'value' => 20000000000,
                'termins' => [
                    ['name' => 'DP', 'percent' => 50],
                    ['name' => 'Pelunasan', 'percent' => 49.99],
                ],
            ]);
            $this->fail('Expected InvalidArgumentException for a 99.99% schedule.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must sum to 100', $e->getMessage());
        }

        $fresh = Contract::query()->findOrFail($contract->id);

        // Nilai kontrak dan jadwal termin lama harus utuh.
        $this->assertSame(10000000000.0, (float) $fresh->value);
        $this->assertSame(11100000000.0, (float) $fresh->total_with_ppn);
        $this->assertSame(5, $fresh->termins()->count());
        $this->assertSame(10000000000.0, (float) $fresh->termins()->sum('amount'));
    }

    public function test_replacing_the_schedule_recomputes_every_amount(): void
    {
        $contract = $this->makeContract();

        $this->contracts()->update($contract, [
            'termins' => [
                ['name' => 'DP 30%', 'percent' => 30],
                ['name' => 'Pelunasan 70%', 'percent' => 70],
            ],
        ]);

        $amounts = $contract->refresh()->termins()->orderBy('termin_no')->pluck('amount')
            ->map(fn ($amount): float => (float) $amount)->all();

        // 10 M x 30% = 3 M ; sisa = 7 M
        $this->assertSame([3000000000.0, 7000000000.0], $amounts);
    }

    public function test_the_schedule_cannot_be_replaced_once_a_termin_is_billed(): void
    {
        $contract = $this->makeContract();
        $contract->termins()->orderBy('termin_no')->first()->update(['billed_at' => '2026-02-10']);

        try {
            $this->contracts()->update($contract, [
                'termins' => [['name' => 'Sekali bayar', 'percent' => 100]],
            ]);
            $this->fail('Expected LogicException when replacing a billed schedule.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('has billed termins', $e->getMessage());
        }

        $this->assertSame(5, Contract::query()->findOrFail($contract->id)->termins()->count());
    }

    public function test_changing_the_value_respreads_the_existing_termin_amounts(): void
    {
        $contract = $this->makeContract();

        $this->contracts()->update($contract, ['value' => 20000000000]);

        $amounts = $contract->refresh()->termins()->orderBy('termin_no')->pluck('amount')
            ->map(fn ($amount): float => (float) $amount)->all();

        // Persen tetap 20/30/30/15/5 atas nilai baru 20 M
        $this->assertSame([
            4000000000.0,
            6000000000.0,
            6000000000.0,
            3000000000.0,
            1000000000.0,
        ], $amounts);

        // 20.000.000.000 * 11 / 100 = 2.200.000.000 ; total 22.200.000.000
        $this->assertSame(2200000000.0, (float) $contract->ppn_amount);
        $this->assertSame(22200000000.0, (float) $contract->total_with_ppn);
    }

    // --------------------------------------------------------------- activate

    public function test_activating_a_draft_with_a_complete_schedule_approves_it(): void
    {
        $contract = $this->makeContract();

        $this->contracts()->activate($contract);

        $this->assertSame(DocumentStatus::Approved, $contract->refresh()->status);
    }

    public function test_activating_a_contract_without_a_schedule_throws(): void
    {
        $contract = $this->makeContract();
        $contract->termins()->delete();

        try {
            $this->contracts()->activate($contract);
            $this->fail('Expected LogicException when activating without a schedule.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('has no termin schedule', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Draft, Contract::query()->findOrFail($contract->id)->status);
    }

    public function test_activating_a_contract_whose_schedule_drifted_off_one_hundred_throws(): void
    {
        $contract = $this->makeContract();
        // Someone edited a termin row directly: 20 + 30 + 30 + 15 + 5 -> 90.
        $contract->termins()->orderByDesc('termin_no')->first()->update(['percent' => 0]);
        $contract->termins()->where('percent', 15)->update(['percent' => 10]);

        try {
            $this->contracts()->activate($contract);
            $this->fail('Expected LogicException when activating a drifted schedule.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('expected 100', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Draft, Contract::query()->findOrFail($contract->id)->status);
    }

    public function test_an_activated_contract_can_no_longer_be_edited(): void
    {
        $contract = $this->makeContract();
        $this->contracts()->activate($contract);

        try {
            $this->contracts()->update($contract, ['value' => 1]);
            $this->fail('Expected LogicException when editing an approved contract.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        $this->assertSame(10000000000.0, (float) Contract::query()->findOrFail($contract->id)->value);
    }
}
