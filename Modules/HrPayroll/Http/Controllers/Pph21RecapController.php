<?php

namespace Modules\HrPayroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\HrPayroll\Http\Requests\Pph21RecapRequest;
use Modules\HrPayroll\Services\Pph21RecapService;

/**
 * Rekap internal PPh 21/26 bulanan — baca-saja (P-3b, T3b.2).
 *
 * Satu GET, di balik hr.view: rekapnya memasangkan nama pegawai dengan
 * NIK/NPWP dan penghasilannya — data pribadi yang sama yang menggerbangi
 * register sertifikat dan pengajuan cuti. Tidak ada izin baru: hr.view
 * sudah menjaga menu SDM & Payroll dan ketiga register itu. CSV-nya ada di
 * dalam amplop JSON (pola TaxExportController): klien mengautentikasi lewat
 * header, jadi tautan unduh telanjang tidak pernah bisa membawa kredensial.
 */
class Pph21RecapController extends ApiController
{
    public function __construct(private readonly Pph21RecapService $recap) {}

    public function index(Pph21RecapRequest $request): JsonResponse
    {
        try {
            return $this->ok($this->recap->monthly($request->year(), $request->month()));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }
}
