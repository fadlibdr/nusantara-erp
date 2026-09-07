<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Finance\Services\BudgetRealisationService;

/**
 * Anggaran vs realisasi — portofolio dan per proyek × bulan (F-2 / T2.2, T2.3).
 *
 * Tipis dengan sengaja: setiap angka di dua endpoint ini lahir di
 * BudgetRealisationService, yang juga dibaca gerbang anggaran PO/SPK. Tidak ada
 * satu pun aritmetika di controller ini, jadi tidak ada tempat bagi jawaban
 * kedua untuk tumbuh.
 */
class BudgetRealisationController extends ApiController
{
    public function __construct(private readonly BudgetRealisationService $budgets) {}

    /** Satu baris per proyek — angka yang sama dengan yang menolak PO/SPK. */
    public function portfolio(Request $request): JsonResponse
    {
        $rows = $this->budgets->portfolio($request->boolean('include_closed'));

        return $this->ok($rows, null, [
            'projects' => count($rows),
            // Berapa proyek yang TIDAK punya anggaran untuk diukur, dilaporkan
            // apa adanya: sebuah portofolio yang menyembunyikan ini terbaca
            // "semua proyek terkendali" padahal separuhnya tidak punya RAP.
            'without_budget' => count(array_filter($rows, static fn (array $row): bool => $row['budget'] === null)),
        ]);
    }

    /**
     * Satu proyek — dipakai formulir PO/SPK dan layar proyek untuk memasang
     * peringatan ≥ 90 % DI TEMPAT UANGNYA DIBELANJAKAN (T2.6).
     *
     * Izinnya fin.view ATAU prc.create ATAU scm.create, dan itu bukan
     * kelonggaran: siapa pun yang boleh mengajukan PO/SPK untuk sebuah proyek
     * SUDAH diberi tahu angka anggaran proyek itu oleh gerbangnya — kalimat
     * penolakannya menyebut anggaran, realisasi, komitmen dan sisanya. Yang
     * ditambahkan endpoint ini adalah memberitahunya SEBELUM ia mengetik satu
     * baris, bukan sesudah dokumennya ditolak.
     */
    public function project(Request $request, int $project): JsonResponse
    {
        return $this->ok($this->budgets->project($project));
    }

    /** Per bulan untuk satu proyek. */
    public function monthly(Request $request, int $project): JsonResponse
    {
        return $this->ok($this->budgets->monthly($project));
    }
}
