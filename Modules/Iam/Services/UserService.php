<?php

namespace Modules\Iam\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\PhoneNumber;
use Modules\Core\Support\WhatsAppConsent;

class UserService
{
    /**
     * Create a user and assign its roles atomically.
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $roles = $data['roles'] ?? [];
            unset($data['roles']);
            [$data, $consent] = $this->splitConsent($data, null);

            /** @var User $user */
            $user = User::query()->create($data);

            if ($consent !== null) {
                WhatsAppConsent::apply($user, $consent['phone'], $consent['opt_in'], WhatsAppConsent::VIA_ADMIN);
            }

            if ($roles !== []) {
                $user->syncRoles($roles);
            }

            return $user->load('roles');
        });
    }

    /**
     * Update a user; roles are replaced wholesale when the key is present.
     * An empty / null password means "keep the current password".
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $roles = array_key_exists('roles', $data) ? $data['roles'] : null;
            unset($data['roles']);

            if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
                unset($data['password']);
            }

            [$data, $consent] = $this->splitConsent($data, $user);

            $user->update($data);

            if ($consent !== null) {
                WhatsAppConsent::apply($user, $consent['phone'], $consent['opt_in'], WhatsAppConsent::VIA_ADMIN);
            }

            // is_active is only checked at login, so deactivating through an
            // update must also revoke the tokens already issued — otherwise the
            // account keeps full API access until its tokens expire.
            if ($user->wasChanged('is_active') && ! $user->is_active) {
                $user->tokens()->delete();
            }

            if ($roles !== null) {
                $user->syncRoles($roles);
            }

            return $user->refresh()->load('roles');
        });
    }

    /**
     * Users are never hard-deleted (their id is referenced by documents all
     * over the ERP) — deactivate the account and revoke all API tokens.
     */
    public function deactivate(User $user): User
    {
        return DB::transaction(function () use ($user) {
            $user->forceFill(['is_active' => false])->save();
            $user->tokens()->delete();

            return $user;
        });
    }

    /**
     * P-3a (T3a.3): nomor WhatsApp dan opt-in TIDAK ditulis lewat fillable —
     * keduanya melewati WhatsAppConsent, satu-satunya tempat aturan
     * "persetujuan melekat pada nomor dan bertanggal" hidup, dengan via
     * 'admin'. Nomornya dinormalkan ke E.164 (request sudah memastikan bisa).
     *
     * @return array{0: array<string, mixed>, 1: array{phone: ?string, opt_in: ?bool}|null}
     */
    private function splitConsent(array $data, ?User $current): array
    {
        if (! array_key_exists('phone_e164', $data) && ! array_key_exists('whatsapp_opt_in', $data)) {
            return [$data, null];
        }

        $consent = [
            // Nomor tidak dikirim tetapi opt-in dikirim: nomor yang ada tetap
            // (pengguna baru belum punya nomor).
            'phone' => array_key_exists('phone_e164', $data)
                ? PhoneNumber::normalize($data['phone_e164'])
                : $current?->phone_e164,
            'opt_in' => array_key_exists('whatsapp_opt_in', $data) && $data['whatsapp_opt_in'] !== null
                ? (bool) $data['whatsapp_opt_in']
                : null,
        ];

        unset($data['phone_e164'], $data['whatsapp_opt_in']);

        return [$data, $consent];
    }

    /**
     * Replace the user's roles wholesale.
     */
    public function syncRoles(User $user, array $roles): User
    {
        $user->syncRoles($roles);

        return $user->load('roles');
    }
}
