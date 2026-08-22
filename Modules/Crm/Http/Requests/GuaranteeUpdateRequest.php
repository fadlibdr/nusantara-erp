<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Crm\Enums\GuaranteeStatus;
use Modules\Crm\Enums\GuaranteeType;
use Modules\Crm\Models\Guarantee;

/**
 * Partial update, so every cross-field rule has to be checked against the ROW
 * AS IT WILL BE, not the payload alone — a payload that only sets
 * quotation_id to null looks harmless until the row it lands on has no
 * contract either.
 */
class GuaranteeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'guarantee_type' => ['sometimes', Rule::enum(GuaranteeType::class)],
            'number' => ['sometimes', 'string', 'max:100'],
            'issuer' => ['sometimes', 'string', 'max:160'],
            // whereNull mirrors the store request: a soft-deleted anchor
            // passes a bare Rule::exists but resolves to null everywhere the
            // SPA looks, so re-anchoring to one would orphan the row.
            'contract_id' => ['sometimes', 'nullable', 'integer', Rule::exists('crm_contracts', 'id')->whereNull('deleted_at')],
            'quotation_id' => ['sometimes', 'nullable', 'integer', Rule::exists('crm_quotations', 'id')->whereNull('deleted_at')],
            'value' => ['sometimes', 'numeric', 'gt:0'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::enum(GuaranteeStatus::class)],
            'document_location' => ['sometimes', 'nullable', 'string', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Guarantee $guarantee */
                $guarantee = $this->route('guarantee');

                $resolve = fn (string $key) => $this->has($key) ? $this->input($key) : $guarantee->{$key};

                // At least one anchor must survive the update.
                if ($resolve('contract_id') === null && $resolve('quotation_id') === null) {
                    $validator->errors()->add('contract_id', 'Jaminan harus terkait dengan kontrak atau penawaran.');
                }

                // Same window rule as on create, with the model filling the
                // side the payload leaves out.
                $start = $this->has('start_date') ? Carbon::parse((string) $this->input('start_date')) : $guarantee->start_date;
                $end = $this->has('end_date') ? Carbon::parse((string) $this->input('end_date')) : $guarantee->end_date;

                if ($start !== null && $end !== null && $end->lt($start)) {
                    $validator->errors()->add('end_date', 'Tanggal berakhir tidak boleh sebelum tanggal mulai.');
                }

                // (issuer, number) stays the identity. Checked here, not with
                // Rule::unique, because changing ONLY the issuer can collide
                // too — and the DB index counts soft-deleted rows, so this
                // check must as well or the 422 becomes a 500.
                if ($this->has('number') || $this->has('issuer')) {
                    $collides = Guarantee::withTrashed()
                        ->where('issuer', $resolve('issuer'))
                        ->where('number', $resolve('number'))
                        ->whereKeyNot($guarantee->getKey())
                        ->exists();

                    if ($collides) {
                        $validator->errors()->add('number', 'Nomor jaminan ini sudah tercatat untuk penerbit yang sama.');
                    }
                }
            },
        ];
    }
}
