<?php

namespace Tests\Unit\Subcontract;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Subcontract\Models\LaborClaim;
use Modules\Subcontract\Models\LaborContract;
use Modules\Subcontract\Models\LaborContractItem;
use Modules\Subcontract\Services\LaborClaimService;
use Modules\Subcontract\Services\LaborContractService;

/**
 * Hand-built SP3 / opname-mandor fixtures (P4), in the taste of
 * SubcontractFixtures: rows are assembled dumb, every number under assertion
 * is spelled out by the test that asserts it.
 */
trait LaborFixtures
{
    private ?Vendor $fixtureMandor = null;

    private ?Project $fixtureLaborProject = null;

    private ?User $fixtureLaborActor = null;

    private ?User $fixtureLaborApprover = null;

    protected function laborContracts(): LaborContractService
    {
        return app(LaborContractService::class);
    }

    protected function laborClaims(): LaborClaimService
    {
        return app(LaborClaimService::class);
    }

    /**
     * Mandor SEHAT — vendor_type mandor DENGAN komitmen K3L + pakta
     * integritas, karena sejak P4 gate prakualifikasi menagih keduanya juga
     * dari mandor. Kirim 'k3l_documents' => false untuk mandor terblokir.
     */
    protected function makeMandor(array $attributes = []): Vendor
    {
        $withDocuments = (bool) ($attributes['k3l_documents'] ?? true);
        unset($attributes['k3l_documents']);

        $vendor = Vendor::create(array_merge([
            'name' => 'Mandor Pak Harjo',
            'classification' => 'jasa',
            'is_pkp' => false,
            'vendor_type' => 'mandor',
            'status' => 'active',
        ], $attributes));

        if ($withDocuments) {
            foreach ([
                ['doc_type' => 'k3l_commitment', 'name' => 'Komitmen K3L'],
                ['doc_type' => 'pakta_integritas', 'name' => 'Pakta Integritas'],
            ] as $document) {
                $vendor->documents()->create($document + [
                    'is_mandatory' => true,
                    'valid_until' => null,
                ]);
            }
        }

        return $vendor;
    }

    protected function defaultMandor(): Vendor
    {
        return $this->fixtureMandor ??= $this->makeMandor();
    }

    protected function defaultLaborProject(): Project
    {
        return $this->fixtureLaborProject ??= Project::create([
            'name' => 'Gedung Kantor Pusat',
            'type' => 'construction',
        ]);
    }

    protected function laborActor(): User
    {
        return $this->fixtureLaborActor ??= User::query()->create([
            'name' => 'Site Manager',
            'email' => 'site-manager-p4@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    protected function laborApprover(): User
    {
        return $this->fixtureLaborApprover ??= User::query()->create([
            'name' => 'Manajer Proyek P4',
            'email' => 'pm-p4@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function makeLaborContract(array $attributes = [], array $lines = []): LaborContract
    {
        /** @var LaborContract $contract */
        $contract = LaborContract::create(array_merge([
            'vendor_id' => $this->defaultMandor()->id,
            'project_id' => $this->defaultLaborProject()->id,
            'title' => 'Upah borongan pasangan bata',
            'value' => 0,
            'ppn_rate' => 0,
            'pph_scheme' => 'final_umkm',
            'pph_rate' => 0.5,
            'start_date' => '2026-02-01',
            'status' => DocumentStatus::Draft,
        ], $attributes));

        foreach ($lines as $line) {
            $this->addLaborLine($contract, $line);
        }

        return $contract;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function makeApprovedLaborContract(array $attributes = [], array $lines = []): LaborContract
    {
        return $this->makeLaborContract(
            array_merge($attributes, ['status' => DocumentStatus::Approved]),
            $lines,
        );
    }

    protected function addLaborLine(LaborContract $contract, array $attributes = []): LaborContractItem
    {
        /** @var LaborContractItem $item */
        $item = $contract->items()->create(array_merge([
            'line_no' => $contract->items()->count() + 1,
            'description' => 'Pasangan bata merah',
            'qty' => 100,
            'unit' => 'm2',
            'unit_rate' => 50000,
            'amount' => 5000000,
        ], $attributes));

        return $item;
    }

    /**
     * Draft an opname mandor covering [labor_contract_item_id => qty_this].
     *
     * @param  array<int, float|int>  $volumes
     */
    protected function draftLaborClaim(LaborContract $contract, array $volumes, array $attributes = []): LaborClaim
    {
        $items = [];

        foreach ($volumes as $itemId => $qtyThis) {
            $items[] = [
                'labor_contract_item_id' => $itemId,
                'qty_this' => $qtyThis,
            ];
        }

        return $this->laborClaims()->createClaim(array_merge([
            'labor_contract_id' => $contract->id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'items' => $items,
        ], $attributes));
    }

    /**
     * @param  array<int, float|int>  $volumes
     */
    protected function approvedLaborClaim(LaborContract $contract, array $volumes, array $attributes = []): LaborClaim
    {
        $claim = $this->draftLaborClaim($contract, $volumes, $attributes);
        $claim->submit($this->laborActor());

        return $this->laborClaims()->approve($claim->refresh(), $this->laborApprover());
    }
}
