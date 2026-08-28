<?php

namespace Tests\Unit\Subcontract;

use App\Models\User;
use Error;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Subcontract\Enums\PphConstructionScheme;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractItem;
use Modules\Subcontract\Services\ClaimService;
use Modules\Subcontract\Services\RetentionService;
use Modules\Subcontract\Services\SubcontractService;

/**
 * Hand-built SPK / opname fixtures shared by the math suite
 * (tests/Unit/Subcontract) and the HTTP suite (tests/Feature/Subcontract).
 *
 * Deliberately dumb: it only assembles rows. It never derives an expected
 * number — every SPK value, line amount and rate is spelled out by the test
 * that asserts on it, so a failure points at one thing.
 */
trait SubcontractFixtures
{
    private ?Vendor $fixtureVendor = null;

    private ?Project $fixtureProject = null;

    private ?User $fixtureActor = null;

    private ?User $fixtureApprover = null;

    protected function subcontractService(): SubcontractService
    {
        return app(SubcontractService::class);
    }

    protected function claimService(): ClaimService
    {
        return app(ClaimService::class);
    }

    protected function retentionService(): RetentionService
    {
        return app(RetentionService::class);
    }

    /**
     * Vendor SEHAT — dan sejak P0-E, "sehat" untuk subkontraktor MENCAKUP
     * dokumen komitmen K3L dan pakta integritas: gerbang prakualifikasi kini
     * menolak SPK/PO subkon tanpa keduanya, jadi fixture tanpa dokumen bukan
     * lagi vendor sehat melainkan vendor terblokir. Tiga belas uji lama merah
     * karena perubahan makna ini, bukan karena cacat — memperbaikinya DI SINI,
     * sekali, mempertahankan maksud semua pemanggil ("vendor yang lolos").
     * Uji yang MEMANG menginginkan vendor tanpa dokumen mengirim
     * 'k3l_documents' => false.
     */
    protected function makeVendor(array $attributes = []): Vendor
    {
        $withDocuments = (bool) ($attributes['k3l_documents'] ?? true);
        unset($attributes['k3l_documents']);

        $vendor = Vendor::create(array_merge([
            'name' => 'PT Subkon Jaya Konstruksi',
            'classification' => 'sipil',
            'is_pkp' => true,
            'is_subcontractor' => true,
            'status' => 'active',
        ], $attributes));

        if ($withDocuments && $vendor->is_subcontractor) {
            foreach ([
                ['doc_type' => 'k3l_commitment', 'name' => 'Komitmen K3L'],
                ['doc_type' => 'pakta_integritas', 'name' => 'Pakta Integritas'],
            ] as $document) {
                $vendor->documents()->create($document + [
                    'is_mandatory' => true,
                    'valid_until' => null, // tanpa masa berlaku = tidak kedaluwarsa
                ]);
            }
        }

        return $vendor;
    }

    protected function defaultVendor(): Vendor
    {
        return $this->fixtureVendor ??= $this->makeVendor();
    }

    protected function makeProject(array $attributes = []): Project
    {
        return Project::create(array_merge([
            'name' => 'Gedung Kantor Pusat',
            'type' => 'construction',
        ], $attributes));
    }

    protected function defaultProject(): Project
    {
        return $this->fixtureProject ??= $this->makeProject();
    }

    /**
     * A plain user row: Approvable stamps user_id on every approval record.
     */
    protected function actor(): User
    {
        return $this->fixtureActor ??= User::query()->create([
            'name' => 'Manajer Proyek',
            'email' => 'pm@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    /**
     * The second pair of eyes. An opname is the document that turns a
     * subcontractor's claim into money owed, so the PM who accepts the volume
     * on site is not the person who approves paying for it.
     */
    protected function approver(): User
    {
        return $this->fixtureApprover ??= User::query()->create([
            'name' => 'Manajer Konstruksi',
            'email' => 'manajer-konstruksi@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    /**
     * Build an SPK row directly. `value`, `ppn_rate`, `pph_rate` and
     * `retention_pct` are always passed in by the caller so the test — not this
     * helper — owns the numbers under assertion.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function makeSubcontract(array $attributes = [], array $lines = []): Subcontract
    {
        /** @var Subcontract $subcontract */
        $subcontract = Subcontract::create(array_merge([
            'vendor_id' => $this->defaultVendor()->id,
            'project_id' => $this->defaultProject()->id,
            'title' => 'Pekerjaan struktur beton',
            'value' => 0,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_scheme' => PphConstructionScheme::PelaksanaanBersertifikat->value,
            'pph_rate' => 2.65,
            'start_date' => '2026-02-01',
            'end_date' => '2026-08-31',
            'status' => DocumentStatus::Draft,
        ], $attributes));

        foreach ($lines as $line) {
            $this->addLine($subcontract, $line);
        }

        return $subcontract;
    }

    /**
     * An SPK already sitting in `approved` — the only state an opname may be
     * raised against.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function makeApprovedSubcontract(array $attributes = [], array $lines = []): Subcontract
    {
        return $this->makeSubcontract(
            array_merge($attributes, ['status' => DocumentStatus::Approved]),
            $lines,
        );
    }

    protected function addLine(Subcontract $subcontract, array $attributes = []): SubcontractItem
    {
        /** @var SubcontractItem $item */
        $item = $subcontract->items()->create(array_merge([
            'line_no' => $subcontract->items()->count() + 1,
            'description' => 'Pekerjaan',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 0,
            'amount' => 0,
            'progress_pct' => 0,
        ], $attributes));

        return $item;
    }

    /**
     * Draft an opname covering `[subcontract_item_id => current_progress_pct]`.
     *
     * @param  array<int, float|int>  $progress
     */
    protected function draftClaim(Subcontract $subcontract, array $progress, array $attributes = []): ProgressClaim
    {
        $items = [];

        foreach ($progress as $itemId => $current) {
            $items[] = [
                'subcontract_item_id' => $itemId,
                'current_progress_pct' => $current,
            ];
        }

        return $this->claimService()->createClaim(array_merge([
            'subcontract_id' => $subcontract->id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'items' => $items,
        ], $attributes));
    }

    /**
     * Draft + submit + approve in one go, for arranging the "already claimed"
     * background of a later opname.
     *
     * @param  array<int, float|int>  $progress
     */
    protected function approvedClaim(Subcontract $subcontract, array $progress, array $attributes = []): ProgressClaim
    {
        $claim = $this->draftClaim($subcontract, $progress, $attributes);
        $claim->submit($this->actor());

        return $this->claimService()->approve($claim->refresh(), $this->approver());
    }

    protected function progressOf(SubcontractItem $item): float
    {
        return (float) $item->fresh()->progress_pct;
    }

    /**
     * PphConstructionScheme::rate() used to be unreachable: the enum called
     * Erp::float() without importing Modules\Core\Support\Erp, so PHP resolved
     * it to the non-existent Modules\Subcontract\Enums\Erp and threw a fatal
     * Error, taking every rate-snapshotting path with it
     * (SubcontractService::create / update).
     *
     * The import has landed; this asserts it stays, so a regression fails the
     * suite loudly instead of silently skipping the tests built on top of it.
     */
    protected function assertPphSchemeRateIsReachable(): void
    {
        try {
            PphConstructionScheme::PelaksanaanBersertifikat->rate();
        } catch (Error $e) {
            $this->fail(
                'PphConstructionScheme::rate() is unreachable again: '.$e->getMessage()
                .' — check that Modules/Subcontract/Enums/PphConstructionScheme.php still '
                .'imports Modules\Core\Support\Erp.'
            );
        }
    }
}
