<?php

use Illuminate\Support\Facades\Route;
use Modules\Projects\Http\Controllers\BaselineController;
use Modules\Projects\Http\Controllers\BastController;
use Modules\Projects\Http\Controllers\DailyReportController;
use Modules\Projects\Http\Controllers\DefectController;
use Modules\Projects\Http\Controllers\EvmController;
use Modules\Projects\Http\Controllers\ManpowerAssignmentController;
use Modules\Projects\Http\Controllers\MaterialVarianceController;
use Modules\Projects\Http\Controllers\MilestoneController;
use Modules\Projects\Http\Controllers\ProjectController;
use Modules\Projects\Http\Controllers\SafetyIncidentController;
use Modules\Projects\Http\Controllers\WbsTaskController;
use Modules\Projects\Http\Controllers\WeeklyProgressController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Projects — collection routes. Single-project routes sit at the BOTTOM of
    // this file because `{project}` is a wildcard that would otherwise swallow
    // the literal prefixes below (daily-reports, bast, ...).
    Route::get('/', [ProjectController::class, 'index']);
    Route::post('/', [ProjectController::class, 'store'])->middleware('permission:prj.create');

    // WBS task progress (entered on leaf tasks, rolls up to parents + project)
    Route::put('wbs-tasks/{wbsTask}/progress', [WbsTaskController::class, 'progress'])->middleware('permission:prj.update');
    // Flat leaf listing for the Inventory issue-form picker (paket pekerjaan on
    // a bon). Literal prefix, so it must stay above the `{project}` wildcard.
    // prj.view is safe here: RoleSeeder grants the warehouse role prj.view, so
    // the storeman who raises the bon can load the list.
    Route::get('wbs-tasks', [WbsTaskController::class, 'list'])->middleware('permission:prj.view');

    // Daily reports (laporan harian)
    Route::get('daily-reports', [DailyReportController::class, 'index']);
    Route::post('daily-reports', [DailyReportController::class, 'store'])->middleware('permission:prj.create');
    Route::get('daily-reports/{dailyReport}', [DailyReportController::class, 'show']);
    // Kandidat GRN untuk tabel MATERIAL MASUK (P0-A) — prj.view seperti GET
    // sensitif lain di file ini: barisnya menyebut vendor dan surat jalan.
    Route::get('daily-reports/{dailyReport}/receipts-candidates', [DailyReportController::class, 'receiptsCandidates'])->middleware('permission:prj.view');
    Route::put('daily-reports/{dailyReport}', [DailyReportController::class, 'update'])->middleware('permission:prj.update');
    Route::delete('daily-reports/{dailyReport}', [DailyReportController::class, 'destroy'])->middleware('permission:prj.delete');

    // Weekly progress (kurva-S data points)
    Route::get('weekly-progress', [WeeklyProgressController::class, 'index']);
    Route::post('weekly-progress', [WeeklyProgressController::class, 'store'])->middleware('permission:prj.create');

    // EVM (CPI/SPI) + baseline kurva-S. Literal prefixes, so they must stay
    // above the `{project}` wildcard at the bottom of this file — "baselines"
    // would otherwise be read as a project id and 404 on every request.
    //
    // These GETs carry permission:prj.view while the module's older GETs carry
    // none. Deliberate, and following the Finance pattern: a baseline names the
    // contract value, the RAP total and the cost performance index, which is
    // commercially sensitive in a way a daily report's headcount is not. The
    // existing routes are left untouched.
    Route::get('evm', [EvmController::class, 'index'])->middleware('permission:prj.view');
    Route::get('baselines', [BaselineController::class, 'index'])->middleware('permission:prj.view');
    Route::post('baselines', [BaselineController::class, 'store'])->middleware('permission:prj.create');
    Route::get('baselines/{baseline}', [BaselineController::class, 'show'])->middleware('permission:prj.view');
    Route::put('baselines/{baseline}', [BaselineController::class, 'update'])->middleware('permission:prj.update');
    Route::delete('baselines/{baseline}', [BaselineController::class, 'destroy'])->middleware('permission:prj.delete');
    Route::post('baselines/{baseline}/resnapshot', [BaselineController::class, 'resnapshot'])->middleware('permission:prj.update');
    Route::post('baselines/{baseline}/submit', [BaselineController::class, 'submit'])->middleware('permission:prj.update');
    Route::post('baselines/{baseline}/approve', [BaselineController::class, 'approve'])->middleware('permission:prj.approve');
    Route::post('baselines/{baseline}/reject', [BaselineController::class, 'reject'])->middleware('permission:prj.approve');

    // Milestones
    Route::get('milestones', [MilestoneController::class, 'index']);
    Route::post('milestones', [MilestoneController::class, 'store'])->middleware('permission:prj.create');
    Route::get('milestones/{milestone}', [MilestoneController::class, 'show']);
    Route::put('milestones/{milestone}', [MilestoneController::class, 'update'])->middleware('permission:prj.update');
    Route::delete('milestones/{milestone}', [MilestoneController::class, 'destroy'])->middleware('permission:prj.delete');

    // BAST (berita acara serah terima) + approval lifecycle
    Route::get('bast', [BastController::class, 'index']);
    Route::post('bast', [BastController::class, 'store'])->middleware('permission:prj.create');
    Route::get('bast/{bast}', [BastController::class, 'show']);
    Route::put('bast/{bast}', [BastController::class, 'update'])->middleware('permission:prj.update');
    Route::delete('bast/{bast}', [BastController::class, 'destroy'])->middleware('permission:prj.delete');
    Route::post('bast/{bast}/submit', [BastController::class, 'submit'])->middleware('permission:prj.update');
    Route::post('bast/{bast}/approve', [BastController::class, 'approve'])->middleware('permission:prj.approve');
    Route::post('bast/{bast}/reject', [BastController::class, 'reject'])->middleware('permission:prj.approve');
    // What approving this BAST II will cost — the retensi at stake above all —
    // read before anybody clicks. prj.view, same gate and same ground as the
    // EVM and baseline GETs above: the payload quotes the Rp 2.425.000.000 of
    // retensi and the unbilled termins, which is exactly the money this file
    // decided is not for every authenticated teknisi.
    Route::get('bast/{bast}/prerequisites', [BastController::class, 'prerequisites'])->middleware('permission:prj.view');

    // Register defect (punch list). `summary` is declared before the {defect}
    // wildcard so the literal segment is not swallowed by it, exactly as
    // safety-incidents/statistics is.
    Route::get('defects/summary', [DefectController::class, 'summary']);
    Route::get('defects', [DefectController::class, 'index']);
    Route::post('defects', [DefectController::class, 'store'])->middleware('permission:prj.create');
    Route::get('defects/{defect}', [DefectController::class, 'show']);
    Route::put('defects/{defect}', [DefectController::class, 'update'])->middleware('permission:prj.update');
    Route::delete('defects/{defect}', [DefectController::class, 'destroy'])->middleware('permission:prj.delete');
    // Declaring a repair done is site work. ACCEPTING it is the customer's act,
    // and it is the row BAST II counts — so verify/waive/reopen sit on
    // prj.approve, which site-manager deliberately does not hold.
    Route::post('defects/{defect}/fixed', [DefectController::class, 'fixed'])->middleware('permission:prj.update');
    Route::post('defects/{defect}/verify', [DefectController::class, 'verify'])->middleware('permission:prj.approve');
    Route::post('defects/{defect}/waive', [DefectController::class, 'waive'])->middleware('permission:prj.approve');
    Route::post('defects/{defect}/reopen', [DefectController::class, 'reopen'])->middleware('permission:prj.approve');

    // Register kecelakaan kerja (SMK3). `statistics` is declared before the
    // {safetyIncident} wildcard so the literal segment is not swallowed by it.
    Route::get('safety-incidents/statistics', [SafetyIncidentController::class, 'statistics']);
    Route::get('safety-incidents', [SafetyIncidentController::class, 'index']);
    Route::post('safety-incidents', [SafetyIncidentController::class, 'store'])->middleware('permission:prj.create');
    Route::get('safety-incidents/{safetyIncident}', [SafetyIncidentController::class, 'show']);
    Route::put('safety-incidents/{safetyIncident}', [SafetyIncidentController::class, 'update'])->middleware('permission:prj.update');
    Route::delete('safety-incidents/{safetyIncident}', [SafetyIncidentController::class, 'destroy'])->middleware('permission:prj.delete');
    // Closing one out is an approval-grade act: it asserts the corrective action
    // was done, and the register's whole value rests on that being true.
    Route::post('safety-incidents/{safetyIncident}/close', [SafetyIncidentController::class, 'close'])->middleware('permission:prj.approve');
    Route::post('safety-incidents/{safetyIncident}/reopen', [SafetyIncidentController::class, 'reopen'])->middleware('permission:prj.approve');

    // Manpower assignments
    Route::get('manpower-assignments', [ManpowerAssignmentController::class, 'index']);
    Route::post('manpower-assignments', [ManpowerAssignmentController::class, 'store'])->middleware('permission:prj.create');
    Route::get('manpower-assignments/{manpowerAssignment}', [ManpowerAssignmentController::class, 'show']);
    Route::put('manpower-assignments/{manpowerAssignment}', [ManpowerAssignmentController::class, 'update'])->middleware('permission:prj.update');
    Route::delete('manpower-assignments/{manpowerAssignment}', [ManpowerAssignmentController::class, 'destroy'])->middleware('permission:prj.delete');

    // Single project + domain actions ({project} wildcard — keep last).
    Route::get('{project}', [ProjectController::class, 'show']);
    Route::put('{project}', [ProjectController::class, 'update'])->middleware('permission:prj.update');
    Route::delete('{project}', [ProjectController::class, 'destroy'])->middleware('permission:prj.delete');
    // Tutup proyek. The summary GET carries prj.view like the BAST
    // prerequisites GET, and on the same ground: it quotes the unbilled
    // termins and the retensi that closing would step over. The action itself
    // is approval-grade — the same permission that approves the BAST II which
    // closes a project the ceremonial way.
    Route::get('{project}/closure', [ProjectController::class, 'closure'])->middleware('permission:prj.view');
    Route::post('{project}/close', [ProjectController::class, 'close'])->middleware('permission:prj.approve');
    Route::post('{project}/generate-wbs', [ProjectController::class, 'generateWbs'])->middleware('permission:prj.update');
    Route::get('{project}/s-curve', [ProjectController::class, 'sCurve']);
    Route::get('{project}/evm', [EvmController::class, 'show'])->middleware('permission:prj.view');
    // Varian material: teori AHSP x volume BOQ vs bon gudang. prj.view for the
    // same reason as the EVM GET above — the payload names RAB volumes and
    // harga satuan. The warehouse role holds prj.view, so the storeman who
    // raises the bon can read the variance his tagging produces.
    Route::get('{project}/material-variance', [MaterialVarianceController::class, 'show'])->middleware('permission:prj.view');
    Route::get('{project}/dashboard', [ProjectController::class, 'dashboard']);
    Route::get('{project}/wbs-tasks', [WbsTaskController::class, 'index']);
    Route::post('{project}/wbs-tasks', [WbsTaskController::class, 'store'])->middleware('permission:prj.create');
});
