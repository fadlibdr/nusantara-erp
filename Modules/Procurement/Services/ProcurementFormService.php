<?php

namespace Modules\Procurement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Terbilang;
use Modules\Procurement\Models\AwardDecision;
use Modules\Procurement\Models\NegotiationMinute;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\RfqItem;
use Modules\Procurement\Models\RfqQuote;
use Modules\Procurement\Models\VendorEvaluation;
use Modules\Procurement\Models\WorkOrder;
use Modules\Procurement\Support\BidWeights;

/**
 * The body of the four Pengadaan house forms, in the taste of
 * Modules\Projects\Services\LaporanFormService.
 *
 * It exists so that Modules\Core\Support\PrintableDocuments stays a
 * DECLARATION. Everything below is either a read off the record it was given
 * or arithmetic over the record's own rows; nothing here writes, and nothing
 * here invents. The registry could hold most of it as closures, but three of
 * these answers need a decision spelled out in prose next to them, and a
 * decision that needs prose does not belong in an array literal:
 *
 *   AN UNPRICED REQUISITION LINE. prc_purchase_requisition_items
 *   .estimated_price is NOT NULL DEFAULT 0 and PurchaseRequisitionStoreRequest
 *   makes it optional, so a stored 0 on this table means "the requester did
 *   not estimate" far more often than it means "free of charge". Printed as
 *   0,00 on the sheet an approver signs, it becomes a price somebody stated.
 *   So it comes back null and the sheet rules it — and the estimate TOTAL
 *   comes back null too whenever any line is unpriced, because a total that
 *   silently treats unknowns as zeros understates the commitment being
 *   approved, which is the one direction that matters.
 *
 *   AN INVITED VENDOR WHO NEVER BID still gets a row on the tabulation. The
 *   record of a banding is who was ASKED, not who answered; a sheet listing
 *   only the vendors who quoted claims a comparison narrower than it was, and
 *   printing Rp 0,00 against a silent vendor claims an offer they never made.
 *   Their offer total is null (ruled) and their coverage says "0 dari n baris
 *   ditawar" beside it.
 *
 *   A PARTIAL BIDDER'S TOTAL IS NOT COMPARABLE with a complete one, so the
 *   coverage string travels next to every total rather than only next to the
 *   incomplete ones. A reader comparing two figures has to be told the second
 *   one prices half the scope, in the same glance.
 */
class ProcurementFormService
{
    /**
     * The four criteria a vendor evaluation scores, in the order the sheet
     * asks them, keyed by the column each score lives in.
     *
     * The weight is DERIVED from this list rather than typed as "25 %", so the
     * four weights this sheet prints move together with the list: four criteria
     * make 100 / 4 = 25,00 each, and 25,00 × 4 is exactly 100.
     *
     * IT IS NOT A GUARANTEE THAT THEY SUM TO 100, and the earlier version of
     * this comment said it was. 100 divided by a count that does not go into it
     * evenly leaves a remainder the rounding drops — three criteria print 33,33
     * three times and foot to 99,99 — so the arithmetic holds for THIS list and
     * for any list whose size divides 100, not for the derivation.
     *
     * That is all it buys, and an earlier version claimed more still: it said
     * deriving the weight stops a fifth criterion from putting a mis-weighted
     * sheet in circulation. It does not. VendorEvaluationService
     * ::totalScore hardcodes round($sum / 4, 2) over four named columns and
     * this list is a separate const — add a fifth criterion there and this
     * sheet prints four rows at 25 % beside a NILAI AKHIR divided by five,
     * with nothing failing. THE TWO LISTS ARE HAND-KEPT IN STEP; changing one
     * means changing the other. A comment that claims a safety it does not
     * provide is worse than no comment, because it stops the next reader
     * checking.
     */
    private const EVALUATION_CRITERIA = [
        'quality_score' => 'Mutu barang / hasil pekerjaan',
        'delivery_score' => 'Ketepatan waktu pengiriman',
        'price_score' => 'Kewajaran harga',
        'service_score' => 'Layanan, komunikasi dan penanganan keluhan',
    ];

