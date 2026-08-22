<?php

use Illuminate\Support\Facades\Route;
use Modules\Iam\Http\Controllers\AuthController;
use Modules\Iam\Http\Controllers\PermissionController;
use Modules\Iam\Http\Controllers\RoleController;
use Modules\Iam\Http\Controllers\UserController;

// Public (unauthenticated) — brute-force protected.
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

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
