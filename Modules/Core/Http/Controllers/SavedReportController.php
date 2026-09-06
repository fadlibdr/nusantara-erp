<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\SavedReport;
use Modules\Core\Services\ReportXlsxExportService;
use Modules\Core\Services\SavedReportService;
use Modules\Core\Support\ReportableResources;

/**
 * Laporan Bebas yang disimpan (Fase 1 / P1-F).
 *
 * Tanpa gerbang izin di rute, alasan yang sama dengan ReportController: izin
 * sebuah laporan adalah izin SUMBERnya, dan SavedReportService menyaring
 * daftarnya per baris. Kepemilikan dijaga service dan dilaporkan 422 — bukan
 * 403 — karena orang yang mencoba menyunting laporan orang lain BOLEH
 * membacanya; ia hanya bukan pemiliknya, dan kalimatnya menyebut jalan
 * keluarnya.
 */
class SavedReportController extends ApiController
{
    public function __construct(private readonly SavedReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $out = [];

        foreach ($this->service->visibleTo($user) as $report) {
            $out[] = $this->present($report, $user->getKey());
        }

        return $this->ok($out);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $report = $this->service->create($request->user(), $request->all());
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['definition' => [$e->getMessage()]]);
        }

        return $this->created($this->present($report, $request->user()->getKey()));
    }

    public function show(Request $request, string $savedReport): JsonResponse
    {
        $report = $this->resolve($request, $savedReport);

        if ($report === null) {
            return $this->notVisible();
        }

        return $this->ok($this->present($report, $request->user()->getKey()));
    }

    public function update(Request $request, string $savedReport): JsonResponse
    {
        $report = $this->resolve($request, $savedReport, manage: true);

        if ($report === null) {
            return $this->notVisible();
        }

        try {
            $report = $this->service->update($request->user(), $report, $request->all());
        } catch (InvalidArgumentException $e) {
            /* LEBIH DULU daripada LogicException, dan itu bukan gaya melainkan
               syarat: InvalidArgumentException MEWARISI LogicException di PHP,
               jadi urutan sebaliknya membuat lengan kedua tidak pernah
               tercapai — setiap galat definisi akan dilabeli 'owner' dan
               terbaca sebagai "Anda bukan pemiliknya". */
            return $this->error($e->getMessage(), 422, ['definition' => [$e->getMessage()]]);
        } catch (LogicException $e) {
            // Bukan pemiliknya. 422, dengan kalimat yang menyebut pemiliknya
            // dan jalan keluarnya — pola PettyCashVoucherService::assertCustodian.
            return $this->error($e->getMessage(), 422, ['owner' => [$e->getMessage()]]);
        }

        return $this->ok($this->present($report, $request->user()->getKey()));
    }

    public function destroy(Request $request, string $savedReport): JsonResponse
    {
        $report = $this->resolve($request, $savedReport, manage: true);

        if ($report === null) {
            return $this->notVisible();
        }

        try {
            $this->service->delete($request->user(), $report);
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422, ['owner' => [$e->getMessage()]]);
        }

        return $this->ok(null, 'Laporan dihapus.');
    }

    public function copy(Request $request, string $savedReport): JsonResponse
    {
        $report = $this->resolve($request, $savedReport);

        if ($report === null) {
            return $this->notVisible();
        }

        try {
            $report = $this->service->copy($request->user(), $report, $request->input('name'));
        } catch (LogicException|InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->created($this->present($report, $request->user()->getKey()));
    }

    /**
     * XLSX sebuah laporan tersimpan — GET, karena `api.blob` di SPA hanya GET
     * dan hanya jalur itu yang membawa X-Api-Token.
     *
     * Header disalin verbatim dari FormPrintController::xlsx().
     */
    public function xlsx(Request $request, string $savedReport): Response|JsonResponse
    {
        $report = $this->resolve($request, $savedReport);

        if ($report === null) {
            return $this->notVisible();
        }

        try {
            $export = app(ReportXlsxExportService::class)->export($report);
        } catch (InvalidArgumentException|LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return response($export['content'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Content-Length' => (string) strlen($export['content']),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    /**
     * Id → baris yang boleh dibaca pemanggil, atau null.
     *
     * Diselesaikan DI SINI dan bukan lewat binding implisit: binding menjawab
     * id yang tidak ada dengan pesan Laravel, sementara laporan yang ada
     * tetapi tersembunyi dijawab kalimat kami — dan dua 404 yang berbeda
     * bunyinya membocorkan tepat apa yang 404 itu ada untuk menutupi.
     */
    private function resolve(Request $request, string $id, bool $manage = false): ?SavedReport
    {
        $report = ctype_digit($id) ? SavedReport::query()->find((int) $id) : null;

        if ($report === null) {
            return null;
        }

        $user = $request->user();

        /* `manage` = menamai ulang, membagikan, menghapus. Pemilik boleh
           melakukannya bahkan setelah izin sumbernya dicabut — kalau tidak,
           barisnya tinggal selamanya tanpa satu pun cara membuangnya
           (temuan verifikasi P1-F). MEMBACA angkanya tetap butuh izin. */
        if ($manage && $this->service->canManage($user, $report)) {
            return $report;
        }

        return $this->service->canRead($user, $report) ? $report : null;
    }

    /**
     * 404, bukan 403: sebuah laporan yang tidak boleh dibaca pemanggil tidak
     * boleh membedakan dirinya dari laporan yang tidak ada — kalau tidak,
     * endpoint ini menjadi cara menghitung berapa laporan yang dimiliki orang
     * lain (pola ProjectPhotoController).
     */
    private function notVisible(): JsonResponse
    {
        return $this->error('Laporan tidak ditemukan.', 404);
    }

    /** @return array<string, mixed> */
    private function present(SavedReport $report, int $viewerId): array
    {
        $entry = ReportableResources::has($report->resource)
            ? ReportableResources::definition($report->resource)
            : null;

        return [
            'id' => $report->id,
            'name' => $report->name,
            'resource' => $report->resource,
            'resource_label' => $entry['label'] ?? $report->resource,
            'definition' => $report->definition,
            'shared_roles' => $report->sharedRoles(),
            // Peran yang sudah tidak ada lagi DISEBUT, bukan disembunyikan:
            // berbagi yang diam-diam berhenti bekerja adalah fitur yang rusak
            // tanpa ada yang tahu.
            'stale_roles' => $this->service->staleRoles($report),
            'owner_id' => $report->user_id,
            'owner_name' => $report->user?->name,
            'is_owner' => $report->user_id === $viewerId,
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }
}
