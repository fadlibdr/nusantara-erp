<?php

use App\Http\Controllers\StatusController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

// The root serves the front-end (a build-free SPA in public/app that talks to
// this API). The previous JSON payload now lives at /status.
Route::get('/', [StatusController::class, 'app']);

Route::get('/status', [StatusController::class, 'web']);

/*
 * There is no login PAGE — the SPA holds a token and the API is stateless. This
 * route exists because Laravel's authentication handler redirects an
 * unauthenticated request to route('login') whenever the request does not ask
 * for JSON, and an undefined named route throws, turning every such call into a
 * 500 instead of a 401.
 *
 * The SPA never reaches it: fetch() sends Accept: application/json and gets a
 * 401 directly. What does reach it is a browser opening an API URL — an
 * attachment download link pasted into the address bar, or reopened after the
 * session expired. Those should say "not signed in", not "server error".
 */
Route::get('/login', static fn (): JsonResponse => new JsonResponse([
    'message' => 'Unauthenticated.',
], 401))->name('login');
