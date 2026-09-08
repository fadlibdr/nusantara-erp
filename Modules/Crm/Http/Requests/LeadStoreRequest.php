<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\LeadStatus;

class LeadStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:40', Rule::unique('crm_leads', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'need_summary' => ['nullable', 'string', 'max:1000'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            /*
             * Prospek LAHIR di salah satu tahap terbuka — undangan tender
             * memang lahir langsung "Terkualifikasi". Yang tidak bisa adalah
             * lahir Menang/Kalah: keduanya hasil keputusan penawaran, dan
             * sebuah prospek yang diketik langsung sebagai Menang adalah
             * kemenangan tanpa satu rupiah pun di belakangnya (F-3 / T3.5).
             */
            'status' => ['nullable', Rule::enum(LeadStatus::class)->only(
                array_filter(LeadStatus::cases(), static fn (LeadStatus $status): bool => $status->isOpen()),
            )],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            /*
             * TURUNAN, bukan ketikan (F-3 / T3.3). Tanggal tindak lanjut sebuah
             * prospek adalah due_at terawal di antara aktivitas terbukanya —
             * satu penulis, LeadFollowUpService. Ditolak, BUKAN diabaikan
             * diam-diam: sebuah field yang hilang tanpa suara adalah cara
             * seseorang mengira ia sudah menjadwalkan tindak lanjut.
             */
            'next_follow_up_at' => ['prohibited'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.Illuminate\\Validation\\Rules\\Enum' => 'Prospek tidak bisa dibuat langsung dengan status Menang atau Kalah: '
                .'keduanya lahir dari keputusan penawaran (Tandai Menang / Tandai Kalah).',
            'next_follow_up_at.prohibited' => 'Tanggal tindak lanjut diturunkan dari aktivitas prospek ini, '
                .'bukan diketik: buat aktivitas berjatuh tempo pada kartu Aktivitas di layar prospek.',
        ];
    }
}
