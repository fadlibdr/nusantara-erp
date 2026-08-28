<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Enums\ExternalDecision;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\ExternalApproval;
use Modules\Core\Services\ExternalApprovalService;
use Modules\Core\Support\ExternalApprovableDocuments;

/**
 * Sisi INTERNAL persetujuan eksternal: menerbitkan/mencabut tautan, mencatat
 * lembar fisik, dan membaca daftarnya untuk kartu SPA.
 *
 * Izin diturunkan dari DOKUMEN, bukan dipaku di rute — pola AttachmentController:
 * membaca daftar tautan sebuah laporan harian butuh prj.view, menerbitkan
 * (kuasa setingkat menyetujui) butuh prj.approve. Izin diperiksa SEBELUM
 * resolusi id, supaya "ada atau tidak"-nya sebuah dokumen tidak terjawab bagi
 * yang tidak berhak tahu.
 */
class ExternalApprovalController extends ApiController
{
    public function __construct(private readonly ExternalApprovalService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in(ExternalApprovableDocuments::slugs())],
            'document_id' => ['required', 'integer', 'min:1'],
        ]);

        if (($denied = $this->deny($request, $data['document_type'], 'view')) !== null) {
            return $denied;
        }

        $rows = ExternalApproval::query()
            ->with(['issuedBy:id,name', 'revokedBy:id,name', 'attachment:id,original_name'])
            ->where('document_slug', $data['document_type'])
            ->where('document_id', (int) $data['document_id'])
            ->orderByDesc('id')
            ->get();

        return $this->ok($rows);
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in(ExternalApprovableDocuments::slugs())],
            'document_id' => ['required', 'integer', 'min:1'],
            'party' => ['required', Rule::in(array_keys(ExternalApproval::PARTIES))],
            'name' => ['required', 'string', 'max:120'],
            'organization' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if (($denied = $this->deny($request, $data['document_type'], 'approve')) !== null) {
            return $denied;
        }

        $issued = $this->service->issue($request->user(), $data);

        // Teks polos token hidup HANYA dalam respons ini. Ia tidak disimpan,
        // tidak dicatat, dan tidak bisa diminta ulang — yang hilang berarti
        // cabut lalu terbitkan tautan baru.
        return $this->created([
            'approval' => $issued['approval'],
            'url' => $issued['url'],
        ], 'Tautan persetujuan diterbitkan. Salin sekarang — tautan hanya ditampilkan sekali dan tidak dapat dilihat lagi.');
    }

    public function revoke(Request $request, ExternalApproval $externalApproval): JsonResponse
    {
        if (($denied = $this->deny($request, $externalApproval->document_slug, 'approve')) !== null) {
            return $denied;
        }

        $revoked = $this->service->revoke($externalApproval, $request->user());

        return $this->ok($revoked, "Tautan untuk {$revoked->name} dicabut.");
    }

    public function recordPhysical(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in(ExternalApprovableDocuments::slugs())],
            'document_id' => ['required', 'integer', 'min:1'],
            'party' => ['required', Rule::in(array_keys(ExternalApproval::PARTIES))],
            'name' => ['required', 'string', 'max:120'],
            'organization' => ['nullable', 'string', 'max:150'],
            'decision' => ['required', Rule::enum(ExternalDecision::class)],
            'decision_notes' => ['nullable', 'string', 'max:1000'],
            'decided_at' => ['nullable', 'date'],
            'attachment_id' => ['required', 'integer', 'min:1'],
        ]);

        if (($denied = $this->deny($request, $data['document_type'], 'approve')) !== null) {
            return $denied;
        }

        $approval = $this->service->recordPhysical($request->user(), $data);

        return $this->created(
            ['approval' => $approval],
            "Keputusan {$approval->decision?->label()} dari {$approval->name} tercatat dari lembar fisik.",
        );
    }

    private function deny(Request $request, string $slug, string $action): ?JsonResponse
    {
        $permission = ExternalApprovableDocuments::prefixFor($slug).'.'.$action;

        if ($request->user()?->can($permission)) {
            return null;
        }

        return $this->error("Anda tidak memiliki izin {$permission}.", 403);
    }
}
