<?php

namespace Modules\Engineering\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Engineering\Enums\SubmittalDecision;
use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Models\MaterialSubmittal;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Projects\Models\WbsTask;

/**
 * P1-ENG: the IPP — and THE GATE, kriteria #2 of the master prompt: an Ijin
 * Pelaksanaan Pekerjaan cannot be submitted while any drawing line rides a
 * submittal the MK has not stamped approved/approved-as-noted, or any
 * material line a submittal not stamped approved. The 422 names the blocking
 * document numbers (the PriceDeviationService house pattern of refusals that
 * quote their numbers), because the engineer's next act is to chase exactly
 * those sheets — but unlike a price deviation there is NO confirm flag here:
 * work on an unapproved drawing is what the form exists to prevent.
 *
 * THE GATE IS ASYMMETRIC, verbatim from the spec ("bukan approved/
 * approved_as_noted" for drawing lines, "belum approved" for material lines):
 * approved-as-noted opens the gate for a DRAWING — the stamp's own text is
 * "proceed incorporating the notes", instructions to the builder — but NOT
 * for a MATERIAL, whose notes ("ganti merk", "lengkapi sertifikat uji")
 * change what may arrive on site, so until a clean approval exists the
 * material named by the line is not yet the material that may be installed.
 */
class IppService
{
    public function create(array $data, User $by): WorkPermitIpp
    {
        $this->assertReferencedSubmittalsBelongTo((int) $data['project_id'], $data);
        $this->assertWbsTaskIsWorkPackage((int) $data['project_id'], $data);

        return DB::transaction(function () use ($data): WorkPermitIpp {
            /** @var WorkPermitIpp $ipp */
            $ipp = WorkPermitIpp::query()->create(
                Arr::except($data, ['code', 'status', 'materials', 'equipment', 'drawings', 'material_approvals'])
                // Status written explicitly rather than left to the column
                // default — the WorkPermitService lesson: a DB default is not
                // hydrated on the freshly created model, and the resource
                // would answer `status: null` for a permit that IS a draft.
                + ['status' => DocumentStatus::Draft],
            );

            $this->writeLines($ipp, $data);

            return $ipp;
        });
    }

    public function update(WorkPermitIpp $ipp, array $data): WorkPermitIpp
    {
        $ipp->assertRevisiBerlaku('diubah');

        if (! $ipp->status->isEditable()) {
            throw ValidationException::withMessages(['status' => sprintf(
                'IPP %s berstatus %s dan tidak dapat diubah lagi.',
                $ipp->code,
                $ipp->status->label(),
            )]);
        }

        $this->assertReferencedSubmittalsBelongTo((int) $ipp->project_id, $data);
        $this->assertWbsTaskIsWorkPackage((int) $ipp->project_id, $data);

        return DB::transaction(function () use ($ipp, $data): WorkPermitIpp {
            $ipp->fill(Arr::except($data, [
                'code', 'status', 'project_id', 'materials', 'equipment', 'drawings', 'material_approvals',
            ]))->save();

            // Wholesale replacement, only for the line sets the payload names.
            $sets = [
                'materials' => 'materials',
                'equipment' => 'equipment',
                'drawings' => 'drawings',
                'material_approvals' => 'materialApprovals',
            ];

            foreach ($sets as $key => $relation) {
                if (array_key_exists($key, $data)) {
                    $ipp->{$relation}()->delete();
                }
            }

            $this->writeLines($ipp, $data);

            return $ipp;
        });
    }

