<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Support\DeliveryGate;
use Modules\Core\Support\NotificationTemplates;
use Modules\Core\Support\QuietHours;
use Modules\Core\Support\WhatsAppSetup;

/**
 * GET core/me/notification-channels — apa yang akan TERJADI pada
 * pemberitahuan luar milik pemanggil sendiri, kanal per kanal (P-3a, T3a.2).
 *
 * Layar Profil tidak boleh menjanjikan "Anda akan menerima e-mail" pada
 * instalasi yang MAIL_MAILER-nya masih log: sebab yang ditampilkan di sini
 * adalah sebab yang SAMA yang akan ditulis kotak keluar ke kolom "Galat /
 * alasan" (DeliveryGate::reasonToSkip) — satu daftar, dua permukaan.
 *
 * Tanpa gerbang izin, seperti me/preferences: tidak ada parameter yang
 * menyebut orang lain, dan keadaan yang dibaca hanya milik $request->user().
 */
class NotificationChannelController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $now = CarbonImmutable::now();
        $quiet = QuietHours::forUser($user);
        $postpone = DeliveryGate::postponement($user, $now);

        $channels = [];
        foreach (DeliveryGate::USER_CHANNELS as $channel) {
            // Tanpa pemeriksaan template: ringkasan ini tentang ORANGNYA, bukan
            // satu peristiwa; kesiapan template dilaporkan terpisah di bawah.
            $reason = DeliveryGate::reasonToSkip($channel, $user, null, false);

            $channels[] = [
                'channel' => $channel,
                'label' => $channel === NotificationDelivery::CHANNEL_EMAIL ? 'E-mail' : 'WhatsApp',
                'address' => DeliveryGate::address($channel, $user) ?: null,
                'enabled_by_user' => DeliveryGate::userEnabled($channel, $user),
                // null = kanal ini akan MENCOBA mengirim; string = kalimat
                // Dilewati yang akan tercatat. "will_deliver" adalah kata
                // yang jujur: mencoba, bukan pasti sampai.
                'will_deliver' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $this->ok([
            'channels' => $channels,
            'quiet_hours' => $quiet?->toArray(),
            'quiet_now' => $postpone !== null,
            'postponed_until' => $postpone === null ? null : $postpone['until']->toIso8601String(),
            'zone' => QuietHours::ZONE,
            // Kesiapan WhatsApp — angka yang DIUKUR dari .env, bukan klaim:
            // "0 dari 5 template terisi" adalah keadaan repo ini sampai pemilik
            // memasukkannya (KEPUTUSAN-INTEGRASI.md §4). Tanpa satu pun nilai
            // rahasia di jawaban ini.
            'whatsapp' => [
                'provider' => WhatsAppSetup::provider(),
                'configured' => WhatsAppSetup::configured(),
                'templates_ready' => WhatsAppSetup::templatesReady(),
                'templates_total' => count(NotificationTemplates::KEYS),
                'phone_e164' => $user->phone_e164,
                'opt_in_at' => $user->whatsapp_opt_in_at?->toIso8601String(),
                'opt_in_via' => $user->whatsapp_opt_in_via,
            ],
        ]);
    }
}
