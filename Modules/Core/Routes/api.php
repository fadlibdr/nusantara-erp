<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\AttachmentController;
use Modules\Core\Http\Controllers\AuditLogController;
use Modules\Core\Http\Controllers\CalendarController;
use Modules\Core\Http\Controllers\CompanyController;
use Modules\Core\Http\Controllers\DashboardController;
use Modules\Core\Http\Controllers\DeadlineController;
use Modules\Core\Http\Controllers\DocumentImportController;
use Modules\Core\Http\Controllers\DocumentPdfController;
use Modules\Core\Http\Controllers\ExternalApprovalController;
use Modules\Core\Http\Controllers\FormPrintController;
use Modules\Core\Http\Controllers\MasterDataController;
use Modules\Core\Http\Controllers\NotificationController;
use Modules\Core\Http\Controllers\ProjectPhotoController;
use Modules\Core\Http\Controllers\SearchController;
use Modules\Core\Http\Controllers\SettingController;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('company', [CompanyController::class, 'show']);
    Route::put('company', [CompanyController::class, 'update'])->middleware('permission:core.update');

    // One search box over everything. No permission gate: the service queries
    // only the groups the caller may read and skips the rest, so there is
    // nothing to filter out afterwards.
    Route::get('search', SearchController::class);

    // Every watched deadline that is close or already past, computed live —
    // the truth that survives a read (or dismissed) notification. No
    // permission gate for the same reason as search: the controller returns
    // only the entries whose permission the caller holds.
    Route::get('deadlines', DeadlineController::class);

    // One month of corporate agenda — kapan sesuatu terjadi, bukan apa yang
    // terlambat (that is deadlines' job). No permission gate for the same
    // reason as search and deadlines: the controller returns only the events
    // whose module the caller may view.
    Route::get('calendar', CalendarController::class);

    // The dashboard's money tiles, summed in SQL over the whole table — the
    // client-side reduce stopped at page 1 and undercounted silently past 100
    // rows. No permission gate for the same reason as search and calendar:
    // each block is included only when the caller holds that module's .view.
    Route::get('dashboard/summary', DashboardController::class);

    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings', [SettingController::class, 'update'])->middleware('permission:core.update');

    // The caller's own inbox. Scoped to $request->user() in the service, so no
    // permission gate applies — and none could help: there is no parameter that
    // would let one user read another's.
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read', [NotificationController::class, 'markRead']);

    // Attachments. No route-level permission: the required one depends on which
    // document the file hangs off, so the controller derives it per request.
    // Who changed what. Read-only: entries are written by model observers, and
    // a log the application can write on request is not evidence.
    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('permission:core.view');

    // Printable documents. Each carries the view permission of the module that
    // owns the record — printing is reading, in another shape.
    Route::get('print/ar-invoices/{arInvoice}', [DocumentPdfController::class, 'arInvoice'])->middleware('permission:fin.view');
    Route::get('print/purchase-orders/{purchaseOrder}', [DocumentPdfController::class, 'purchaseOrder'])->middleware('permission:prc.view');
    Route::get('print/bast/{bast}', [DocumentPdfController::class, 'bast'])->middleware('permission:prj.view');
    Route::get('print/payslips/{payslip}', [DocumentPdfController::class, 'payslip'])->middleware('permission:hr.view');

    // Formulir rumah — the owner's own construction forms (laporan harian,
    // detail schedule, …), returned as printable HTML rather than a PDF: the
    // weekly grid is landscape and dompdf's page is portrait-only. No
    // route-level permission for the same reason as master-data: the right one
    // depends on which form is asked for, so the controller reads it off the
    // form registry — still the owning module's .view, printing being reading.
    // The catalogue of printable documents this caller may reach. Declared
    // BEFORE the {form}/{id} route so a literal path can never be read as a
    // slug, and with no route-level permission because it filters itself: it
    // answers only with the documents the caller's own permissions allow.
    Route::get('print/forms', [FormPrintController::class, 'index']);
    Route::get('print/forms/{form}/{id}', [FormPrintController::class, 'show'])
        ->where('form', '[a-z0-9-]+')
        ->whereNumber('id');

    // Bulk load / export of master data. No route-level permission: the right
    // one depends on which table is being loaded, so the controller derives it
    // per request from the registry.
    Route::get('master-data', [MasterDataController::class, 'index']);
    Route::get('master-data/{resource}/template', [MasterDataController::class, 'template']);
    Route::get('master-data/{resource}/export', [MasterDataController::class, 'export']);
    Route::post('master-data/{resource}/preview', [MasterDataController::class, 'preview']);
    Route::post('master-data/{resource}/import', [MasterDataController::class, 'commit']);

    // The same thing for documents that are a parent plus lines — penawaran,
    // BOQ, AHSP, RAP — which do not fit the flat importer's one-row-one-record
    // shape. No route-level permission for the same reason as above: a penawaran
    // is crm and a BOQ is est, so the controller derives it per request from the
    // registry. Importing needs create AND update; approve is never involved.
    Route::get('document-import', [DocumentImportController::class, 'index']);
    Route::get('document-import/{resource}/template', [DocumentImportController::class, 'template']);
    Route::get('document-import/{resource}/export', [DocumentImportController::class, 'export']);
    Route::post('document-import/{resource}/preview', [DocumentImportController::class, 'preview']);
    Route::post('document-import/{resource}/import', [DocumentImportController::class, 'commit']);

    // Galeri foto progres: every image-mime attachment across one project's
    // documents. No route-level permission — prj.view is checked first in the
    // controller (before the id resolves, to avoid an existence oracle), and
    // each SOURCE of photos then requires its own module's .view.
    Route::get('projects/{project}/photos', ProjectPhotoController::class)->whereNumber('project');

    // Persetujuan eksternal MK/Owner (P0-F). No route-level permission for the
    // attachments reason: the right one depends on which document the mandate
    // hangs off, so the controller derives it per request from the registry —
    // {prefix}.view to read the list, {prefix}.approve to issue, revoke, or
    // record a signed physical sheet (issuing a decision link is
    // approve-adjacent power). The plaintext token appears ONLY in the issue
    // response, exactly once.
    Route::get('external-approvals', [ExternalApprovalController::class, 'index']);
    Route::post('external-approvals', [ExternalApprovalController::class, 'issue']);
    Route::post('external-approvals/record-physical', [ExternalApprovalController::class, 'recordPhysical']);
    Route::post('external-approvals/{externalApproval}/revoke', [ExternalApprovalController::class, 'revoke'])->whereNumber('externalApproval');

    Route::get('attachments', [AttachmentController::class, 'index']);
    Route::post('attachments', [AttachmentController::class, 'store']);
    // Multipart, for the 25 MB class (dwg/dxf/mpp) that base64-inside-JSON
    // arithmetically cannot carry — see AttachmentService::MAX_BYTES.
    Route::post('attachments/upload', [AttachmentController::class, 'upload']);
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])->whereNumber('attachment');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('attachment');
});
