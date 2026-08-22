<?php

namespace Modules\Inventory\Enums;

/**
 * Two-step lifecycle for stock documents that hit the ledger the moment they
 * are posted (GRN, material issue). Posted documents are immutable.
 *
 * Cancelled is a THIRD state, not a way back into the first two: a posted
 * document stays posted in the ledger for ever and a cancellation is a new
 * mirror movement plus a reversing journal, exactly as ApBillService::cancel()
 * works on the AP side. It is reachable today for material issues
 * (StockService::cancelIssue — the movement that lands on PROJECT COST, so the
 * one a wrong project makes permanently wrong) and for goods receipts
 * (StockService::cancelReceipt — where T37's consequences concentrated: the
 * receipt's value sat in 1-1400 with a clearing credit a vendor bill could
 * still settle, and PoService::registerReceipt() only ever ADDED to
 * qty_received, so a bogus receipt left the order reading delivered for ever;
 * the cancellation walks the stock back out, reverses the journals, clears the
 * recorded clearing and hands the quantities back through
 * PoService::unregisterReceipt(), reopening an auto-closed order).
 *
 * Transfers and opnames still have no way back, and deliberately so — their
 * remedies already exist as SECOND DOCUMENTS, and neither leaves a stranded
 * liability or a corrupted three-way match behind. A transfer is undone by
 * receiving it and sending a second transfer the other way (no journal is
 * posted on either leg, so there is nothing to reverse). An opname is undone
 * by a second opname, with the condition worth knowing: the two 6-4400
 * Selisih Persediaan variances only cancel if nothing priced differently
 * moved in between, and they land in different months, so the first month's
 * profit and loss stays wrong — a reporting cost, not a hole a document can
 * still spend. Weighed up under T37 in docs/ASSESSMENT-LANJUTAN.md.
 */
enum StockDocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Posted => 'Diposting',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