    /**
     * The five weighted-tabulation aspects on paper (sistem nilai DAN 4.8, P2),
     * keyed by BidWeights::ASPECTS so the printed labels move with the config.
     *
     * harga says where its score comes from because it is the ONE aspect the
     * evaluator does not type — the tabulation must not let a reader mistake a
     * computed ratio score for a panel judgement.
     */
    private const ASPECT_LABELS = [
        'harga' => 'Harga (rasio thd RAB)',
        'mutu' => 'Mutu / teknis',
        'waktu' => 'Ketepatan waktu',
        'keuangan' => 'Kemampuan keuangan',
        'k3' => 'K3 / HSE',
    ];

    // ------------------------------------------------- permintaan pembelian

    /**
     * One printed line per requisition item.
     *
     * The description of a stock line lives in inv_items, not on the
     * requisition row (PurchaseRequisitionStoreRequest allows either), and a
     * ruled blank where the ERP does know the name would be an omission rather
     * than an honest gap. Resolved in ONE query for the whole table — a body
     * of 200 rows that reached across per row is 200 queries inside one print
     * — and through a plain query rather than an Inventory model, the same way
     * PoService::itemName does it: the Procurement module must keep working on
     * an install where Inventory is absent.
     *
     * @return list<array<string, mixed>>
     */
    public function requisitionLines(PurchaseRequisition $requisition): array
    {
        $names = $this->itemNames(
            $requisition->items->pluck('item_id')->filter()->unique()->all()
        );

        return $requisition->items->map(function ($line) use ($names): array {
            $price = $this->statedPrice($line->estimated_price);
            $qty = (float) $line->qty;

            return [
                'line_no' => (int) $line->line_no,
                'description' => trim((string) ($line->description ?? '')) ?: ($names[(int) $line->item_id] ?? null),
                'qty' => $qty,
                'unit' => $line->unit,
                'estimated_price' => $price,
                // No price, no extension. Multiplying an unknown by a volume
                // gives a confident zero, which is the failure this whole file
                // exists to refuse.
                'estimated_amount' => $price === null ? null : round($qty * $price, 2),
            ];
        })->values()->all();
    }

    /**
     * The requisition's estimated value, or null when it cannot be stated.
     *
     * Null the moment ONE line is unpriced. The alternative — sum what is
     * priced and print it — is a number that looks like the total and is
     * always too small, on the document an approver reads to decide whether
     * this purchase needs a director.
     */
    public function estimatedTotal(PurchaseRequisition $requisition): ?float
    {
        $total = 0.0;

        foreach ($requisition->items as $line) {
            $price = $this->statedPrice($line->estimated_price);

            if ($price === null) {
                return null;
            }

            $total += (float) $line->qty * $price;
        }

        return $requisition->items->isEmpty() ? null : round($total, 2);
    }

    // ------------------------------------------------------ order pembelian

    /**
     * The PO's notes block: what was written on the order, the reason the
     * prakualifikasi gate was overridden when it was (temuan #35), and the
     * reason the order was placed without a PR when it was (T3.8).
     *
     * In the NOTES rather than in an identity line, and that is the point: an
     * identity line prints its label whether or not it has a value, so a clean
     * PO would carry "OVERRIDE PRAKUALIFIKASI : ......" and invite somebody to
     * write one in. The notes block simply rules itself when there is nothing
     * to say.
     */
    public function orderNotes(PurchaseOrder $order): ?string
    {
        return $this->paragraphs([
            $order->notes,
            $this->labelled('Override prakualifikasi vendor', $order->qualification_override_reason),
            $this->labelled('Alasan tanpa PR', $order->pr_bypass_reason),
        ]);
    }

    /**
     * The single-row terbilang box under the PO totals.
     *
     * Spelled by Core's own Terbilang, which is what the dompdf purchase order
     * next door uses — the same order printed on either path must not word its
     * own total two different ways.
     *
     * @return list<array<string, mixed>>
     */
    public function orderTerbilangRow(PurchaseOrder $order): array
    {
        return [[
            'amount' => (float) $order->total,
            'terbilang' => Terbilang::rupiah((float) $order->total),
        ]];
    }

    // ------------------------------------------------------- ppk alat & jasa

    /**
     * The PPK's notes block: what was written on the order, and the reason
     * the prakualifikasi gate was overridden when it was — the exact shape of
     * orderNotes above, for the exact reason: an identity line would print
     * "OVERRIDE PRAKUALIFIKASI : ......" on every clean PPK and invite one.
     */
    public function workOrderNotes(WorkOrder $workOrder): ?string
    {
        return $this->paragraphs([
            $workOrder->notes,
            $this->labelled('Override prakualifikasi vendor', $workOrder->qualification_override_reason),
        ]);
    }

