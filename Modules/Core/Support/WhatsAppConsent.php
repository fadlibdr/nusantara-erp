<?php

namespace Modules\Core\Support;

use App\Models\User;

/**
 * Persetujuan WhatsApp melekat pada NOMOR dan BERTANGGAL (P-3a, T3a.3).
 *
 * Satu fungsi untuk kedua pintu tulis — PUT iam/me/phone (orangnya sendiri,
 * via 'profil') dan PUT iam/users/{id} (administrator mencatat persetujuan
 * yang diberikan di luar aplikasi, via 'admin') — supaya keduanya tidak bisa
 * berselisih tentang kapan sebuah stempel dipasang atau dihapus:
 *
 *   nomor berganti           → stempel lama HAPUS (persetujuan untuk nomor
 *                              lama bukan persetujuan untuk nomor baru),
 *                              lalu aturan opt-in di bawah berlaku
 *   opt_in = true            → stempel SEKARANG + via, HANYA bila belum ada
 *                              stempel untuk nomor ini (stempel yang ada
 *                              tidak ditulis ulang: tanggalnya adalah fakta)
 *   opt_in = false           → stempel HAPUS (pencabutan)
 *   opt_in = null (tak ada)  → stempel tidak disentuh
 *   nomor kosong             → stempel HAPUS, apa pun opt_in
 *
 * Tidak ada boolean opt-in di basis data; "sudah opt-in" = whatsapp_opt_in_at
 * tidak null. UserResource memulangkan `whatsapp_opt_in` sebagai turunan
 * supaya formulir generik bisa membulatkannya kembali.
 */
final class WhatsAppConsent
{
    public const VIA_PROFILE = 'profil';

    public const VIA_ADMIN = 'admin';

    public const VIAS = [self::VIA_PROFILE, self::VIA_ADMIN];

    /**
     * @param  string|null  $phone  sudah dinormalkan (PhoneNumber::normalize) atau null
     */
    public static function apply(User $user, ?string $phone, ?bool $optIn, string $via): User
    {
        if (! in_array($via, self::VIAS, true)) {
            throw new \InvalidArgumentException("Jalur opt-in \"{$via}\" tidak dikenal.");
        }

        $previous = $user->phone_e164;
        $changes = ['phone_e164' => $phone];

        $numberChanged = $previous !== $phone;

        if ($phone === null || $numberChanged || $optIn === false) {
            $changes['whatsapp_opt_in_at'] = null;
            $changes['whatsapp_opt_in_via'] = null;
        }

        // Stempel yang masih berlaku: yang ada pada penggunanya, KECUALI baris
        // di atas baru saja mencabutnya untuk nomor ini. Bukan `??`: nilai
        // null yang baru diset adalah keputusan, dan `null ?? stempel-lama`
        // membacanya sebagai "stempel sudah ada" — ganti nomor sambil
        // menyatakan opt-in lagi lalu berakhir TANPA stempel di kedua pintu
        // (verifikasi P-3a, 12 Sep 2026).
        $hasStamp = ! array_key_exists('whatsapp_opt_in_at', $changes) && $user->whatsapp_opt_in_at !== null;

        if ($phone !== null && $optIn === true && ! $hasStamp) {
            $changes['whatsapp_opt_in_at'] = now();
            $changes['whatsapp_opt_in_via'] = $via;
        }

        $user->forceFill($changes)->save();

        return $user;
    }
}
