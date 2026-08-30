<?php

namespace Modules\Quality\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Location;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Quality\Enums\ItemResult;
use Modules\Quality\Enums\NcrStatus;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Quality\Models\Ncr;

/**
 * P1-QC: the inspection lifecycle, and THE BLOCK.
 *
 * THE BLOCK (spec kriteria): an OPEN NCR at a location refuses the submit of a
 * LATER-stage inspection at that SAME location. The comparison is the hold-point
 * ordering before < during < after (InspectionStage::isLaterThan) — a same-stage
 * re-inspection passes, a different location passes, only advancing to a later
 * hold point over an unresolved nonconformance is refused. The 422 names every
 * blocking NCR at once (the IppService house pattern), because the QC engineer's
 * next act is to chase exactly those reports; there is NO confirm flag — running
 * the next inspection over an open nonconformance is what the block exists to
 * prevent.
 *
 * `passed` is DERIVED here from the result rows (any `nok` fails the sheet),
 * never accepted from the request — a sheet must not be able to claim a pass its
 * own lines contradict.
 */
class InspectionService
{
    public function create(array $data, User $by): Inspection
    {
        $this->assertCoherent($data);
        $this->assertResultsBelongToTemplate((int) $data['template_id'], $data['results'] ?? []);

        return DB::transaction(function () use ($data): Inspection {
            /** @var Inspection $inspection */
            $inspection = Inspection::query()->create(
                Arr::except($data, ['code', 'status', 'passed', 'results'])
                + ['status' => DocumentStatus::Draft],
            );

            $this->writeResults($inspection, $data['results'] ?? []);
            $this->deriveOverall($inspection);

            return $inspection;
        });
    }

    public function update(Inspection $inspection, array $data): Inspection
    {
        $inspection->assertRevisiBerlaku('diubah');

        if (! $inspection->status->isEditable()) {
            throw ValidationException::withMessages(['status' => sprintf(
                'Inspeksi %s berstatus %s dan tidak dapat diubah lagi.',
                $inspection->code,
                $inspection->status->label(),
            )]);
        }

        $data['project_id'] = $inspection->project_id;
        $data['template_id'] ??= $inspection->template_id;
        $this->assertCoherent($data);
        $this->assertResultsBelongToTemplate((int) $inspection->template_id, $data['results'] ?? []);

        return DB::transaction(function () use ($inspection, $data): Inspection {
            $inspection->fill(Arr::except($data, ['code', 'status', 'passed', 'project_id', 'template_id', 'results']))->save();

            if (array_key_exists('results', $data)) {
                $inspection->results()->delete();
                $this->writeResults($inspection, $data['results'] ?? []);
            }

            $this->deriveOverall($inspection);

            return $inspection;
        });
    }

    /**
     * THE BLOCK, then the trait. Refusals quote every blocking NCR number at
     * once — a gate that reveals one blocker per attempt teaches people to stop
     * reading it.
     */
    public function submit(Inspection $inspection, User $by): Inspection
    {
        $inspection->assertRevisiBerlaku('diajukan');

        $stage = $inspection->template?->stage
            ?? InspectionTemplate::query()->whereKey($inspection->template_id)->value('stage');

        $blockers = Ncr::query()
            ->where('location_id', $inspection->location_id)
            ->whereIn('status', NcrStatus::openValues())
            ->orderBy('code')
            ->get()
            ->filter(fn (Ncr $ncr): bool => $ncr->stage !== null && $stage !== null && $stage->isLaterThan($ncr->stage));

        if ($blockers->isNotEmpty()) {
            $names = $blockers
                ->map(fn (Ncr $ncr): string => sprintf('%s (%s, %s)', $ncr->code, $ncr->stage->label(), $ncr->status->label()))
                ->implode('; ');

            throw ValidationException::withMessages(['status' => sprintf(
                'Inspeksi %s tahap %s tidak dapat diajukan: masih ada NCR terbuka di lokasi ini dari tahap sebelumnya — %s. '
                    .'Selesaikan (verifikasi) NCR-nya dahulu sebelum melanjutkan ke tahap berikutnya.',
                $inspection->code,
                $stage?->label(),
                $names,
            )]);
        }

        return $inspection->submit($by);
    }

