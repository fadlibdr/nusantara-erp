<?php

namespace Modules\Iam\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

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

            /** @var User $user */
            $user = User::query()->create($data);

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

            $user->update($data);

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
     * Replace the user's roles wholesale.
     */
    public function syncRoles(User $user, array $roles): User
    {
        $user->syncRoles($roles);

        return $user->load('roles');
    }
}
