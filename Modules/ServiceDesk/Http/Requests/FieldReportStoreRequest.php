<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FieldReportStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'ticket_id' => ['required', 'integer', Rule::exists('svc_tickets', 'id')],
            'report_date' => ['required', 'date'],
            'technician_employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')],
            // Gudang asal suku cadang. Nullable while drafting, but a report
            // that lists parts cannot be ACKNOWLEDGED without it — the sign-off
            // posts the stock issue, and an issue needs a warehouse.
            'warehouse_id' => ['nullable', 'integer', Rule::exists('inv_warehouses', 'id')->whereNull('deleted_at')],
            'findings' => ['required', 'string', 'max:10000'],
            'actions_taken' => ['required', 'string', 'max:10000'],
            'recommendations' => ['nullable', 'string', 'max:10000'],
            'customer_sign_name' => ['nullable', 'string', 'max:100'],
            'parts' => ['nullable', 'array'],
            'parts.*.item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            'parts.*.qty' => ['required', 'numeric', 'min:0.001'],
            'parts.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
