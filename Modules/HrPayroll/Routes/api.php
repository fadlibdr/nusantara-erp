<?php

use Illuminate\Support\Facades\Route;
use Modules\HrPayroll\Http\Controllers\AttendanceController;
use Modules\HrPayroll\Http\Controllers\AttendanceRecapController;
use Modules\HrPayroll\Http\Controllers\CertificateController;
use Modules\HrPayroll\Http\Controllers\EmployeeController;
use Modules\HrPayroll\Http\Controllers\LeaveRequestController;
use Modules\HrPayroll\Http\Controllers\PayrollRunController;
use Modules\HrPayroll\Http\Controllers\Pph21RecapController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Employees
    Route::get('employees', [EmployeeController::class, 'index']);
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:hr.create');
    Route::get('employees/{employee}', [EmployeeController::class, 'show']);
    Route::put('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:hr.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:hr.delete');
    Route::get('employees/{employee}/payslips', [EmployeeController::class, 'payslips']);
    Route::get('employees/{employee}/leave-balance', [EmployeeController::class, 'leaveBalance']);

    // Certificates (register sertifikat SKK / K3 / principal). The GETs are
    // gated too: the register pairs employee names with certificate numbers,
    // issuers and expiry dates — personal data a procurement- or servis-only
    // token has no business reading. Same convention as the Crm guarantee
    // register, which gates its GETs with crm.view.
    Route::get('certificates', [CertificateController::class, 'index'])->middleware('permission:hr.view');
    Route::post('certificates', [CertificateController::class, 'store'])->middleware('permission:hr.create');
    Route::get('certificates/{certificate}', [CertificateController::class, 'show'])->middleware('permission:hr.view');
    Route::put('certificates/{certificate}', [CertificateController::class, 'update'])->middleware('permission:hr.update');
    Route::delete('certificates/{certificate}', [CertificateController::class, 'destroy'])->middleware('permission:hr.delete');

    // Attendance recaps (rekap absensi bulanan)
    Route::get('attendance-recaps', [AttendanceRecapController::class, 'index']);
    // DI ATAS rute berparameter di bawahnya, atau 'proposal' ditelan pengikat
    // model dan menjadi 404. Usulan saja — tidak ada POST pasangannya, dan itu
    // disengaja (AttendanceRecapProposalService).
    Route::get('attendance-recaps/proposal', [AttendanceRecapController::class, 'proposal'])->middleware('permission:hr.view');
    Route::post('attendance-recaps', [AttendanceRecapController::class, 'store'])->middleware('permission:hr.create');
    Route::get('attendance-recaps/{attendanceRecap}', [AttendanceRecapController::class, 'show']);
    Route::put('attendance-recaps/{attendanceRecap}', [AttendanceRecapController::class, 'update'])->middleware('permission:hr.update');
    Route::delete('attendance-recaps/{attendanceRecap}', [AttendanceRecapController::class, 'destroy'])->middleware('permission:hr.delete');

    // Cuti/izin (finding #22). The GETs are gated like the certificate
    // register above: a leave request carries WHY somebody was away — sakit
    // with a surat dokter attached, cuti khusus for a family death — personal
    // data no procurement- or servis-only token has any business reading.
    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->middleware('permission:hr.view');
    Route::post('leave-requests', [LeaveRequestController::class, 'store'])->middleware('permission:hr.create');
    Route::get('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->middleware('permission:hr.view');
    Route::put('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'update'])->middleware('permission:hr.update');
    Route::delete('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'destroy'])->middleware('permission:hr.delete');
    Route::post('leave-requests/{leaveRequest}/submit', [LeaveRequestController::class, 'submit'])->middleware('permission:hr.update');
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->middleware('permission:hr.approve');
    Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->middleware('permission:hr.approve');

    /*
     * Absensi harian (register, half 2 of finding #22).
     *
     * GET-nya SEMULA tanpa gerbang, dengan alasan yang ditulis di sini: "siapa
     * yang di lokasi tidak membawa sebab maupun diagnosis". F-4 mencabut
     * alasan itu. Sejak barisnya membawa koordinat, akurasi fix, jam datang
     * dan selfie, register ini menjadi riwayat POSISI seseorang hari demi
     * hari — data pribadi yang setara dengan register sertifikat dan
     * pengajuan cuti di atas, dan keduanya dijaga hr.view. Menu SDM & Payroll
     * memang sudah dijaga hr.view di schema.js, jadi tidak ada layar yang
     * kehilangan pintunya; yang ditutup adalah token yang memanggil API
     * langsung.
     *
     * Lembar absensi (absensi.js) adalah EDITOR-nya: bulk idempoten pada
     * (employee, date), jadi mengoreksi satu baris = buka tanggalnya, ubah,
     * simpan ulang. show/update/destroy per-baris adalah pintu API
     * (integrasi/koreksi admin), sengaja tanpa layar sendiri.
     */
    Route::get('attendances', [AttendanceController::class, 'index'])->middleware('permission:hr.view');
    /*
     * "Absensi Saya" — TANPA izin hr.*, dan sengaja DI ATAS rute berparameter
     * di bawahnya: 'attendances/me' yang didaftarkan sesudah
     * 'attendances/{attendance}' akan ditelan pengikat model dan menjawab 404
     * untuk setiap orang.
     *
     * Ketiganya hanya menyentuh baris milik pemanggil sendiri — kuncinya
     * users.employee_id, bukan sebuah field employee_id di badan permintaan.
     * Absen masuk/pulang tidak menuntut hr.create karena tukang di lapangan
     * tidak memegang izin HR mana pun, dan absensi yang hanya bisa dicatat
     * oleh kerani adalah absensi kertas dengan langkah tambahan.
     */
    Route::get('attendances/me', [AttendanceController::class, 'mine']);
    Route::post('attendances/me/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('attendances/me/clock-out', [AttendanceController::class, 'clockOut']);
    Route::post('attendances/bulk', [AttendanceController::class, 'bulk'])->middleware('permission:hr.create');
    Route::get('attendances/{attendance}', [AttendanceController::class, 'show'])->middleware('permission:hr.view');
    Route::get('attendances/{attendance}/corrections', [AttendanceController::class, 'corrections'])->middleware('permission:hr.view');
    Route::put('attendances/{attendance}', [AttendanceController::class, 'update'])->middleware('permission:hr.update');
    Route::delete('attendances/{attendance}', [AttendanceController::class, 'destroy'])->middleware('permission:hr.delete');

    // Payroll runs
    Route::get('payroll-runs', [PayrollRunController::class, 'index']);
    Route::post('payroll-runs', [PayrollRunController::class, 'store'])->middleware('permission:hr.create');
    Route::get('payroll-runs/{payrollRun}', [PayrollRunController::class, 'show']);
    Route::put('payroll-runs/{payrollRun}', [PayrollRunController::class, 'update'])->middleware('permission:hr.update');
    Route::delete('payroll-runs/{payrollRun}', [PayrollRunController::class, 'destroy'])->middleware('permission:hr.delete');
    Route::post('payroll-runs/{payrollRun}/calculate', [PayrollRunController::class, 'calculate'])->middleware('permission:hr.update');
    Route::post('payroll-runs/{payrollRun}/submit', [PayrollRunController::class, 'submit'])->middleware('permission:hr.update');
    Route::post('payroll-runs/{payrollRun}/approve', [PayrollRunController::class, 'approve'])->middleware('permission:hr.approve');
    Route::post('payroll-runs/{payrollRun}/reject', [PayrollRunController::class, 'reject'])->middleware('permission:hr.approve');
    Route::get('payroll-runs/{payrollRun}/payslips', [PayrollRunController::class, 'payslips']);

    /*
     * P-3b — rekap internal PPh 21/26 bulanan dari snapshot slip run yang
     * disetujui/diposting. Baca-saja, di balik hr.view (nama + NIK/NPWP +
     * penghasilan = data pribadi, gerbang yang sama dengan register
     * sertifikat dan cuti). Jalurnya sendiri ('pph21-recap', bukan
     * 'payroll-runs/pph21-recap') supaya tidak pernah ditelan pengikat model
     * {payrollRun} — jebakan yang sama dengan 'attendances/me' di atas.
     */
    Route::get('pph21-recap', [Pph21RecapController::class, 'index'])->middleware('permission:hr.view');
});