    /**
     * "PPN 11%", or a label that does not claim a rate when none applies —
     * SubcontractFormService::ppnLabel's wording, because a PPK is the same
     * kind of paper as an SPK: a work commitment whose vendor may be non-PKP,
     * and "PPN 0% : Rp 0,00" reads as a rate somebody agreed rather than a
     * tax that is simply not levied.
     */
    public function workOrderPpnLabel(WorkOrder $workOrder): string
    {
        $rate = (float) $workOrder->ppn_rate;

        return $rate > 0
            ? 'PPN '.rtrim(rtrim(number_format($rate, 2, ',', '.'), '0'), ',').'%'
            : 'PPN (tidak dikenakan)';
    }

    /**
     * Arithmetic over the PPK's own two stored columns (value = Σ amount
     * baris via WorkOrderService::recalcValue, ppn_rate = snapshot saat
     * dibuat) — never today's config. The AP bills that realise this plafon
     * compute their PPN per billing off the same snapshot
     * (ApBillService::createFromWorkOrderBilling), so sheet and ledger agree.
     */
    public function workOrderPpnAmount(WorkOrder $workOrder): float
    {
        return round((float) $workOrder->value * (float) $workOrder->ppn_rate / 100, 2);
    }

    public function workOrderTotal(WorkOrder $workOrder): float
    {
        return round((float) $workOrder->value + $this->workOrderPpnAmount($workOrder), 2);
    }

    // ----------------------------------------------------- banding penawaran

    /**
     * One row per INVITED vendor — including the ones who never answered.
     *
     * @return list<array<string, mixed>>
     */
    public function vendorRecap(Rfq $rfq): array
    {
        $lineCount = $rfq->items->count();
        $rows = [];
        $no = 0;

        foreach ($rfq->vendors as $invitation) {
            $quotes = $this->quotesOf($rfq, (int) $invitation->vendor_id);
            $wins = $quotes->filter(fn (RfqQuote $quote): bool => (bool) $quote->is_winner);

            $rows[] = [
                'no' => ++$no,
                'vendor' => $this->vendorName($invitation->vendor?->name, (int) $invitation->vendor_id),
                'code' => $invitation->vendor?->code,
                'coverage' => $quotes->count().' dari '.$lineCount.' baris ditawar',
                // A vendor who priced nothing offered nothing. Not Rp 0,00 —
                // that is an offer to do the work for free.
                'offer' => $quotes->isEmpty() ? null : $this->sumQuotes($rfq, $quotes),
                'won_lines' => $wins->count(),
                // Same argument one column over: 0 lines won is a fact and
                // prints as 0; Rp 0,00 awarded is not a figure, it is a blank.
                'won_value' => $wins->isEmpty() ? null : $this->sumQuotes($rfq, $wins),
            ];
        }

        return $rows;
    }

    /**
     * The tabulation itself: one row per recorded quote, cheapest first within
     * each line, so the comparison reads down the page.
     *
     * A line NOBODY quoted still gets a row — with its vendor, price and
     * amount ruled — because a scope item that received no offers is the most
     * important thing on a banding sheet and dropping it hides it.
     *
     * @return list<array<string, mixed>>
     */
    public function quoteRows(Rfq $rfq): array
    {
        $rows = [];

        foreach ($rfq->items as $line) {
            /** @var Collection<int, RfqQuote> $quotes */
            $quotes = $line->quotes->sortBy(fn (RfqQuote $quote): float => (float) $quote->unit_price)->values();

            if ($quotes->isEmpty()) {
                $rows[] = $this->quoteRow($line) + [
                    'vendor' => null, 'unit_price' => null, 'amount' => null, 'is_winner' => null,
                ];

                continue;
            }

            foreach ($quotes as $quote) {
                $rows[] = $this->quoteRow($line) + [
                    'vendor' => $this->vendorName($quote->vendor?->name, (int) $quote->vendor_id),
                    'unit_price' => (float) $quote->unit_price,
                    'amount' => round((float) $line->qty * (float) $quote->unit_price, 2),
                    'is_winner' => (bool) $quote->is_winner,
                ];
            }
        }

        return $rows;
    }

