<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Http\ApiController;
use Modules\Crm\Services\TenderQualificationService;

/**
 * Penyusun kualifikasi — BACA SAJA. Tidak ada POST/PUT/DELETE di sini dan itu
 * bukan kelalaian: yang disusun layar ini adalah lampiran dari master yang
 * sudah dimiliki modul lain (hr_certificates, ast_assets, prc_vendors), dan
 * satu-satunya cara memperbaiki isinya adalah memperbaiki masternya di modul
 * pemiliknya. Sebuah endpoint tulis di sini akan menjadi salinan kedua.
 */
class TenderQualificationController extends ApiController
{
    public function __construct(private readonly TenderQualificationService $qualification) {}

    /**
     * ?as_of=YYYY-MM-DD — daftar dijawab sebagaimana pada tanggal itu, bukan
     * sebagaimana hari ini; lembar kualifikasi bertanggal harus menjawab
     * pertanyaan yang sama setiap kali dibuka.
     */
    public function personnel(Request $request): JsonResponse
    {
        return $this->ok($this->qualification->personnel(
            $this->asOf($request),
            $request->filled('certificate_type') ? (string) $request->query('certificate_type') : null,
        ));
    }

    /**
     * ?as_of= juga di sini, dan bukan hiasan: masa sewa berakhir persis seperti
     * sertifikat berakhir, jadi tabel alat harus menjawab tanggal yang sama
     * dengan tabel personil di layar yang sama.
     */
    public function equipment(Request $request): JsonResponse
    {
        return $this->ok($this->qualification->equipment(
            $request->filled('ownership') ? (string) $request->query('ownership') : null,
            $this->asOf($request),
        ));
    }

    /** ?as_of=YYYY-MM-DD, atau null bila tidak diberikan / tidak berbentuk tanggal. */
    private function asOf(Request $request): ?Carbon
    {
        return $request->filled('as_of') && Carbon::hasFormat((string) $request->query('as_of'), 'Y-m-d')
            ? Carbon::parse((string) $request->query('as_of'))
            : null;
    }

    public function subcontractors(): JsonResponse
    {
        return $this->ok($this->qualification->subcontractors());
    }
}
