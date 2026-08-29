<?php

namespace Tests\Feature\Projects;

use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Location;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\Customer;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Projects\Models\ContractVariation;
use Modules\Projects\Models\Project;

/**
 * P3 — the smallest honest world an owner opname needs: a signed contract, its
 * approved BOQ, and the project delivering it.
 *
 * Separate from BaselineFixtures on purpose. That trait rebuilds PRJ-2026-001
 * exactly as the live file holds it — 8 WBS leaves whose weights close on
 * 100,0000 — because the EVM expectations there ARE the live site's numbers.
 * An opname measures BOQ VOLUME, which that fixture deliberately does not
 * carry (its single BOQ item is a Rp 42 M lump sum for the RAP), and inventing
 * volumes on top of it would make the EVM numbers stop being the live ones.
 * Two small round quantities are what the ceiling arithmetic needs to be
 * readable in the assertion.
 *
 * Deliberately dumb, same rule as BaselineFixtures: it assembles rows and never
 * computes an expectation.
 */
trait OpnameFixtures
{
    protected Contract $contract;

    protected Project $project;

    protected Boq $boq;

    /** @var array<string, BoqItem> keyed by wbs_code */
    protected array $boqItems = [];

    /**
     * Contract CTR/2026/I/0001 at Rp 1.000.000.000 with a two-item BOQ:
     *
     *   A.1  Galian tanah      1.000 m3 x Rp 200.000  = Rp 200.000.000
     *   A.2  Beton K-350         500 m3 x Rp 1.600.000 = Rp 800.000.000
     *                                                    -------------
     *                                                    Rp 1.000.000.000
     *
     * The two amounts are 20 % / 80 % of the BOQ, so a value-weighted actual
     * percentage is arithmetic anybody can check in their head.
     */
    protected function seedOpnameWorld(): void
    {
        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);

        $this->contract = Contract::query()->create([
            'code' => 'CTR/2026/I/0001',
            'customer_id' => $customer->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'scope_type' => 'construction',
            'value' => 1_000_000_000,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'start_date' => '2026-02-02',
            'end_date' => '2027-07-31',
            'status' => DocumentStatus::Approved,
        ]);

        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'contract_id' => $this->contract->id,
            'customer_id' => $customer->id,
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-02-02',
            'end_date' => '2027-07-31',
            'contract_value' => 1_000_000_000,
            'retention_pct' => 5,
        ]);

        $this->boq = Boq::query()->create([
            'title' => 'RAB '.$this->contract->code,
            'contract_id' => $this->contract->id,
            'project_id' => $this->project->id,
            'version' => 1,
            'total' => 1_000_000_000,
            'status' => DocumentStatus::Approved,
        ]);

        $section = $this->boq->sections()->create([
            'section_no' => 'A',
            'name' => 'Pekerjaan struktur',
        ]);

        foreach ([
            ['A.1', 'Galian tanah biasa', 1000, 'm3', 200_000],
            ['A.2', 'Beton K-350 struktur', 500, 'm3', 1_600_000],
        ] as [$code, $description, $qty, $unit, $price]) {
            $this->boqItems[$code] = BoqItem::query()->create([
                'boq_id' => $this->boq->id,
                'section_id' => $section->id,
                'wbs_code' => $code,
                'description' => $description,
                'qty' => $qty,
                'unit' => $unit,
                'unit_price' => $price,
                'amount' => $qty * $price,
            ]);
        }
    }

    /** A zone under the project, for BAPP and per-zone measurement lines. */
    protected function makeZone(string $code, string $name): Location
    {
        return Location::query()->create([
            'project_id' => $this->project->id,
            'kind' => 'zone',
            'code' => $code,
            'name' => $name,
            'sort_order' => 1,
        ]);
    }

    /**
     * A change order in the given status, plus the per-item volume it carries.
     * The value is incidental here — the ceiling reads the VOLUME row.
     */
    protected function makeVariation(
        string $wbsCode,
        float $qtyChange,
        DocumentStatus $status = DocumentStatus::Approved,
    ): ContractVariation {
        $order = ContractChangeOrder::query()->create([
            'contract_id' => $this->contract->id,
            'change_date' => '2026-06-01',
            'title' => "Tambah volume {$wbsCode}",
            'reason' => 'kondisi_lapangan',
            'change_type' => 'tambah_kurang',
            'value_change' => $qtyChange * (float) $this->boqItems[$wbsCode]->unit_price,
            'ppn_change' => 0,
            'status' => $status,
        ]);

        return ContractVariation::query()->create([
            'contract_id' => $this->contract->id,
            'change_order_id' => $order->id,
            'boq_item_id' => $this->boqItems[$wbsCode]->id,
            'qty_change' => $qtyChange,
            'unit' => $this->boqItems[$wbsCode]->unit,
        ]);
    }

    /** @return array<string, mixed> one measurement line payload */
    protected function line(string $wbsCode, float $qtyThis, ?int $locationId = null): array
    {
        return [
            'boq_item_id' => $this->boqItems[$wbsCode]->id,
            'location_id' => $locationId,
            'qty_this' => $qtyThis,
        ];
    }
}
