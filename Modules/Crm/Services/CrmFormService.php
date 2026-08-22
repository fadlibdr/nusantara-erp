<?php

namespace Modules\Crm\Services;

use Illuminate\Support\Collection;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\Guarantee;

/**
 * The read model behind the printed CRM house forms.
 *
 * Modules\Core\Support\PrintableDocuments declares WHAT each sheet prints;
 * anything that needs a decision rather than a column lives here, in the module
 * that owns the data — the same split LaporanFormService already makes for the
 * two Projects forms, and for the same reason: the decision about what may NOT
 * be printed has to be somewhere a test can address it directly, not inside an
 * array literal.
 *
 * Every method returns null (never 0, never a placeholder sentence) for a cell
 * the database cannot answer. The generic sheet renders null as the ruled blank
 * the owner's own paper has always had.
 */
class CrmFormService
{
    /**
     * The four money lines of a berita acara pekerjaan tambah-kurang.
     *
     * THE DECISION THIS METHOD EXISTS FOR — what "nilai sesudah" means.
     *
     * crm_contracts.value moves only when a change order is APPROVED
     * (ContractChangeOrderService::approve, which also backfills original_value
     * with what was signed). So:
     *
     *   approved  — the contract row itself now carries the amended value, and
     *               printing it is quoting the record;
     *   otherwise — the contract is still worth what it was worth, and this
     *               sheet is the PROPOSAL to change it. The sheet says so.
     *
     * What it deliberately never prints is value + value_change on an
     * unapproved order. The arithmetic is trivial and the number is wrong the
     * moment a second change order is also pending: each sheet would project a
     * total that ignores the other, both would look authoritative, and the
     * figure the customer signs would be one nobody's ledger can reproduce.
     *
     * @return list<array{uraian: string, nilai: ?float}>
     */
    public function changeOrderValues(ContractChangeOrder $order): array
    {
        $contract = $order->contract;
        $approved = $order->status === DocumentStatus::Approved;

        return [
            [
                // original_value is null until the FIRST approved amendment, and
                // that null means "never amended" — the current value is then
                // still the signed one. Same expression the approval writes with.
                'uraian' => 'Nilai kontrak sesuai tanda tangan (DPP)',
                'nilai' => $contract === null ? null : (float) ($contract->original_value ?? $contract->value),
            ],
            [
                'uraian' => 'Nilai perubahan pada berita acara ini (tambah / kurang)',
                'nilai' => (float) $order->value_change,
            ],
            [
                'uraian' => 'PPN atas nilai perubahan',
                'nilai' => (float) $order->ppn_change,
            ],
            [
                'uraian' => $approved
                    ? 'Nilai kontrak (DPP) setelah perubahan ini disetujui'
                    : 'Nilai kontrak (DPP) berjalan — perubahan ini belum disetujui',
                'nilai' => $contract === null ? null : (float) $contract->value,
            ],
        ];
    }

    /**
     * ALASAN, in the words the screen uses.
     *
     * crm_contract_change_orders.reason is a plain string validated against
     * four values by the controller — no enum, so no label() to call. The map
     * is here rather than in the registry entry because a value the list does
     * not know still has to print: it is what the operator stored, and blanking
     * it would hide a reason somebody typed rather than admit we cannot spell
     * it nicely.
     */
    public function changeOrderReason(ContractChangeOrder $order): ?string
    {
        $reason = trim((string) ($order->reason ?? ''));

        return match ($reason) {
            '' => null,
            'permintaan_pelanggan' => 'Permintaan pelanggan',
            'kondisi_lapangan' => 'Kondisi lapangan',
            'desain' => 'Perubahan desain',
            'lainnya' => 'Lainnya',
            default => $reason,
        };
    }

    /**
     * The rows of a printed register jaminan.
     *
     * A REGISTER is a list, and the print endpoint hands this service one row's
     * id — so the sheet printed from any bond is the register of the CONTRACT
     * that bond belongs to. That is the sheet somebody actually files: bonds
     * lapse one at a time and what a project manager needs in front of him is
     * every security this job stands on, with its expiry.
     *
     * A bid bond has no contract (it is raised against the tender, which is why
     * crm_guarantees carries two nullable links and not one required one). Then
     * the register is the single row we can prove, and no other bond is dragged
     * onto a sheet it has nothing to do with.
     *
     * @return Collection<int, Guarantee>
     */
    public function guaranteeRegister(Guarantee $guarantee): Collection
    {
        $siblings = $guarantee->contract?->guarantees;

        // values(): the NO. column is a row counter, and a Collection filtered
        // or eager-loaded elsewhere can arrive with the parent's keys — which
        // would print 1, 3, 6 down a register nobody could then reference.
        return ($siblings === null || $siblings->isEmpty())
            ? collect([$guarantee])
            : $siblings->values();
    }

    /** What the register's own nilai column adds up to. */
    public function guaranteeRegisterTotal(Guarantee $guarantee): float
    {
        return round(
            $this->guaranteeRegister($guarantee)->sum(fn (Guarantee $row): float => (float) $row->value),
            2,
        );
    }

    /**
     * SISA HARI as the register prints it.
     *
     * The wording is the house forms' wording, deliberately: FormPrintService's
     * identity block says "0 hari (lewat 12 hari)" for a contract in overrun,
     * and a jaminan register that invented a second phrasing for the same fact
     * would read as a different system. A lapsed bond states its overrun rather
     * than quietly reading "0 hari" — the whole point of the register is that
     * somebody notices.
     *
     * null (a ruled blank) for a bond that is no longer live; see
     * Guarantee::daysRemaining().
     */
    public function guaranteeRemaining(Guarantee $guarantee): ?string
    {
        $days = $guarantee->daysRemaining();

        return match (true) {
            $days === null => null,
            $days < 0 => '0 hari (lewat '.abs($days).' hari)',
            default => $days.' hari',
        };
    }
}
