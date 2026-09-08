<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\ActivityType;
use Modules\Crm\Support\ActivityDocuments;

class ActivityStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(ActivityDocuments::types())],
            'document_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::enum(ActivityType::class)],
            'subject' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            // Dicap server saat tombol "Selesai" ditekan (ActivityService).
            // Ditolak, bukan diabaikan: sebuah field yang hilang diam-diam
            // adalah cara sebuah laporan mengira ia mencatat sesuatu.
            'done_at' => ['prohibited'],
            'done_by_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'done_at.prohibited' => 'Tanggal selesai dicatat server saat aktivitas ditandai selesai, bukan diketik.',
            'done_by_id.prohibited' => 'Penyelesai dicatat server dari pengguna yang menekan "Selesai", bukan diketik.',
        ];
    }
}