    /**
     * "PT Semen Andalan (2 baris)", or null while nobody has decided.
     *
     * Null rather than "the cheapest column" on purpose: choosing the winner
     * is the decision the signature block below it records, and a sheet that
     * pre-filled it would be making that decision on the way to the printer.
     * RfqService::chooseWinner is deliberately not lowest-price either — the
     * cheapest vendor who cannot deliver is not the winner.
     */
    public function recommendation(Rfq $rfq): ?string
    {
        $byVendor = [];

        foreach ($this->winningQuotes($rfq) as $quote) {
            $vendorId = (int) $quote->vendor_id;
            // Grouped by ID rather than by name, and named through the id when
            // the vendor row has gone: "(2 baris)" with no subject in front of
            // it would read as a recommendation nobody made, and the id at
            // least says which supplier to go and look up.
            $byVendor[$vendorId]['name'] ??= $this->vendorName($quote->vendor?->name, $vendorId);
            $byVendor[$vendorId]['lines'] = ($byVendor[$vendorId]['lines'] ?? 0) + 1;
        }

        if ($byVendor === []) {
            return null;
        }

        return implode('; ', array_map(
            static fn (array $group): string => $group['name'].' ('.$group['lines'].' baris)',
            $byVendor,
        ));
    }

    /** The value of the winning cells, or null while there are none. */
    public function recommendedValue(Rfq $rfq): ?float
    {
        $winners = $this->winningQuotes($rfq);

        return $winners->isEmpty() ? null : $this->sumQuotes($rfq, $winners);
    }

    // ---------------------------------------------- penilaian berbobot (P2)

    /**
     * True once at least one vendor on this RFQ has been scored.
     *
     * The weighted tabulation is PRINTED only from here on. An RFQ nobody has
     * scored yet has no ranking to show, and printing an empty weighted grid
     * with the header weights would assert a scoring exercise that has not
     * happened — the banding sheet stays the price tabulation until it does.
     */
    public function hasWeightedTabulation(Rfq $rfq): bool
    {
        return $rfq->bidEvaluations->isNotEmpty();
    }

    /**
     * The weight split printed above the weighted tabulation, one row per
     * aspect, footing to 100.
     *
     * Read from the validated config (BidWeights) so the split this sheet
     * declares is the SAME split the tabulation was scored on — a printed 50 %
     * beside a score computed at 40 % would be a document contradicting itself.
     *
     * @return list<array<string, mixed>>
     */
    public function bidWeightRows(Rfq $rfq): array
    {
        $weights = BidWeights::weights();
        $rows = [];
        $no = 0;

        foreach (BidWeights::ASPECTS as $aspect) {
            $rows[] = [
                'no' => ++$no,
                'aspect' => self::ASPECT_LABELS[$aspect] ?? $aspect,
                'weight' => round((float) ($weights[$aspect] ?? 0), 2),
                'source' => $aspect === 'harga'
                    ? 'Dihitung dari rasio penawaran terhadap RAB'
                    : 'Dinilai panitia (0–100)',
            ];
        }

        return $rows;
    }

    /** The declared weights' sum — 100 on a valid config, and shown as the foot. */
    public function bidWeightTotal(): float
    {
        return round(array_sum(BidWeights::weights()), 2);
    }

    /**
     * The weighted tabulation itself: one row per INVITED vendor, the scored
     * ones in rank order and the rest at the foot.
     *
     * A vendor invited but NOT scored is ruled across every score cell — never
     * a zero. prc_bid_evaluations defaults every aspect column to 0, so a
     * missing evaluation and a genuine zero would print identically if this
     * reached for the columns; it reaches for the ROW instead, and a vendor
     * with no row gets ruled blanks and "Belum dinilai". A blank score and a
     * score of nought are different claims on a sheet that ranks suppliers.
     *
     * @return list<array<string, mixed>>
     */
    public function evaluationRows(Rfq $rfq): array
    {
        $rows = [];
        $seen = [];

        foreach ($rfq->bidEvaluations as $evaluation) {
            $vendorId = (int) $evaluation->vendor_id;
            $seen[$vendorId] = true;

            $rows[] = [
                'rank' => $evaluation->rank !== null ? (int) $evaluation->rank : null,
                'vendor' => $this->vendorName($evaluation->vendor?->name, $vendorId),
                'harga' => (float) $evaluation->harga_score,
                'mutu' => (float) $evaluation->mutu_score,
                'waktu' => (float) $evaluation->waktu_score,
                'keuangan' => (float) $evaluation->keuangan_score,
                'k3' => (float) $evaluation->k3_score,
                'weighted' => (float) $evaluation->weighted_score,
                'note' => null,
            ];
        }

        foreach ($rfq->vendors as $invitation) {
            $vendorId = (int) $invitation->vendor_id;

            if (isset($seen[$vendorId])) {
                continue;
            }

            $rows[] = [
                'rank' => null,
                'vendor' => $this->vendorName($invitation->vendor?->name, $vendorId),
                'harga' => null, 'mutu' => null, 'waktu' => null,
                'keuangan' => null, 'k3' => null, 'weighted' => null,
                'note' => 'Belum dinilai',
            ];
        }

        return $rows;
    }

