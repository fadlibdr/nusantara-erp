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
     * ledger. Gated on svc.update alone, the shipped `teknisi` role — which
     * holds svc.view/create/update plus inv.VIEW and nothing else — could write
     * off Rp 55.500.000 of ITM-0004 from WH-PUSAT on a report it wrote itself,
     * signed with a customer name it typed itself, while the SAME login is
     * refused POST inventory/issues/{id}/post with a 403. The `warehouse` role,
     * which exists to move stock, cannot do it at all.
     *
     * So the endpoint asks for the right it actually exercises. Scoped to
     * reports that carry parts on purpose: a signature-only sign-off moves no
     * stock and stays a technician's job, which is what it is.
     *
     * NOTE FOR THE ROLE MATRIX (Modules/Iam RoleSeeder, not this module): inv.post
     * is seeded to `admin` alone today, so nobody but an admin can complete a
     * parts visit until either `teknisi` or `warehouse` is granted it. That is
     * the privilege being made VISIBLE rather than smuggled in through svc.update
     * — which is the point — but it is a decision the role matrix has to record.
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