    /**
     * THE GATE, then the trait. Refusals quote every blocking number at once —
     * a gate that reveals one blocker per attempt teaches people to stop
     * reading it.
     */
    public function submit(WorkPermitIpp $ipp, User $by): WorkPermitIpp
    {
        $ipp->assertRevisiBerlaku('diajukan');

        $blockers = [];

        foreach ($ipp->drawings()->with(['drawingSubmittal.drawing', 'drawingSubmittal.supersededBy'])->get() as $line) {
            $submittal = $line->drawingSubmittal;

            if ($submittal->isSuperseded()) {
                $blockers[] = sprintf(
                    'gambar %s (%s %s) telah digantikan revisi %s — rujuk revisi terbarunya',
                    $submittal->code,
                    $submittal->drawing?->number,
                    $submittal->revision,
                    $submittal->supersededBy?->code ?? '-',
                );

                continue;
            }

            if ($submittal->decision === null) {
                $blockers[] = sprintf(
                    'gambar %s (%s %s) masih menunggu keputusan %s',
                    $submittal->code,
                    $submittal->drawing?->number,
                    $submittal->revision,
                    $submittal->reviewer_party?->label(),
                );
            } elseif (! $submittal->decision->permitsWork()) {
                $blockers[] = sprintf(
                    'gambar %s (%s %s) berkeputusan %s',
                    $submittal->code,
                    $submittal->drawing?->number,
                    $submittal->revision,
                    $submittal->decision->label(),
                );
            }
        }

        foreach ($ipp->materialApprovals()->with('materialSubmittal')->get() as $line) {
            $submittal = $line->materialSubmittal;

            if ($submittal->decision === null) {
                $blockers[] = sprintf(
                    'material %s (%s) masih menunggu keputusan %s',
                    $submittal->code,
                    $submittal->material_name,
                    $submittal->reviewer_party?->label(),
                );
            } elseif ($submittal->decision !== SubmittalDecision::Approved) {
                // Asymmetric on purpose (see the class comment): a material
                // line demands a clean approval, so approved-as-noted — which
                // opens the gate for a drawing — still blocks here, and the
                // message says why instead of leaving the engineer to wonder
                // what is wrong with a stamp that says "disetujui".
                $blockers[] = sprintf(
                    'material %s (%s) berkeputusan %s%s',
                    $submittal->code,
                    $submittal->material_name,
                    $submittal->decision->label(),
                    $submittal->decision === SubmittalDecision::ApprovedAsNoted
                        ? ' — baris material menuntut keputusan Disetujui penuh; bereskan catatannya dan ajukan ulang submittal-nya'
                        : '',
                );
            }
        }

        if ($blockers !== []) {
            throw ValidationException::withMessages(['status' => sprintf(
                'IPP %s tidak dapat diajukan: %s. Selesaikan persetujuan MK-nya dahulu.',
                $ipp->code,
                implode('; ', $blockers),
            )]);
        }

        return $ipp->submit($by);
    }

    /**
     * P8 — revisi generik (D9), pola DrawingSubmittalService: baris BARU
     * bernomor IPP baru, revision + 1, siklus draft dari awal, dan seluruh
     * baris material/alat/gambar/persetujuan-material TERSALIN — revisi
     * berangkat dari isi yang direvisinya, bukan dari nol. Pendahulu dikunci
     * lalu distempel; nomor, status, dan riwayat persetujuannya tak disentuh.
     */
    public function revise(WorkPermitIpp $ipp): WorkPermitIpp
    {
        return DB::transaction(function () use ($ipp): WorkPermitIpp {
            /** @var WorkPermitIpp $locked */
            $locked = WorkPermitIpp::query()->whereKey($ipp->getKey())->lockForUpdate()->firstOrFail();

            $locked->assertRevisiBerlaku('direvisi');

            $successor = $locked->replicate(['code', 'status', 'revision', 'superseded_at', 'superseded_by_id', 'deleted_at']);
            $successor->forceFill([
                'status' => DocumentStatus::Draft,
                'revision' => (int) $locked->revision + 1,
            ])->save();

            $sets = [
                'materials' => ['item_id', 'description', 'qty', 'unit'],
                'equipment' => ['description', 'qty', 'notes'],
                'drawings' => ['drawing_submittal_id'],
                'materialApprovals' => ['material_submittal_id'],
            ];

            foreach ($sets as $relation => $columns) {
                foreach ($locked->{$relation}()->get() as $line) {
                    $successor->{$relation}()->create(Arr::only($line->getAttributes(), $columns));
                }
            }

            $locked->forceFill([
                'superseded_at' => now(),
                'superseded_by_id' => $successor->id,
            ])->save();

            return $successor;
        });
    }

    // ------------------------------------------------------------------ lines