    /**
     * P8 — revisi generik (D9), pola DrawingSubmittalService: baris BARU
     * bernomor QCI baru, revision + 1, siklus draft dari awal, dan butir hasil
     * TERSALIN sebagai titik berangkat — merevisi lembar berarti membetulkan
     * isinya, bukan mengetik ulang dari nol (nilai salinan diedit sebelum
     * diajukan, seperti draf mana pun). Pendahulu dikunci lalu distempel;
     * nomor, status, verdict, dan riwayat persetujuannya tak disentuh.
     */
    public function revise(Inspection $inspection): Inspection
    {
        return DB::transaction(function () use ($inspection): Inspection {
            /** @var Inspection $locked */
            $locked = Inspection::query()->whereKey($inspection->getKey())->lockForUpdate()->firstOrFail();

            $locked->assertRevisiBerlaku('direvisi');

            $successor = $locked->replicate(['code', 'status', 'revision', 'superseded_at', 'superseded_by_id', 'deleted_at']);
            $successor->forceFill([
                'status' => DocumentStatus::Draft,
                'revision' => (int) $locked->revision + 1,
            ])->save();

            foreach ($locked->results()->get() as $line) {
                $successor->results()->create(Arr::only($line->getAttributes(), ['template_item_id', 'result', 'remark']));
            }

            // `passed` ikut tersalin oleh replicate dan tetap konsisten dengan
            // butir salinannya; update() berikutnya menurunkannya ulang.

            $locked->forceFill([
                'superseded_at' => now(),
                'superseded_by_id' => $successor->id,
            ])->save();

            return $successor;
        });
    }

    // ------------------------------------------------------------------ helpers

    private function writeResults(Inspection $inspection, array $results): void
    {
        foreach ($results as $line) {
            $inspection->results()->create(Arr::only($line, ['template_item_id', 'result', 'remark']));
        }
    }

    /**
     * The overall verdict IS the lines: any `nok` fails the sheet, `na` never
     * does. Written here, never from the request.
     */
    private function deriveOverall(Inspection $inspection): void
    {
        $anyNok = $inspection->results()
            ->where('result', ItemResult::Nok->value)
            ->exists();

        $inspection->forceFill(['passed' => ! $anyNok])->save();
    }

    /**
     * Referential integrity across module boundaries is this service's job
     * (ARCHITECTURE.md), the way IppService checks its references: the location
     * (and the IPP, when named) must belong to the same project as the
     * inspection, or the sheet would attribute work to the wrong job.
     */
    private function assertCoherent(array $data): void
    {
        $projectId = (int) $data['project_id'];

        $location = Location::query()->find((int) ($data['location_id'] ?? 0));

        if ($location === null || (int) $location->project_id !== $projectId) {
            throw ValidationException::withMessages([
                'location_id' => 'Lokasi yang dipilih bukan bagian dari proyek inspeksi ini.',
            ]);
        }

        if (! empty($data['ipp_id'])) {
            $ipp = WorkPermitIpp::query()->find((int) $data['ipp_id']);

            if ($ipp === null || (int) $ipp->project_id !== $projectId) {
                throw ValidationException::withMessages([
                    'ipp_id' => 'IPP yang dipilih berada pada proyek lain dan tidak dapat mendasari inspeksi ini.',
                ]);
            }
        }
    }

    private function assertResultsBelongToTemplate(int $templateId, array $results): void
    {
        foreach ($results as $index => $line) {
            $itemId = (int) ($line['template_item_id'] ?? 0);

            $belongs = DB::table('qc_inspection_template_items')
                ->where('id', $itemId)
                ->where('template_id', $templateId)
                ->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    "results.{$index}.template_item_id" => 'Butir hasil tidak termasuk dalam template inspeksi ini.',
                ]);
            }
        }
    }
}
