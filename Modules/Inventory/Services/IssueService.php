<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Issue;
use Modules\Projects\Models\WbsTask;

class IssueService
{
    public function create(array $data, bool $confirmedWithoutIpp = false): Issue
    {
        $this->applyIppRules($data, null, $confirmedWithoutIpp);

        return DB::transaction(function () use ($data): Issue {
            $items = Arr::pull($data, 'items', []);

            $issue = new Issue(Arr::except($data, ['code', 'status']));
            $issue->status = StockDocumentStatus::Draft;
            $issue->save(); // HasDocumentNumber fills the ISS code

            $this->syncItems($issue, $items);

            return $issue->load('items.item', 'warehouse', 'ipp');
        });
    }

    public function update(Issue $issue, array $data, bool $confirmedWithoutIpp = false): Issue
    {
        $this->assertEditable($issue);
        $this->applyIppRules($data, $issue, $confirmedWithoutIpp);

        return DB::transaction(function () use ($issue, $data): Issue {
            $items = Arr::pull($data, 'items');

            $issue->fill(Arr::except($data, ['code', 'status']));
            $issue->save();

            if (is_array($items)) {
                $this->syncItems($issue, $items); // lines are replaced wholesale
            }

            return $issue->load('items.item', 'warehouse', 'ipp');
        });
    }

    /**
     * P1-ENG: the bon meets the Ijin Pelaksanaan Pekerjaan. Two rules, and one
     * deliberate warning-not-block.
     *
     * INHERITANCE. A bon that points at an IPP inherits the permit's
     * wbs_task_id as its header default — the permit named the work package
     * once, when the PM authorised the work, so the storeman is not asked to
     * re-type an attribution the variance report must then trust. The value
     * was held to the picker's own standard at the IPP end
     * (IppService::assertWbsTaskIsWorkPackage: leaf, same project, carries a
     * BOQ item), so inheriting it here is not a validation bypass. The header
     * stays only a DEFAULT: a line naming its own task still wins
     * (syncItems), and a header the user typed HIMSELF that contradicts the
     * permit is refused rather than silently out-voted — either the bon is
     * for different work (then drop the IPP) or it is not (then drop the
     * task).
     *
     * ONLY AN APPROVED PERMIT COUNTS. "IPP aktif" = status approved: a draft
     * or submitted IPP authorises nothing yet, a rejected one never will, so
     * neither can anchor a bon nor demand a confirmation.
     *
     * THE CONFIRMATION (kriteria dari spec: "peringatan konfirmasi, bukan
     * blokir" — the PriceDeviationService pattern). A bon WITHOUT an IPP on a
     * project that HAS active IPPs is 422 on ipp_id until the payload carries
     * confirm_without_ipp, and the message names every active permit so what
     * gets confirmed is a fact, not an empty sentence. Material outside any
     * permit is real — site consumables, cleanup — and must stay possible.
     * The gate runs when the bon is CREATED, and again only when an update
     * touches ipp_id (detaching a permit re-asks; re-editing the purpose does
     * not nag).
     *
     * Guarded by Schema::hasTable so Inventory still works on an installation
     * without Engineering — that module owns the table, not us (the same
     * stance PriceDeviationService takes on est_boq_items).
     */
    private function applyIppRules(array &$data, ?Issue $existing, bool $confirmedWithoutIpp): void
    {
        if (! Schema::hasTable('eng_work_permits_ipp')) {
            return;
        }

        $projectId = array_key_exists('project_id', $data)
            ? (int) ($data['project_id'] ?? 0)
            : (int) ($existing?->project_id ?? 0);

        $ippId = array_key_exists('ipp_id', $data)
            ? (int) ($data['ipp_id'] ?? 0)
            : (int) ($existing?->ipp_id ?? 0);

        if ($ippId <= 0) {
            $touchesIpp = $existing === null || array_key_exists('ipp_id', $data);

            if ($projectId > 0 && $touchesIpp && ! $confirmedWithoutIpp) {
                $active = WorkPermitIpp::query()
                    ->where('project_id', $projectId)
                    ->where('status', DocumentStatus::Approved->value)
                    ->orderBy('code')
                    ->pluck('code');

                if ($active->isNotEmpty()) {
                    throw ValidationException::withMessages(['ipp_id' => sprintf(
                        'Proyek ini memiliki IPP aktif: %s. Pilih IPP yang mendasari pengeluaran ini '
                        .'agar bon mewarisi paket pekerjaannya, atau ajukan ulang dengan konfirmasi '
                        .'bila bon ini memang di luar cakupan IPP.',
                        $active->implode(', '),
                    )]);
                }
            }

            return;
        }

        $ipp = WorkPermitIpp::query()->find($ippId);

        if ($ipp === null) {
            throw ValidationException::withMessages(['ipp_id' => 'IPP yang dipilih tidak ditemukan.']);
        }

        if ((int) $ipp->project_id !== $projectId) {
            throw ValidationException::withMessages(['ipp_id' => sprintf(
                'IPP %s milik proyek lain dan tidak dapat menjadi dasar bon proyek ini.',
                $ipp->code,
            )]);
        }

        if ($ipp->status !== DocumentStatus::Approved) {
            throw ValidationException::withMessages(['ipp_id' => sprintf(
                'IPP %s masih berstatus %s; hanya IPP yang disetujui yang dapat menjadi dasar pengeluaran material.',
                $ipp->code,
                $ipp->status->label(),
            )]);
        }

        if ($ipp->wbs_task_id === null) {
            return; // a permit without a work package: nothing to inherit
        }

        $headerTaskId = array_key_exists('wbs_task_id', $data)
            ? (int) ($data['wbs_task_id'] ?? 0)
            : (int) ($existing?->wbs_task_id ?? 0);

        if ($headerTaskId <= 0) {
            $data['wbs_task_id'] = (int) $ipp->wbs_task_id; // the inheritance
        } elseif ($headerTaskId !== (int) $ipp->wbs_task_id) {
            throw ValidationException::withMessages(['wbs_task_id' => sprintf(
                'Bon menunjuk %s yang paket pekerjaannya WBS %s, tetapi tugas WBS bon diisi %s. '
                .'Kosongkan tugas WBS agar diwarisi dari IPP, atau lepaskan IPP-nya bila bon ini untuk pekerjaan lain.',
                $ipp->code,
                WbsTask::query()->find($ipp->wbs_task_id)?->wbs_code ?? $ipp->wbs_task_id,
                WbsTask::query()->find($headerTaskId)?->wbs_code ?? $headerTaskId,
            )]);
        }
    }

