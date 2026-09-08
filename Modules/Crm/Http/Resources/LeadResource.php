<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'source' => $this->source,
            'phone' => $this->phone,
            'email' => $this->email,
            'need_summary' => $this->need_summary,
            'estimated_value' => $this->estimated_value,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'owner_user_id' => $this->owner_user_id,
            // Tidak pernah null. Sel kosong terbaca "belum dimuat"; yang benar
            // adalah "belum ada yang bertanggung jawab", dan kalimatnya ditulis
            // di SATU tempat supaya daftar, kartu papan, layar dokumen dan CSV
            // tidak pernah bisa mengejanya berbeda. Pemilik yang baris
            // penggunanya hilang berbunyi lain — itu data rusak, bukan
            // "belum ditugaskan".
            'owner_user_name' => ActivityResource::ownerName($this->owner_user_id, $this->owner?->name),
            'next_follow_up_at' => $this->next_follow_up_at?->toDateString(),
            // Non-null berarti sudah dikonversi — tombol "Jadikan Pelanggan"
            // menyembunyikan diri berdasarkan field ini.
            'customer_id' => $this->customer_id,
            'notes' => $this->notes,
            /*
             * Riwayat tahap, hanya pada layar dokumen (show memuat relasinya).
             * Baris daftar tidak membawanya: satu papan berisi 50 kartu tidak
             * boleh menyeret 50 riwayat, dan yang membacanya memang orang yang
             * sudah membuka prospeknya.
             */
            'status_history' => $this->whenLoaded('statusChanges', fn () => $this->statusChanges
                ->map(fn ($change) => [
                    'id' => $change->id,
                    'from_status' => $change->from_status?->value,
                    'from_label' => $change->from_status?->label(),
                    'to_status' => $change->to_status?->value,
                    'to_label' => $change->to_status?->label(),
                    'direction' => $change->direction,
                    'direction_label' => match ($change->direction) {
                        'maju' => 'Maju',
                        'mundur' => 'Mundur',
                        'penawaran' => 'Keputusan penawaran',
                        default => $change->direction,
                    },
                    'reason' => $change->reason,
                    'document_code' => $change->document_code,
                    'user_name' => $change->user?->name,
                    'created_at' => $change->created_at?->toIso8601String(),
                ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
