<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Crm\Support\ActivityDocuments;

/**
 * Satu aktivitas sebagaimana dibaca kartu di layar prospek/penawaran/pelanggan.
 *
 * Dua field turunan dikirim server, bukan dihitung klien: `is_overdue` dan
 * `owner_user_name`. Yang pertama karena "lewat tanggal" dibandingkan dengan
 * jam SERVER — jam peramban yang meleset dua hari akan mewarnai baris yang
 * salah; yang kedua karena "Belum ditugaskan" harus berbunyi sama di daftar, di
 * kartu, di papan dan di CSV, dan satu-satunya cara menjaminnya adalah satu
 * kalimat di satu tempat.
 */
class ActivityResource extends JsonResource
{
    /** Kalimat yang dipakai di seluruh aplikasi untuk pemilik yang kosong. */
    public const BELUM_DITUGASKAN = 'Belum ditugaskan';

    /**
     * Satu kalimat pemilik, dipakai kartu aktivitas DAN LeadResource — supaya
     * "Belum ditugaskan" tidak pernah ditulis dua kali dengan dua ejaan.
     */
    public static function ownerName(?int $ownerId, ?string $name): string
    {
        if ($ownerId === null) {
            return self::BELUM_DITUGASKAN;
        }

        return $name ?? "Pengguna #{$ownerId} (tidak ditemukan)";
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_type_label' => ActivityDocuments::label((string) $this->document_type),
            'document_id' => $this->document_id,
            // Ditempelkan ActivityController (satu query per jenis). Absen —
            // bukan null — bila pemanggilnya tidak memintanya, supaya "kosong"
            // tidak pernah terbaca sebagai "dokumennya tanpa nama".
            'document_label' => $this->whenNotNull($this->document_label),
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'subject' => $this->subject,
            'notes' => $this->notes,
            'due_at' => $this->due_at?->toDateString(),
            'done_at' => $this->done_at?->toIso8601String(),
            'done_by_id' => $this->done_by_id,
            'done_by_name' => $this->whenLoaded('doneBy', fn () => $this->doneBy?->name),
            'owner_user_id' => $this->owner_user_id,
            // Tidak pernah null: sebuah sel kosong terbaca "belum dimuat",
            // sedangkan yang benar adalah "belum ada yang bertanggung jawab".
            // Pemilik yang barisnya sudah tidak ada TIDAK ikut berbunyi "Belum
            // ditugaskan" — itu dua keadaan yang berbeda, dan yang kedua adalah
            // data rusak yang harus terlihat.
            'owner_user_name' => self::ownerName($this->owner_user_id, $this->owner?->name),
            'is_open' => $this->done_at === null,
            'is_overdue' => $this->isOverdue(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
