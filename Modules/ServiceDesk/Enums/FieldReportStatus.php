<?php

namespace Modules\ServiceDesk\Enums;

enum FieldReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Submitted => 'Diajukan',
            self::Acknowledged => 'Disahkan Pelanggan',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the report may still be pulled back to Draft.
     *
     * From Submitted, always: nothing has been posted for it yet, and being
     * able to retreat is the only thing that keeps a submitted report from
     * becoming a permanent period-close wedge — Submitted + parts is a
     * DanglingDocuments source at BLOCK severity, and a report whose issue
     * preconditions went unsatisfiable after submission (another movement
     * landed on that warehouse/item after the visit day) has no other move
     * left. See FieldReportService::returnToDraft().
     *
     * From Acknowledged, never. The signature posted a real bon; inv_issues
     * .field_report_id is UNIQUE per report and StockService::cancelIssue
     * refuses a field-report-raised bon outright. A way back would leave the
     * bon in the ledger pointing at a report that claims the parts never
     * left, with 1-1400 short of the shelf by the visit's value — for the live
     * PM/2026/VI/0001 that is Rp 1.850.000, its 1 x ITM-0004. Correcting a
     * wrong sign-off is an opname.
     */
    public function canReturnToDraft(): bool
    {
        return $this === self::Submitted;
    }
}
