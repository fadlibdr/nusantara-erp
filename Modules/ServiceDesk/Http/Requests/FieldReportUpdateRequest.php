<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FieldReportUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'ticket_id' => ['sometimes', 'required', 'integer', Rule::exists('svc_tickets', 'id')],
            'report_date' => ['sometimes', 'required', 'date'],
            'technician_employee_id' => ['sometimes', 'required', 'integer', Rule::exists('hr_employees', 'id')],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('inv_warehouses', 'id')->whereNull('deleted_at')],
            'findings' => ['sometimes', 'required', 'string', 'max:10000'],
            'actions_taken' => ['sometimes', 'required', 'string', 'max:10000'],
            'recommendations' => ['nullable', 'string', 'max:10000'],
            'customer_sign_name' => ['nullable', 'string', 'max:100'],
            'parts' => ['sometimes', 'array'],
            'parts.*.item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            'parts.*.qty' => ['required', 'numeric', 'min:0.001'],
            'parts.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
