<?php

use Illuminate\Support\Facades\Route;
use Modules\HrPayroll\Http\Controllers\AttendanceController;
use Modules\HrPayroll\Http\Controllers\AttendanceRecapController;
use Modules\HrPayroll\Http\Controllers\CertificateController;
use Modules\HrPayroll\Http\Controllers\EmployeeController;
use Modules\HrPayroll\Http\Controllers\LeaveRequestController;
use Modules\HrPayroll\Http\Controllers\PayrollRunController;

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

    // Absensi harian (register, half 2 of finding #22). Ungated GETs like the
    // monthly recaps: who was on site carries no reason and no diagnosis.
    // Lembar absensi (absensi.js) adalah EDITOR-nya: bulk idempoten pada
    // (employee, date), jadi mengoreksi satu baris = buka tanggalnya, ubah,
    // simpan ulang. show/update/destroy per-baris di bawah adalah pintu API
    // (integrasi/koreksi admin), sengaja tanpa layar sendiri.
    Route::get('attendances', [AttendanceController::class, 'index']);
    Route::post('attendances/bulk', [AttendanceController::class, 'bulk'])->middleware('permission:hr.create');
    Route::get('attendances/{attendance}', [AttendanceController::class, 'show']);
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
});
