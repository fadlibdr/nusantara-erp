<?php

namespace Modules\Projects\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Models\Location;
use Modules\Projects\Enums\ZoneCertificateStatus;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ZoneCertificate;

/**
 * BAPP per zona — and the gate that keeps "Selesai" meaning something.
 *
 * AN OPEN NCR IN THE ZONE REFUSES `done` — at the zone's own node or anywhere
 * BENEATH it, because core_locations is a hierarchy and a defect in the room is
 * a defect in the zone (Location::subtreeIds). P1-QC already decided what "open"
 * means (NcrStatus::isOpen — open | under_correction) and already uses that one
 * predicate in two places: the later-stage inspection block and the BAST I
 * prerequisite. This is the third reader of the SAME predicate, scoped to the
 * certificate's location SUBTREE instead of the project. Marking a zone
 * finished while a nonconformance raised in it is still being corrected is not
 * a small inaccuracy — the BAPP is what an owner claim bills against (kriteria
 * #6), so the lie would be worth money within the week.
 *
 * qc_ncr is read as a RAW TABLE behind Schema::hasTable, with the two status
 * strings as literals, for the reason BastPrerequisiteService spells out: the
 * dependency arrow is Quality → Projects and never the reverse, so Projects may
 * not import NcrStatus. The Quality suite asserts the two literals still equal
 * NcrStatus::openValues().
 *
 * `check` and `waiting_repair` are NOT gated. An inspector must be able to
 * write down what he found the moment he finds it — including "nunggu
 * perbaikan", which is the honest mark for a zone whose NCR is open. Only the
 * claim of completeness is checked.
 */
class ZoneCertificateService
{
    /** Enough codes to act on, few enough to read in a toast (BastPrerequisiteService). */
    private const MAX_NAMED_NCR = 5;

