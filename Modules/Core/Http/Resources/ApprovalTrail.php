<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Support\Collection;
use Modules\Core\Models\Approval;

/**
 * Jejak persetujuan satu dokumen, satu bentuk untuk dua puluh lima resource.
 *
 * Sebelum F-1 penutup yang sama persis disalin dua puluh lima kali — byte per
 * byte identik di QuotationResource, PaymentResource, BoqResource dan dua
 * puluh dua lainnya. Selama tidak ada yang berubah, itu tidak menyakiti siapa
 * pun. Delegasi mengubahnya: sebuah baris persetujuan sekarang bisa berbunyi
 * "Budi a.n. Sari", dan sebuah fakta yang harus muncul di dua puluh lima
 * tempat akan muncul di dua puluh empat.
 *
 * `on_behalf_of` null pada hampir semua baris, dan itu bukan kekosongan yang
 * perlu disembunyikan — ia berarti orang itu menyetujui atas namanya sendiri.
 */
final class ApprovalTrail
{
    /**
     * @param  Collection<int, Approval>  $approvals
     * @return list<array<string, mixed>>
     */
    public static function map(Collection $approvals): array
    {
        return $approvals->map(fn (Approval $approval): array => [
            'id' => $approval->id,
            'action' => $approval->action,
            'note' => $approval->note,
            'created_at' => $approval->created_at?->toIso8601String(),
            'user' => $approval->relationLoaded('user') && $approval->user !== null
                ? ['id' => $approval->user->id, 'name' => $approval->user->name]
                : null,
            /*
             * "a.n." — dibaca dari kolomnya, dan namanya dimuat malas bila
             * relasinya belum ada. Baris ini hampir selalu null, jadi kueri
             * tambahan itu hampir tidak pernah terjadi; memaksa setiap
             * pemanggil menambahkan with('approvals.onBehalfOf') akan berarti
             * dua puluh lima suntingan lagi dan satu yang terlupa.
             */
            'on_behalf_of' => $approval->on_behalf_of_user_id === null
                ? null
                : ['id' => $approval->on_behalf_of_user_id, 'name' => $approval->onBehalfOf?->name],
        ])->values()->all();
    }
}
