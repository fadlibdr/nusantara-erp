<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Modules\Core\Http\ApiController;
use Modules\Core\Services\FormPrintService;
use Modules\Core\Support\PrintableDocuments;

/**
 * Formulir rumah, served as printable HTML.
 *
 * One route for every form, unlike the four hand-written PDF routes next door,
 * because the forms are a growing family and each new one would otherwise cost
 * a route, a method and a middleware string. The price of one route is that the
 * permission cannot be route middleware — a laporan harian is prj and a form
 * printed off a payroll run would be hr — so it is derived per request from the
 * form registry, exactly as the master-data and attachment endpoints derive
 * theirs. The house rule is unchanged: a form carries the VIEW permission of the
 * module that owns the record, because printing is reading in another shape.
 *
 * text/html rather than application/pdf, and no dompdf anywhere in the path: the
 * weekly schedule is a landscape grid dompdf cannot lay out. The SPA fetches
 * this with its auth header, blobs it and prints it (see print.js
 * openPrintable) — which is also why the sheet is a standalone document with
 * its own <html> and nothing linked from outside it.
 */
class FormPrintController extends ApiController
{
    public function __construct(private readonly FormPrintService $forms) {}

    /**
     * The catalogue — which house forms THIS caller may print, and where each
     * button belongs.
     *
     * This endpoint is what stops "a print button on every module" from costing
     * forty schema.js edits: the SPA asks once, caches the answer like it caches
     * a lookup source, and draws "Cetak <label>" on whichever screen the entry
     * names. A module lane adds one array entry to PrintableDocuments and the
     * button appears with no front-end change at all.
     *
     * Permission-filtered on the server, not in the browser. A button that
     * always answers 403 is a support ticket, and which documents a role cannot
     * reach is not something to work out from a list it was handed anyway.
     *
     * The seven bespoke forms are deliberately absent: they are still declared
     * on the schema.js entries that carry their query parameters (?tanggal=,
     * ?minggu=), and listing them here as well would draw every one of them
     * twice.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->ok(app(PrintableDocuments::class)->catalogue($request->user()));
    }

    public function show(Request $request, string $form, int $id): Response|JsonResponse
    {
        // The unknown-form check comes first and is deliberately
        // indistinguishable from a typo. The list of forms is not secret — it
        // is printed on the buttons — so there is nothing to leak, and doing it
        // before the record loads keeps the endpoint from answering "does
        // project 812 exist?" for a caller holding no permission at all.
        try {
            $definition = $this->forms->definition($form);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }

        if (! $request->user()?->can($definition['permission'])) {
            return $this->error('Anda tidak memiliki izin untuk mencetak formulir ini.', 403);
        }

        $filters = $request->validate([
            // The form's own date: which day a laporan harian is, which day the
            // "hari ke / sisa hari" counters are measured from. Absent means
            // today, which is what somebody printing a blank pad wants.
            'tanggal' => ['nullable', 'date'],
            'minggu' => ['nullable', 'integer', 'min:1', 'max:520'],
            // Which slice of a register to print — daftar temuan's ?status=.
            // Deliberately NOT validated against a list of values here: the
            // form owns its own vocabulary, and FormPrintService refuses an
            // unknown one by name rather than filtering silently to an empty
            // sheet, which on a punch list is the answer somebody hoping to
            // walk past it would like to get.
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        // Round two of the same argument the unknown-form check makes above: a
        // form that refuses its own parameters — an unknown status, a month of
        // 13 — is telling the caller something, and a 500 loses the sentence.
        try {
            $html = $this->forms->html($form, [
                'id' => $id,
                'date' => $filters['tanggal'] ?? null,
                'week' => isset($filters['minggu']) ? (int) $filters['minggu'] : null,
                'status' => $filters['status'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Length' => (string) strlen($html),
            // Same three as the PDF endpoint. nosniff matters more here, not
            // less: this response really is HTML, and the guarantee worth making
            // is that a browser will never treat it as anything else.
            'X-Content-Type-Options' => 'nosniff',
            // A generated form is never worth caching: the project behind it can
            // change, and a stale laporan harian is worse than a slow one.
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }
}
