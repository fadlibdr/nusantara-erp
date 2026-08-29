<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Location;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Enums\ZoneCertificateStatus;
use Modules\Projects\Services\ZoneCertificateService;
use Modules\Quality\Models\Ncr;
use Modules\Quality\Services\NcrService;
use Tests\ErpTestCase;

/**
 * P3 — BAPP per zona, and the one thing it is not allowed to say.
 *
 * "Selesai" on a zone whose nonconformance is still open is the kind of paper
 * that costs money twice: once when the owner claim bills the zone (kriteria
 * #6 reads exactly this mark), and again when the defect surfaces at BAST II
 * with the retention already released. P1-QC had already defined "open" and
 * already blocked two things with it; this is the third reader of the same
 * predicate, scoped to the zone.
 *
 * What the gate does NOT do is stop an inspector writing down what he found.
 * "Nunggu perbaikan" on a zone with an open NCR is the honest mark, and it is
 * accepted without argument.
 */
class ZoneCertificateTest extends ErpTestCase
{
    use OpnameFixtures;

    private ZoneCertificateService $service;

    private Location $zoneA;

    private Location $zoneB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOpnameWorld();
        $this->service = app(ZoneCertificateService::class);
        $this->zoneA = $this->makeZone('Z-A', 'Lantai 3 Zona A');
        $this->zoneB = $this->makeZone('Z-B', 'Lantai 3 Zona B');
    }

    private function openNcr(Location $location): Ncr
    {
        return app(NcrService::class)->create([
            'project_id' => $this->project->id,
            'location_id' => $location->id,
            'stage' => 'during',
            'description' => 'Selimut beton kurang dari toleransi pada kolom K3.',
            'responsible_employee_id' => $this->responsibleEmployee()->id,
            'subcontract_id' => null,
        ]);
    }

    /** A node UNDER a zone — core_locations is a tree, not a flat list. */
    private function childOf(Location $parent, string $code, string $name): Location
    {
        return Location::query()->create([
            'project_id' => $this->project->id,
            'parent_id' => $parent->id,
            'kind' => 'room',
            'code' => $code,
            'name' => $name,
            'sort_order' => 1,
        ]);
    }

    private function responsibleEmployee(): Employee
    {
        return Employee::query()->create([
            'code' => 'EMP-'.fake()->unique()->numerify('####'),
            'name' => 'Agus Prasetyo',
            'nik_ktp' => fake()->unique()->numerify('################'),
            'gender' => 'male',
            'birth_date' => '1988-05-01',
            'ptkp_status' => 'K/1',
            'join_date' => '2020-01-01',
            'employment_type' => 'tetap',
            'position' => 'Site Manager',
            'department' => 'proyek',
        ]);
    }

    private function inspector(): User
    {
        return User::query()->create([
            'name' => 'QC', 'email' => 'qc@test.local', 'password' => 'password', 'is_active' => true,
        ]);
    }

    public function test_a_zone_without_findings_can_be_certified_done(): void
    {
        $bapp = $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'done',
            'certified_at' => '2026-06-30',
            'certified_by_party' => 'mk',
        ]);

        $this->assertSame(ZoneCertificateStatus::Done, $bapp->status);
        $this->assertStringStartsWith('BAPP/', $bapp->code);
    }

    public function test_an_open_ncr_at_the_zone_refuses_done_and_the_message_names_it(): void
    {
        $ncr = $this->openNcr($this->zoneA);

        try {
            $this->service->create([
                'project_id' => $this->project->id,
                'location_id' => $this->zoneA->id,
                'status' => 'done',
            ]);
            $this->fail('Zona dengan NCR terbuka seharusnya tidak dapat ditandai Selesai.');
        } catch (ValidationException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));

            $this->assertStringContainsString($ncr->code, $message);
            $this->assertStringContainsString('Z-A', $message);
            $this->assertStringContainsString('Nunggu perbaikan', $message);
        }
    }

    public function test_an_ncr_under_correction_still_blocks_done(): void
    {
        app(NcrService::class)->startCorrection($this->openNcr($this->zoneA));

        $this->expectException(ValidationException::class);

        $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'done',
        ]);
    }

    public function test_the_same_zone_accepts_done_once_the_ncr_is_verified(): void
    {
        $ncr = $this->openNcr($this->zoneA);

        $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'waiting_repair',
            'certified_at' => '2026-06-10',
        ]);

        app(NcrService::class)->verify($ncr, $this->inspector());

        $bapp = $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'done',
            'certified_at' => '2026-06-30',
        ]);

        $this->assertSame(ZoneCertificateStatus::Done, $bapp->status);
        // The zone's CURRENT mark is the later sheet, not the earlier one.
        $this->assertSame(
            ZoneCertificateStatus::Done,
            $this->service->statusFor($this->project->id, $this->zoneA->id),
        );
    }

    /**
     * THE ZONE IS A SUBTREE, NOT A NODE. core_locations is a hierarchy (Tower ›
     * Lantai › Zona › As › Ruang, PANDUAN §16), and an NCR is raised at the
     * finest node the inspector can point at — the room, the axis — not at the
     * zone he happens to be certifying. A gate that compares location_id to one
     * key therefore answers about a POINT while the sheet claims a PLACE, and
     * the zone stays freely markable "Selesai" with its own defect inside it.
     */
    public function test_an_open_ncr_one_level_below_the_zone_refuses_done(): void
    {
        $room = $this->childOf($this->zoneA, 'Z-A-01', 'Ruang panel lantai 3');
        $ncr = $this->openNcr($room);

        try {
            $this->service->create([
                'project_id' => $this->project->id,
                'location_id' => $this->zoneA->id,
                'status' => 'done',
            ]);
            $this->fail('Zona dengan NCR terbuka di sub-lokasinya seharusnya tidak dapat ditandai Selesai.');
        } catch (ValidationException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));

            // The code alone is not enough to act on: "an NCR in this zone" is
            // now a claim about a subtree, so the refusal has to say WHERE.
            $this->assertStringContainsString($ncr->code, $message);
            $this->assertStringContainsString('Z-A-01', $message);
        }
    }

    /** Two levels down is still inside the zone. */
    public function test_an_open_ncr_two_levels_below_the_zone_refuses_done(): void
    {
        $axis = $this->childOf($this->zoneA, 'Z-A-AS-1', 'As 1');
        $ncr = $this->openNcr($this->childOf($axis, 'Z-A-AS-1-R2', 'Ruang 2'));

        try {
            $this->service->create([
                'project_id' => $this->project->id,
                'location_id' => $this->zoneA->id,
                'status' => 'done',
            ]);
            $this->fail('NCR dua tingkat di bawah zona seharusnya tetap memblokir Selesai.');
        } catch (ValidationException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));

            $this->assertStringContainsString($ncr->code, $message);
            $this->assertStringContainsString('Z-A-AS-1-R2', $message);
        }
    }

    /**
     * And the walk goes DOWN only. A defect in the room next door — a child of
     * the OTHER zone — is not this zone's problem, and a gate that blocked on it
     * would push inspectors to stop recording NCR at the level they find them.
     */
    public function test_an_ncr_below_another_zone_does_not_block_this_one(): void
    {
        $this->openNcr($this->childOf($this->zoneB, 'Z-B-01', 'Ruang genset'));

        $bapp = $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'done',
        ]);

        $this->assertSame(ZoneCertificateStatus::Done, $bapp->status);
    }

    /** Nor UP: an NCR at the floor above says nothing about this zone. */
    public function test_an_ncr_at_the_parent_floor_does_not_block_the_zone_below_it(): void
    {
        $floor = Location::query()->create([
            'project_id' => $this->project->id,
            'kind' => 'floor',
            'code' => 'LT-3',
            'name' => 'Lantai 3',
        ]);
        $this->zoneA->parent_id = $floor->id;
        $this->zoneA->save();

        $this->openNcr($floor);

        $bapp = $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'done',
        ]);

        $this->assertSame(ZoneCertificateStatus::Done, $bapp->status);
    }

    public function test_an_ncr_in_another_zone_does_not_block_this_one(): void
    {
        $this->openNcr($this->zoneB);

        $bapp = $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'done',
        ]);

        $this->assertSame(ZoneCertificateStatus::Done, $bapp->status);
    }

    public function test_waiting_repair_is_accepted_while_the_ncr_is_open(): void
    {
        $this->openNcr($this->zoneA);

        $bapp = $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'waiting_repair',
        ]);

        $this->assertSame(ZoneCertificateStatus::WaitingRepair, $bapp->status);
        $this->assertTrue($bapp->blocksBilling());
    }

    public function test_editing_an_existing_bapp_to_done_runs_the_same_gate(): void
    {
        $bapp = $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'check',
        ]);

        $this->openNcr($this->zoneA);

        $this->expectException(ValidationException::class);

        $this->service->update($bapp, ['status' => 'done']);
    }

    public function test_a_location_from_another_project_is_refused(): void
    {
        $alien = Location::query()->create([
            'project_id' => 999_999,
            'kind' => 'zone',
            'code' => 'Z-X',
            'name' => 'Zona proyek lain',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $alien->id,
            'status' => 'check',
        ]);
    }

    /**
     * NULL, not Selesai and not Nunggu perbaikan. "Never inspected" is a third
     * answer, and both readers of this method have to keep it apart from the
     * other two — the owner claim bills such a zone (a BAPP that does not exist
     * says nothing about it) while a screen must not paint it green.
     */
    public function test_a_zone_never_inspected_has_no_status_at_all(): void
    {
        $this->assertNull($this->service->statusFor($this->project->id, $this->zoneA->id));

        $this->service->create([
            'project_id' => $this->project->id,
            'location_id' => $this->zoneA->id,
            'status' => 'check',
        ]);

        $this->assertSame(
            ZoneCertificateStatus::Check,
            $this->service->statusFor($this->project->id, $this->zoneA->id),
        );
    }
}