    // ------------------------------------------------ berita acara negosiasi

    /**
     * The negotiated lines: what was quoted, and what it became.
     *
     * A stored 0 on either price column is the table default, not a figure, so
     * it is ruled rather than printed as Rp 0,00 — the same rule the whole file
     * keeps. SELISIH is null unless BOTH prices are stated, because a delta
     * against an unknown is not a delta.
     *
     * @return list<array<string, mixed>>
     */
    public function negotiationItemRows(NegotiationMinute $minute): array
    {
        $rows = [];

        foreach ($minute->items as $item) {
            $awal = $this->statedPrice($item->harga_awal);
            $nego = $this->statedPrice($item->harga_nego);

            $rows[] = [
                'line_no' => (int) $item->line_no,
                'description' => $item->description,
                'qty' => $item->qty !== null ? (float) $item->qty : null,
                'unit' => $item->unit,
                'harga_awal' => $awal,
                'harga_nego' => $nego,
                'selisih' => ($awal !== null && $nego !== null) ? round($nego - $awal, 2) : null,
            ];
        }

        return $rows;
    }

    /** The negotiation's attendees, from the peserta json. */
    public function negotiationParticipantRows(NegotiationMinute $minute): array
    {
        return $this->personRows($minute->peserta);
    }

    // --------------------------------------------------- keputusan pemenang

    /** The award committee, from the committee json. */
    public function awardCommitteeRows(AwardDecision $award): array
    {
        return $this->personRows($award->committee);
    }

    /**
     * The single-row terbilang box under the award value.
     *
     * Spelled by Core's Terbilang, the same speller the PO and every other
     * money document here use — one award worded two ways is a document that
     * argues with itself.
     *
     * @return list<array<string, mixed>>
     */
    public function awardTerbilangRow(AwardDecision $award): array
    {
        return [[
            'amount' => (float) $award->awarded_amount,
            'terbilang' => Terbilang::rupiah((float) $award->awarded_amount),
        ]];
    }

    /**
     * The award's notes block: whatever was written, and the deviation reason
     * WHEN there is a deviation.
     *
     * In the notes rather than an identity line for the same reason
     * orderNotes() is: a clean award would otherwise carry "ALASAN DEVIASI :
     * ......" and invite one to be written in after the signatures. The reason
     * only belongs on the paper when the award actually deviates.
     */
    public function awardNotes(AwardDecision $award): ?string
    {
        return $this->paragraphs([
            $award->notes,
            (float) $award->deviation_amount > 0
                ? $this->labelled('Alasan deviasi terhadap RAB', $award->deviation_reason)
                : null,
        ]);
    }

    // ------------------------------------------------------- evaluasi vendor