    public function delete(Issue $issue): void
    {
        $this->assertEditable($issue);

        DB::transaction(function () use ($issue): void {
            $issue->items()->delete();
            $issue->delete();
        });
    }

    /**
     * Replace the lines of a draft issue.
     *
     * ATTRIBUTION RULE, which the material variance report is built on: the
     * LINE names the work package, the header is only its default. One bon can
     * serve two packages — ISS/2026/VII/0001 issues 150 zak semen for the
     * pasangan bata analysis (WBS C.1) and 80 btg besi beton for the pembesian
     * analysis (WBS B.3) — so a header-only attribution would have to be wrong
     * about one of the two lines, and a posted issue can no longer be split.
     * Copying the header down keeps the ordinary single-package bon at one
     * field for the storeman.
     *
     * An update that changes only the header therefore leaves existing lines
     * alone: lines are written only when the payload sends them.
     */
    private function syncItems(Issue $issue, array $items): void
    {
        $issue->items()->delete();

        foreach ($items as $item) {
            $issue->items()->create([
                'item_id' => $item['item_id'],
                'wbs_task_id' => $item['wbs_task_id'] ?? $issue->wbs_task_id,
                'qty' => round((float) ($item['qty'] ?? 0), 3),
                'unit_cost' => 0, // valued at warehouse avg cost when posted
                'amount' => 0,
            ]);
        }
    }

    private function assertEditable(Issue $issue): void
    {
        if (! $issue->status->isEditable()) {
            throw new LogicException("Issue {$issue->code} is {$issue->status->value} and can no longer be modified.");
        }
    }
}