    public function create(array $data): ZoneCertificate
    {
        return DB::transaction(function () use ($data): ZoneCertificate {
            $project = Project::query()->findOrFail((int) $data['project_id']);
            $project->assertOperational('berita acara pemeriksaan pekerjaan');

            $location = $this->locationOf($project, (int) $data['location_id']);
            $status = $this->statusFrom($data['status'] ?? ZoneCertificateStatus::Check->value);

            $this->assertMayCarry($project, $location, $status);

            $certificate = new ZoneCertificate([
                'project_id' => $project->id,
                'location_id' => $location->id,
                'certified_at' => $data['certified_at'] ?? null,
                // Never defaulted from project master data: roadmap §7 — a
                // signing party is a recorded fact or it is blank.
                'certified_by_party' => $data['certified_by_party'] ?? null,
                'certified_by_name' => $data['certified_by_name'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $certificate->status = $status;
            $certificate->save(); // HasDocumentNumber fills the BAPP code

            return $certificate->load('project', 'location');
        });
    }

    public function update(ZoneCertificate $certificate, array $data): ZoneCertificate
    {
        return DB::transaction(function () use ($certificate, $data): ZoneCertificate {
            /** @var ZoneCertificate $certificate */
            $certificate = ZoneCertificate::query()
                ->whereKey($certificate->id)->lockForUpdate()->firstOrFail();

            $project = $certificate->project()->firstOrFail();
            $project->assertOperational('berita acara pemeriksaan pekerjaan');

            $location = array_key_exists('location_id', $data)
                ? $this->locationOf($project, (int) $data['location_id'])
                : $certificate->location()->firstOrFail();

            $status = array_key_exists('status', $data)
                ? $this->statusFrom($data['status'])
                : $certificate->status;

            $this->assertMayCarry($project, $location, $status);

            $certificate->fill(Arr::only($data, [
                'certified_at', 'certified_by_party', 'certified_by_name', 'notes',
            ]));
            $certificate->location_id = $location->id;
            $certificate->status = $status;
            $certificate->save();

            return $certificate->refresh()->load('project', 'location');
        });
    }

    public function delete(ZoneCertificate $certificate): void
    {
        $certificate->delete();
    }

    /**
     * The zone's CURRENT status: the LATEST certificate, by certified_at then
     * id. Null when the zone has never been inspected — which is not the same
     * as "fine", and every caller has to keep the two apart.
     */
    public function statusFor(int $projectId, int $locationId): ?ZoneCertificateStatus
    {
        $latest = ZoneCertificate::query()
            ->where('project_id', $projectId)
            ->where('location_id', $locationId)
            ->orderByRaw('certified_at IS NULL')
            ->orderByDesc('certified_at')
            ->orderByDesc('id')
            ->first();

        return $latest?->status;
    }

    // ------------------------------------------------------------------ rules

    private function assertMayCarry(Project $project, Location $location, ZoneCertificateStatus $status): void
    {
        if (! $status->isDone()) {
            return;
        }

        $open = $this->openNcrIn($location);

        if ($open === []) {
            return;
        }

        $named = array_slice($open, 0, self::MAX_NAMED_NCR);
        $rest = count($open) - count($named);
        $list = implode(', ', $this->labelled($named, $location)).($rest > 0 ? ", dan {$rest} lainnya" : '');

        throw ValidationException::withMessages(['status' => sprintf(
            'Zona %s (%s) tidak dapat ditandai "%s": %d NCR masih terbuka di zona ini atau di bawahnya (%s). '
            .'Verifikasi atau tutup NCR-nya dahulu, atau tandai zona ini "%s".',
            $location->code,
            $location->path(),
            ZoneCertificateStatus::Done->label(),
            count($open),
            $list,
            ZoneCertificateStatus::WaitingRepair->label(),
        )]);
    }

    /**
     * Open NCR codes IN THIS ZONE — at the node and ANYWHERE BENEATH IT — or an
     * empty list when Quality is not installed. `open` = status in (open,
     * under_correction) — NcrStatus's own definition, by value.
     *
     * THE SUBTREE IS THE POINT. core_locations is a hierarchy (PANDUAN §16) and
     * an NCR is raised at the finest node the inspector can point at: the room,
     * the axis. Comparing location_id to the certificate's single key asked
     * about a node while the sheet claims a place, so a zone whose room held an
     * open nonconformance was freely markable "Selesai" — and F/BAPP then
     * printed "Tidak ada NCR terbuka di zona ini." over the signatures with the
     * defect standing one level down. Location::subtreeIds() is the walk.
     *
     * PUBLIC because F/BAPP prints the same list the gate refuses on. The sheet
     * an inspector carries has to say WHY a zone cannot be marked finished, and
     * a second query written into the print registry would be a second answer
     * to that question — the registry's own rule is that a resolver reads its
     * record or calls the owning module's service, never writes SQL of its own.
     *
     * @return list<string>
     */
    public function openNcrCodes(Location $location): array
    {
        return array_map(
            static fn (array $row): string => $row['code'],
            $this->openNcrIn($location),
        );
    }

    /**
     * The same finding with the NODE IT SITS AT, which the refusal needs and
     * the printed list does not. One query for both readers; openNcrCodes()
     * above is the thin projection of it.
     *
     * @return list<array{code: string, location_id: int}>
     */
    private function openNcrIn(Location $location): array
    {
        if (! Schema::hasTable('qc_ncr')) {
            return [];
        }

        return DB::table('qc_ncr')
            ->whereIn('location_id', $location->subtreeIds())
            ->whereIn('status', ['open', 'under_correction'])
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['code', 'location_id'])
            ->map(static fn (object $row): array => [
                'code' => (string) $row->code,
                'location_id' => (int) $row->location_id,
            ])
            ->all();
    }

    /**
     * "NCR/2026/VIII/0001 di Z-A-01" — the code AND the node it sits at.
     *
     * The second half is not decoration. Now that the gate reads a subtree,
     * "NCR terbuka di zona ini" is a claim about a place with rooms in it, and
     * an inspector handed only a code would have to go looking for the finding
     * he is being refused over. A finding at the zone node itself names the
     * zone, so every entry in the list reads the same way.
     *
     * @param  list<array{code: string, location_id: int}>  $rows
     * @return list<string>
     */
    private function labelled(array $rows, Location $zone): array
    {
        $codes = Location::query()
            ->whereIn('id', array_values(array_unique(array_column($rows, 'location_id'))))
            ->pluck('code', 'id')
            ->all();

        return array_map(
            static fn (array $row): string => sprintf(
                '%s di %s',
                $row['code'],
                $codes[$row['location_id']] ?? $zone->code,
            ),
            $rows,
        );
    }

    private function locationOf(Project $project, int $locationId): Location
    {
        $location = Location::query()->find($locationId);

        if ($location === null || (int) $location->project_id !== (int) $project->id) {
            throw ValidationException::withMessages(['location_id' => sprintf(
                'Lokasi tersebut bukan bagian dari proyek %s.',
                $project->code,
            )]);
        }

        return $location;
    }

    private function statusFrom(mixed $status): ZoneCertificateStatus
    {
        if ($status instanceof ZoneCertificateStatus) {
            return $status;
        }

        $resolved = ZoneCertificateStatus::tryFrom((string) $status);

        if ($resolved === null) {
            throw new LogicException("Status BAPP \"{$status}\" tidak dikenal.");
        }

        return $resolved;
    }
}