    private function writeLines(WorkPermitIpp $ipp, array $data): void
    {
        foreach ($data['materials'] ?? [] as $line) {
            $ipp->materials()->create(Arr::only($line, ['item_id', 'description', 'qty', 'unit']));
        }

        foreach ($data['equipment'] ?? [] as $line) {
            $ipp->equipment()->create(Arr::only($line, ['description', 'qty', 'notes']));
        }

        foreach ($data['drawings'] ?? [] as $line) {
            $ipp->drawings()->create(Arr::only($line, ['drawing_submittal_id']));
        }

        foreach ($data['material_approvals'] ?? [] as $line) {
            $ipp->materialApprovals()->create(Arr::only($line, ['material_submittal_id']));
        }
    }

    /**
     * The IPP's wbs_task_id exists for one consumer — the bon that points at
     * this IPP inherits it (IssueService), and from there it reaches the
     * material variance report — so the value is held to exactly the standard
     * the bon's own picker enforces: a LEAF of THIS project CARRYING a BOQ
     * item. Anything looser and inheritance launders an attribution
     * IssueStoreRequest would have refused if typed by hand. The three
     * refusal sentences are IssueStoreRequest's, verbatim, so the drafter and
     * the storeman read the same rule in the same words.
     *
     * Service-side, not request-side: the project to check against comes from
     * the ROUTE MODEL on update (project_id is not writable there), and
     * Engineering → Projects is a declared dependency, so WbsTask is queried
     * directly with no schema guard.
     */
    private function assertWbsTaskIsWorkPackage(int $projectId, array $data): void
    {
        $taskId = (int) ($data['wbs_task_id'] ?? 0);

        if ($taskId <= 0) {
            return; // nullable: an IPP may precede the WBS
        }

        $task = WbsTask::query()->find($taskId);

        if ($task === null || (int) $task->project_id !== $projectId) {
            throw ValidationException::withMessages([
                'wbs_task_id' => 'Tugas WBS yang dipilih bukan bagian dari WBS proyek ini.',
            ]);
        }

        if (WbsTask::query()->where('parent_id', $task->id)->exists()) {
            throw ValidationException::withMessages([
                'wbs_task_id' => 'Tugas WBS yang dipilih masih punya sub-tugas; pilih paket pekerjaan paling bawah.',
            ]);
        }

        if ($task->boq_item_id === null) {
            throw ValidationException::withMessages([
                'wbs_task_id' => 'Tugas WBS yang dipilih tidak terhubung ke item BOQ, sehingga pemakaian material tidak dapat dibandingkan dengan analisa harga satuan.',
            ]);
        }
    }

    /**
     * Referential integrity across the module's own lines is the FK's job; the
     * PROJECT match is this service's (ARCHITECTURE.md: integrity across
     * boundaries is enforced in services). An IPP quoting another project's
     * approved drawing would pass the gate on a stamp that approves different
     * work.
     */
    private function assertReferencedSubmittalsBelongTo(int $projectId, array $data): void
    {
        foreach ($data['drawings'] ?? [] as $index => $line) {
            $submittal = DrawingSubmittal::query()
                ->with('drawing')
                ->find((int) ($line['drawing_submittal_id'] ?? 0));

            if ($submittal === null) {
                throw ValidationException::withMessages(["drawings.{$index}.drawing_submittal_id" => 'Submittal gambar tidak ditemukan.']);
            }

            if ((int) $submittal->drawing?->project_id !== $projectId) {
                throw ValidationException::withMessages(["drawings.{$index}.drawing_submittal_id" => sprintf(
                    'Submittal %s berada pada proyek lain dan tidak dapat dirujuk IPP proyek ini.',
                    $submittal->code,
                )]);
            }
        }

        foreach ($data['material_approvals'] ?? [] as $index => $line) {
            $submittal = MaterialSubmittal::query()->find((int) ($line['material_submittal_id'] ?? 0));

            if ($submittal === null) {
                throw ValidationException::withMessages(["material_approvals.{$index}.material_submittal_id" => 'Submittal material tidak ditemukan.']);
            }

            if ((int) $submittal->project_id !== $projectId) {
                throw ValidationException::withMessages(["material_approvals.{$index}.material_submittal_id" => sprintf(
                    'Submittal %s berada pada proyek lain dan tidak dapat dirujuk IPP proyek ini.',
                    $submittal->code,
                )]);
            }
        }
    }
}
