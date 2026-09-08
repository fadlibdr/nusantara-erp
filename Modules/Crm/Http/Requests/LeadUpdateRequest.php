<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $leadId = $this->route('lead')?->id;

        return [
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('crm_leads', 'code')->ignore($leadId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'need_summary' => ['nullable', 'string', 'max:1000'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            /*
             * Tahap TIDAK lagi diubah lewat formulir (F-3 / T3.5): satu PUT
             * dengan {"status":"won"} dulu memenangkan prospek tanpa penawaran,
             * tanpa nilai dan tanpa tanggal keputusan — dan win-rate per sales
             * dihitung dari kolom itu. Pintunya sekarang
             * POST leads/{id}/pipeline (LeadPipelineService), yang memeriksa
             * arah perpindahan, menuntut alasan untuk mundur, dan mencatat
             * riwayatnya. Ditolak, bukan diabaikan: yang diabaikan diam-diam
             * membuat orang mengira tahapnya sudah berpindah.
             */
            'status' => ['prohibited'],
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
            'status.prohibited' => 'Tahap prospek dipindahkan lewat tombol "Ubah Tahap" (atau papan pipeline), '
                .'bukan lewat formulir: perpindahan mundur menuntut alasan dan setiap perpindahan tercatat di riwayat.',
            'next_follow_up_at.prohibited' => 'Tanggal tindak lanjut diturunkan dari aktivitas prospek ini, '
                .'bukan diketik: buat aktivitas berjatuh tempo pada kartu Aktivitas di layar prospek.',
        ];
    }
}
