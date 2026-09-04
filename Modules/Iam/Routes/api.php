<?php

use Illuminate\Support\Facades\Route;
use Modules\Iam\Http\Controllers\AuthController;
use Modules\Iam\Http\Controllers\PermissionController;
use Modules\Iam\Http\Controllers\RoleController;
use Modules\Iam\Http\Controllers\UserController;

// Public (unauthenticated) — brute-force protected.
// Akun demo untuk halaman masuk — HANYA di luar produksi. Sebelum ini
// app.js menuliskan daftar email peran internal di halaman masuk publik
// tanpa memeriksa lingkungan (asesmen UX 2 Sep 2026).
Route::get('auth/demo-accounts', fn () => response()->json([
    'data' => app()->environment('production')
        ? []
        : ['admin@nusantara.test', 'direktur@nusantara.test', 'finance@nusantara.test', 'project-manager@nusantara.test'],
]));
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Lupa kata sandi (T2.7). password-help adalah saudara demo-accounts: halaman
// masuk bertanya ke server apakah tautan email sungguh sampai (MAIL_MAILER
// bukan log) dan siapa administratornya, bukan menebak. Dua rute berikutnya
// memakai batas yang sama dengan login: keduanya pintu tanpa sesi.
Route::get('auth/password-help', [AuthController::class, 'passwordHelp']);
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    // Ganti kata sandi sendiri — sandi lama wajib (ChangePasswordRequest).
    // Menu akun hanya "Tutup · Keluar" sampai 2 Sep 2026 (HASIL-UJI §1, S9).
    Route::put('me/password', [AuthController::class, 'changePassword']);

    Route::middleware('permission:iam.view')->group(function (): void {
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('roles/{role}', [RoleController::class, 'show']);
        Route::get('permissions', [PermissionController::class, 'index']);
    });

    Route::middleware('permission:iam.create')->group(function (): void {
        Route::post('users', [UserController::class, 'store']);
        Route::post('roles', [RoleController::class, 'store']);
    });

    Route::middleware('permission:iam.update')->group(function (): void {
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::post('users/{user}/roles', [UserController::class, 'syncRoles']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::post('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
    });

    Route::middleware('permission:iam.delete')->group(function (): void {
        Route::delete('users/{user}', [UserController::class, 'destroy']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    });
});
