<?php

use Illuminate\Support\Facades\Route;
use Modules\ServiceDesk\Http\Controllers\FieldReportController;
use Modules\ServiceDesk\Http\Controllers\PreventiveScheduleController;
use Modules\ServiceDesk\Http\Controllers\ServiceContractController;
use Modules\ServiceDesk\Http\Controllers\TicketController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Maintenance contracts (kontrak pemeliharaan) + SLA
    Route::get('contracts', [ServiceContractController::class, 'index']);
    Route::post('contracts', [ServiceContractController::class, 'store'])->middleware('permission:svc.create');
    Route::get('contracts/{contract}', [ServiceContractController::class, 'show']);
    Route::put('contracts/{contract}', [ServiceContractController::class, 'update'])->middleware('permission:svc.update');
    Route::delete('contracts/{contract}', [ServiceContractController::class, 'destroy'])->middleware('permission:svc.delete');

    // Tickets (tiket layanan)
    Route::get('tickets-sla-breaches', [TicketController::class, 'slaBreaches']);
    Route::get('tickets', [TicketController::class, 'index']);
    Route::post('tickets', [TicketController::class, 'store'])->middleware('permission:svc.create');
    Route::get('tickets/{ticket}', [TicketController::class, 'show']);
    Route::put('tickets/{ticket}', [TicketController::class, 'update'])->middleware('permission:svc.update');
    Route::delete('tickets/{ticket}', [TicketController::class, 'destroy'])->middleware('permission:svc.delete');
    Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign'])->middleware('permission:svc.update');
    Route::post('tickets/{ticket}/activities', [TicketController::class, 'storeActivity'])->middleware('permission:svc.update');
    Route::post('tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->middleware('permission:svc.update');
    Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->middleware('permission:svc.update');

    // Preventive maintenance schedules (jadwal PM)
    Route::get('preventive-schedules', [PreventiveScheduleController::class, 'index']);
    Route::post('preventive-schedules', [PreventiveScheduleController::class, 'store'])->middleware('permission:svc.create');
    Route::post('preventive-schedules/generate-now', [PreventiveScheduleController::class, 'generateNow'])->middleware('permission:svc.create');
    Route::get('preventive-schedules/{schedule}', [PreventiveScheduleController::class, 'show']);
    Route::put('preventive-schedules/{schedule}', [PreventiveScheduleController::class, 'update'])->middleware('permission:svc.update');
    Route::delete('preventive-schedules/{schedule}', [PreventiveScheduleController::class, 'destroy'])->middleware('permission:svc.delete');

    // Field service reports (laporan servis lapangan / berita acara kunjungan)
    Route::get('field-reports', [FieldReportController::class, 'index']);
    Route::post('field-reports', [FieldReportController::class, 'store'])->middleware('permission:svc.create');
    Route::get('field-reports/{fieldReport}', [FieldReportController::class, 'show']);
    Route::put('field-reports/{fieldReport}', [FieldReportController::class, 'update'])->middleware('permission:svc.update');
    Route::delete('field-reports/{fieldReport}', [FieldReportController::class, 'destroy'])->middleware('permission:svc.delete');
    Route::post('field-reports/{fieldReport}/submit', [FieldReportController::class, 'submit'])->middleware('permission:svc.update');
    // The way back out of Submitted, and the only one there is. A submitted
    // report carrying parts blocks its month's close until it is acknowledged,
    // and the acknowledgement can become impossible after the fact (a later
    // movement on the same warehouse/item, or a close over report_date) — so
    // the report must be able to retreat to Draft and be re-dated. svc.update
    // and nothing more: unlike acknowledge, this posts no journal and moves no
    // stock, it only unlocks the form the same right already lets its holder
    // edit. Acknowledged reports are refused by the service.
    Route::post('field-reports/{fieldReport}/return-to-draft', [FieldReportController::class, 'returnToDraft'])->middleware('permission:svc.update');
    // svc.update is the floor, not the whole gate: acknowledging a report that
    // lists spare parts relieves warehouse stock and posts a GL journal, so
    // FieldReportAcknowledgeRequest::authorize() additionally demands inv.post —
    // the same right POST inventory/issues/{issue}/post demands for the identical
    // movement. See that request for why the second right is conditional.
    Route::post('field-reports/{fieldReport}/acknowledge', [FieldReportController::class, 'acknowledge'])->middleware('permission:svc.update');
});
