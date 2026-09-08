<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\ActivityType;
use Modules\Crm\Support\ActivityDocuments;

class ActivityUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // Memindahkan aktivitas ke dokumen lain boleh — keduanya (asal dan
            // tujuan) dihitung ulang turunannya oleh ActivityService.
            'document_type' => ['sometimes', Rule::in(ActivityDocuments::types())],
            'document_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', Rule::enum(ActivityType::class)],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'done_at' => ['prohibited'],
            'done_by_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'done_at.prohibited' => 'Pakai tombol "Selesai" pada aktivitasnya; tanggal selesai dicap server, bukan diketik.',
            'done_by_id.prohibited' => 'Penyelesai dicatat server dari pengguna yang menekan "Selesai", bukan diketik.',
        ];
    }
}
