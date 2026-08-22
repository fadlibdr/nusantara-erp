<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceDesk\Models\FieldReport;

class FieldReportAcknowledgeRequest extends FormRequest
{
    /**
     * The route's permission:svc.update covers the signature. It does NOT cover
     * what this endpoint does when the report lists spare parts.
     *
     * Acknowledging a parts-bearing report runs the full inventory posting
     * inside the same transaction (FieldReportService::issueParts →
     * IssueService::create → StockService::postIssue): stock leaves a warehouse
     * at moving average and a journal Dr 6-4100 / Cr 1-1400 hits the general
     * ledger. Gated on svc.update alone, the `teknisi` role as first shipped —
     * svc.view/create/update plus inv.VIEW and nothing else — could have written
     * off Rp 55.500.000 of ITM-0004 from WH-PUSAT on a report it wrote itself,
     * signed with a customer name it typed itself, while the SAME login was
     * refused POST inventory/issues/{id}/post with a 403.
     *
     * So the endpoint asks for the right it actually exercises. Scoped to
     * reports that carry parts on purpose: a signature-only sign-off moves no
     * stock and stays a technician's job, which is what it is.
     *
     * THE ROLE MATRIX RECORDED ITS DECISION on 22 Aug 2026: `teknisi` now holds
     * inv.post (RoleSeeder on fresh installs, Iam migration 000242 on live
     * tenants), so a technician completes the parts visit they made and this
     * gate holds that login to the same right every other stock posting
     * demands — the privilege stays VISIBLE instead of smuggled in through
     * svc.update. The `warehouse` role still holds neither svc.update nor
     * inv.post and cannot complete a parts visit; that strain is recorded in
     * the role matrix, not decided here.
     */
    public function authorize(): bool
    {
        $report = $this->route('fieldReport');

        if (! $report instanceof FieldReport || $report->parts()->doesntExist()) {
            return true; // permission middleware (svc.update) guards the signature
        }

        return (bool) $this->user()?->can('inv.post');
    }

    public function rules(): array
    {
        return [
            'customer_sign_name' => ['required', 'string', 'max:100'],
        ];
    }
}
