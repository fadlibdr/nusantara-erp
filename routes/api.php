<?php

use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StatusController::class, 'api']);

Route::middleware('auth:sanctum')->get('/user', [StatusController::class, 'user']);
