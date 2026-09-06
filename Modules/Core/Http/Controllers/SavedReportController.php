<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            $out[] = $this->present($report, $user);
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

        return $this->created($this->present($report, $request->user()));
    }

    public function show(Request $request, string $savedReport): JsonResponse
    {
        // Baris SENDIRI, juga tanpa izin sumbernya: daftar menampilkannya (ia
        // harus bisa dibuang), jadi 404 di sini adalah dua jawaban berbeda
        // untuk satu id — GET 404 sementara PUT dan DELETE 200 (verifikasi
        // kedua P1-F). Yang dikirim adalah PERTANYAAN yang ditulis orangnya
        // sendiri; angkanya tetap butuh izin, dan `readable` mengatakannya.
        $report = $this->resolve($request, $savedReport, manage: true);

        if ($report === null) {
            return $this->notVisible();
        }

        return $this->ok($this->present($report, $request->user()));
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

        return $this->ok($this->present($report, $request->user()));
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

        return $this->created($this->present($report, $request->user()));
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

        /* `manage` = melihat barisnya, menamai ulang, membagikan, menghapus.
           Pemilik boleh melakukannya bahkan setelah izin sumbernya dicabut —
           kalau tidak, barisnya tinggal selamanya tanpa satu pun cara
           membuangnya (temuan verifikasi P1-F). MEMBACA ANGKAnya tetap butuh
           izin, dan itulah kenapa `copy` dan `xlsx` — dua verb yang benar-benar
           menjalankan kuerinya — sengaja TIDAK memakai lengan ini. */
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
    private function present(SavedReport $report, User $viewer): array
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
            'is_owner' => $report->user_id === $viewer->getKey(),
            /* ANGKAnya boleh dibaca pemanggil? Baris sendiri tetap terlihat
               setelah izin sumbernya dicabut (supaya bisa dibuang), tetapi
               Buka dan XLSX-nya harus tertutup dan sebabnya tertulis —
               daftar yang menawarkan tombol yang selalu 404 lebih buruk
               daripada daftar yang mengatakannya (verifikasi kedua P1-F). */
            'readable' => $this->service->canRead($viewer, $report),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }
}
