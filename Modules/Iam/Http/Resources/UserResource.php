<?php

namespace Modules\Iam\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\ApprovalDelegations;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'employee_id' => $this->employee_id,
            'is_active' => (bool) $this->is_active,
            // P-3a (T3a.3): nomor WhatsApp E.164 + opt-in BERSTEMPEL WAKTU.
            // `whatsapp_opt_in` adalah turunan (stempel ada?) supaya formulir
            // generik Sistem › Pengguna membulatkannya kembali; kebenarannya
            // tetap `whatsapp_opt_in_at` + `whatsapp_opt_in_via`.
            'phone_e164' => $this->phone_e164,
            'whatsapp_opt_in' => $this->whatsapp_opt_in_at !== null,
            'whatsapp_opt_in_at' => $this->whatsapp_opt_in_at?->toIso8601String(),
            'whatsapp_opt_in_via' => $this->whatsapp_opt_in_via,
            'roles' => $this->roles->pluck('name')->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values(),
            /*
             * F-1 (verifikasi putaran 2) — HAK YANG DIPINJAM, DI FIELD SENDIRI.
             *
             * `permissions` di atas adalah getAllPermissions() milik Spatie: ia
             * tidak melewati Gate::before, jadi ability yang dipinjamkan sebuah
             * delegasi tidak pernah ada di dalamnya. Layar menggerbangi setiap
             * tombol pada daftar itu, sehingga seorang delegat murni — persis
             * orang yang fitur ini ada untuknya — tidak melihat "Tugas Saya" di
             * bilah samping dan tidak melihat Setujui pada satu dokumen pun.
             *
             * DILEBURKAN KE `permissions` AKAN MENJADI KEBOHONGAN: daftar itu
             * menjawab "apa yang DIPEGANG orang ini", dan penjaga matriks
             * persetujuan bergantung pada jawaban itu (menyunting ambang
             * menuntut approve-director yang dipegang SENDIRI). Jadi yang
             * dipinjam dikirim terpisah, beserta nama pemberinya, supaya layar
             * dapat berkata "a.n. Sari".
             *
             * @return array<string, list<string>>
             */
            'delegated_permissions' => $this->lentAbilities($request),
            // null = has never decided; the SPA opens the onboarding guide at
            // login on exactly that value (5 Sep 2026). Carried on auth/me so
            // the decision follows the person across browsers and devices.
            'onboarding_status' => $this->onboarding_status,
            'onboarding_seen_at' => $this->onboarding_seen_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Hanya untuk baris PEMANGGILNYA SENDIRI.
     *
     * Pertanyaan yang dijawab field ini adalah "apa yang boleh SAYA lakukan",
     * bukan "apa yang boleh orang itu" — dan daftar pengguna memulangkan
     * resource yang sama untuk sampai 200 baris. Menghitungnya per baris berarti
     * satu pemeriksaan delegasi per pengguna pada layar yang tidak memakainya.
     *
     * @return array<string, list<string>>
     */
    private function lentAbilities(Request $request): array
    {
        $viewer = $request->user();

        if ($viewer === null || (int) $viewer->getKey() !== (int) $this->id) {
            return [];
        }

        return ApprovalDelegations::lentAbilitiesFor($this->resource);
    }
}