    /**
     * The four scored criteria, each with the weight the average implies.
     *
     * @return list<array<string, mixed>>
     */
    public function evaluationCriteria(VendorEvaluation $evaluation): array
    {
        $weight = round(100 / count(self::EVALUATION_CRITERIA), 2);
        $rows = [];
        $no = 0;

        foreach (self::EVALUATION_CRITERIA as $column => $criterion) {
            $rows[] = [
                'no' => ++$no,
                'criterion' => $criterion,
                'weight' => $weight,
                // 1-5, NOT NULL on every one of the four columns, so every
                // score printed here is a score somebody actually gave.
                'score' => (int) $evaluation->{$column},
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------- internals

    /**
     * The supplier's name, or the identifier that at least says which supplier.
     *
     * Every row of this sheet that carries a PRICE has to carry a party, and a
     * winning row most of all: a dotted blank in the vendor column next to
     * 217.500.000,00 and a "Ya" is an invitation to write a name in after the
     * three signatures are on it. The eager loads in PrintableDocuments already
     * use withTrashed, so a soft-deleted supplier still prints its real name
     * and this fallback is reached only when the row is gone from the database
     * altogether — where "Vendor #2" is the honest answer, being what we
     * actually still know.
     *
     * A vendor NOT invited and NOT quoting has no id here at all; those cells
     * stay ruled, as they should.
     */
    private function vendorName(?string $name, int $vendorId): ?string
    {
        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        return $vendorId > 0 ? 'Vendor #'.$vendorId : null;
    }

    /**
     * A json list of people — the BAN's peserta, the award's committee — as
     * printable rows. Entries may be plain strings (a name) or objects carrying
     * nama / jabatan / pihak (or the english keys). A blank name is dropped: an
     * empty row on a signed attendance table is a place to write a name in.
     *
     * @return list<array<string, mixed>>
     */
    private function personRows(mixed $people): array
    {
        $rows = [];
        $no = 0;

        foreach ((array) ($people ?? []) as $person) {
            if (is_array($person)) {
                $name = trim((string) ($person['nama'] ?? $person['name'] ?? ''));
                $role = trim((string) ($person['jabatan'] ?? $person['role'] ?? ''));
                $side = trim((string) ($person['pihak'] ?? $person['side'] ?? ''));
            } else {
                $name = trim((string) $person);
                $role = '';
                $side = '';
            }

            if ($name === '') {
                continue;
            }

            $rows[] = ['no' => ++$no, 'name' => $name, 'role' => $role ?: null, 'side' => $side ?: null];
        }

        return $rows;
    }

    /** @return Collection<int, RfqQuote> */
    private function quotesOf(Rfq $rfq, int $vendorId): Collection
    {
        return $rfq->items
            ->flatMap(fn (RfqItem $line): iterable => $line->quotes)
            ->filter(fn (RfqQuote $quote): bool => (int) $quote->vendor_id === $vendorId)
            ->values();
    }

    /** @return Collection<int, RfqQuote> */
    private function winningQuotes(Rfq $rfq): Collection
    {
        return $rfq->items
            ->flatMap(fn (RfqItem $line): iterable => $line->quotes)
            ->filter(fn (RfqQuote $quote): bool => (bool) $quote->is_winner)
            ->values();
    }

    /**
     * Quoted unit prices extended by the RFQ's own volumes — the only way two
     * vendors' offers are comparable at all, since a quote is a unit price and
     * the volume belongs to the line.
     *
     * @param  Collection<int, RfqQuote>  $quotes
     */
    private function sumQuotes(Rfq $rfq, Collection $quotes): float
    {
        $volumes = $rfq->items->pluck('qty', 'id');

        return round($quotes->sum(
            fn (RfqQuote $quote): float => (float) ($volumes[$quote->rfq_item_id] ?? 0) * (float) $quote->unit_price
        ), 2);
    }

    /** @return array<string, mixed> */
    private function quoteRow(RfqItem $line): array
    {
        return [
            'line_no' => (int) $line->line_no,
            'description' => $line->description,
            'qty' => (float) $line->qty,
            'unit' => $line->unit,
        ];
    }

    /**
     * A price the requester actually stated, or null.
     *
     * See the class docblock: the column's zero is its DEFAULT, not a figure.
     * A line genuinely supplied free of charge loses nothing by being ruled —
     * the site writes "gratis" on it — while the opposite mistake puts an
     * invented price under a signature.
     */
    private function statedPrice(mixed $value): ?float
    {
        $price = (float) $value;

        return $price > 0 ? $price : null;
    }

    /**
     * inv_items names by id, in one query, or an empty map when the Inventory
     * module is not installed. Mirrors PoService::itemName's plain-query,
     * schema-guarded shape.
     *
     * @param  list<int>  $itemIds
     * @return array<int, string>
     */
    private function itemNames(array $itemIds): array
    {
        if ($itemIds === [] || ! Schema::hasTable('inv_items')) {
            return [];
        }

        return DB::table('inv_items')
            ->whereIn('id', $itemIds)
            ->pluck('name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    private function labelled(string $label, mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $label.' : '.$text;
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    private function paragraphs(array $parts): ?string
    {
        $kept = array_values(array_filter(array_map(
            static fn (?string $part): string => trim((string) $part),
            $parts,
        ), static fn (string $part): bool => $part !== ''));

        return $kept === [] ? null : implode("\n\n", $kept);
    }
}
