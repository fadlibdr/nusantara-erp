<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Support\ModuleCounts;

/**
 * Angka utama per modul untuk launcher `#/home` dan beranda modul `#/m/<prefix>`
 * (P1-C, T1C.2).
 *
 * Muatan yang SAMA dengan blok `modules` pada `core/dashboard/summary?include=
 * modules` — endpoint ini ada supaya launcher tidak perlu meminta ubin uang
 * dasbor yang tidak digambarnya, dan supaya dasbor tetap seringan hari ini bagi
 * yang tidak meminta blok itu.
 *
 * Tanpa gerbang izin, pola calendar/search/deadlines: registri menyaring
 * dirinya sendiri per entri, jadi tidak ada yang tersisa untuk disaring
 * sesudahnya — dan modul yang izinnya tidak dipegang TIDAK ADA di jawaban,
 * bukan hadir dengan angka 0.
 */
class ModuleCountController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->ok(ModuleCounts::for($request->user()));
    }
}
