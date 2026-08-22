<?php

namespace Modules\Iam\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Http\ApiController;
use Modules\Iam\Http\Requests\LoginRequest;
use Modules\Iam\Http\Resources\UserResource;

class AuthController extends ApiController
{
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            return $this->error('Email atau password salah.', 401);
        }

        if (! $user->is_active) {
            return $this->error('Akun Anda dinonaktifkan. Hubungi administrator.', 403);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ], 'Login berhasil');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Logout berhasil');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(new UserResource($request->user()->load('roles')));
    }
}
