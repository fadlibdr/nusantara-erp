<?php

namespace Modules\Iam\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Core\Http\ApiController;
use Modules\Core\Support\WhatsAppConsent;
use Modules\Iam\Http\Requests\UpdatePhoneRequest;
use Modules\Iam\Http\Resources\UserResource;

/**
 * PUT iam/me/phone — nomor WhatsApp dan opt-in milik PEMANGGIL SENDIRI
 * (P-3a, T3a.3). Tanpa izin tambahan, pola me/password dan me/onboarding:
 * tidak ada parameter yang menyebut orang lain.
 *
 * Stempel opt-in dipasang WhatsAppConsent dengan via 'profil' — jejak bahwa
 * orangnya sendiri yang menyetujui, di layar Profil › Notifikasi, pada saat
 * itu. Jawabannya berbentuk auth/me supaya SPA mengganti salinan sesinya.
 */
class PhoneController extends ApiController
{
    public function update(UpdatePhoneRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $phone = $request->normalizedPhone();
        $optIn = $request->boolean('whatsapp_opt_in');

        WhatsAppConsent::apply($user, $phone, $optIn, WhatsAppConsent::VIA_PROFILE);

        $message = match (true) {
            $phone === null => 'Nomor WhatsApp dihapus; persetujuan WhatsApp ikut dicabut.',
            $user->whatsapp_opt_in_at !== null => "Nomor {$phone} disimpan dengan opt-in tercatat "
                .$user->whatsapp_opt_in_at->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB.',
            default => "Nomor {$phone} disimpan tanpa opt-in — WhatsApp tidak akan dikirim sampai Anda menyetujuinya.",
        };

        return $this->ok(new UserResource($user->load('roles')), $message);
    }
}
