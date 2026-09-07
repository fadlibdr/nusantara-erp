<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Support\ApprovableDocuments;
use Modules\Core\Support\ApprovalDelegations;

/**
 * Delegasi persetujuan "a.n." — milik pemanggilnya sendiri (F-1 / T1.5).
 *
 * SIAPA BOLEH MEMBUAT SATU: hanya orang yang MEMBERIKAN haknya, atau pemegang
 * iam.update (administrator yang menata akun). Bukan gerbang izin di rute
 * melainkan aturan di dalam service, karena keduanya benar sekaligus dan
 * sebuah gerbang tunggal tidak bisa menyatakan keduanya: seorang direktur
 * tanpa iam.update harus tetap bisa menyerahkan haknya sendiri sebelum cuti,
 * dan tidak seorang pun boleh menyerahkan hak orang lain.
 *
 * MEMBUAT DELEGASI TIDAK PERNAH MEMBUAT HAK BARU. Yang dipinjamkan hanyalah
 * izin yang benar-benar DIPEGANG pemberinya, diperiksa saat dipakai
 * (ApprovalDelegations::grants) — jadi sebuah baris yang menyebut awalan yang
 * tidak dipegang pemberinya adalah baris yang tidak memberi apa-apa. Layar
 * memperingatkan saat baris seperti itu dibuat alih-alih diam.
 *
 * DICABUT, BUKAN DIHAPUS: setiap baris "a.n." di jejak persetujuan menunjuk
 * balik ke delegasi yang membuatnya mungkin, dan menghapus barisnya membuat
 * jejak itu tidak dapat dijelaskan.
 */
class ApprovalDelegationController extends ApiController
{
    /**
     * Delegasi yang menyangkut pemanggil: yang ia berikan dan yang ia pegang.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = ApprovalDelegation::query()
            ->with(['giver:id,name', 'delegate:id,name'])
            ->where(fn ($query) => $query
                ->where('giver_user_id', $user->getKey())
                ->orWhere('delegate_user_id', $user->getKey()))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return $this->ok(
            $rows->map(fn (ApprovalDelegation $row) => $this->present($row, $user))->values(),
            null,
            [
                // Awalan yang bisa dipilih = awalan yang punya dokumen
                // ber-approve. Dikirim dari server supaya layar tidak menyalin
                // daftar modul yang akan basi pada modul ke-15.
                'scopes' => $this->scopeOptions(),
                'can_delegate_for_others' => (bool) $user->can('iam.update'),
            ],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'giver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'delegate_user_id' => ['required', 'integer', 'exists:users,id'],
            'scope' => ['nullable', 'string', 'max:20'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], [
            'delegate_user_id.required' => 'Penerima delegasi wajib dipilih.',
            'starts_at.required' => 'Tanggal mulai wajib diisi.',
            'ends_at.after_or_equal' => 'Tanggal selesai tidak boleh mendahului tanggal mulai.',
        ]);

        $giverId = (int) ($data['giver_user_id'] ?? $actor->getKey());

        if ($giverId !== (int) $actor->getKey() && ! $actor->can('iam.update')) {
            throw ValidationException::withMessages([
                'giver_user_id' => 'Anda hanya dapat mendelegasikan hak persetujuan MILIK ANDA SENDIRI. '
                    .'Mendelegasikan hak orang lain memerlukan izin iam.update.',
            ]);
        }

        if ($giverId === (int) $data['delegate_user_id']) {
            throw ValidationException::withMessages([
                'delegate_user_id' => 'Pemberi dan penerima delegasi tidak boleh orang yang sama.',
            ]);
        }

        $scope = $this->normaliseScope($data['scope'] ?? null);

        $delegation = ApprovalDelegation::query()->create([
            'giver_user_id' => $giverId,
            'delegate_user_id' => (int) $data['delegate_user_id'],
            'scope' => $scope,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'reason' => $data['reason'] ?? null,
            'created_by' => $actor->getKey(),
        ]);

        ApprovalDelegations::flushMemo();

        $delegation->load(['giver:id,name', 'delegate:id,name']);

        return $this->ok(
            $this->present($delegation, $actor),
            $this->createdMessage($delegation),
        )->setStatusCode(201);
    }

    /** Mencabut. Baris tetap ada — ia menjelaskan setiap "a.n." yang ditinggalkannya. */
    public function destroy(Request $request, ApprovalDelegation $approvalDelegation): JsonResponse
    {
        $actor = $request->user();

        if ((int) $approvalDelegation->giver_user_id !== (int) $actor->getKey() && ! $actor->can('iam.update')) {
            throw ValidationException::withMessages([
                'id' => 'Delegasi hanya dapat dicabut oleh pemberinya, atau oleh pemegang izin iam.update.',
            ]);
        }

        if ($approvalDelegation->revoked_at === null) {
            $approvalDelegation->forceFill(['revoked_at' => now()])->save();
            ApprovalDelegations::flushMemo();
        }

        $approvalDelegation->load(['giver:id,name', 'delegate:id,name']);

        return $this->ok($this->present($approvalDelegation, $actor), 'Delegasi dicabut.');
    }

    /**
     * Peringatan yang jujur saat baris yang baru dibuat tidak memberi apa pun:
     * pemberinya tidak memegang satu pun hak approve dalam lingkupnya. Barisnya
     * tetap disimpan (jadwal cuti bulan depan sah dibuat sebelum peran
     * pemberinya ditata), tetapi kalimatnya menyebutkannya.
     */
    private function createdMessage(ApprovalDelegation $delegation): string
    {
        $giver = User::query()->find($delegation->giver_user_id);
        $prefixes = $delegation->scope === null
            ? $this->approvablePrefixes()
            : [$delegation->scope];

        foreach ($prefixes as $prefix) {
            foreach (["{$prefix}.approve", "{$prefix}.approve-director"] as $ability) {
                try {
                    if ($giver !== null && $giver->hasPermissionTo($ability, 'web')) {
                        return 'Delegasi disimpan.';
                    }
                } catch (\Throwable) {
                    // izin belum diseed — diperlakukan sebagai tidak dipegang
                }
            }
        }

        return 'Delegasi disimpan — tetapi '.($giver?->name ?? 'pemberinya')
            .' tidak memegang satu pun hak persetujuan dalam lingkup ini, sehingga delegasi ini '
            .'belum memberikan apa pun. Delegasi hanya meminjamkan izin yang benar-benar dipegang.';
    }

    /** @return array<string, mixed> */
    private function present(ApprovalDelegation $row, User $viewer): array
    {
        return [
            'id' => $row->id,
            'giver' => ['id' => $row->giver_user_id, 'name' => $row->giver?->name],
            'delegate' => ['id' => $row->delegate_user_id, 'name' => $row->delegate?->name],
            'scope' => $row->scope,
            'scope_label' => $row->scope === null ? 'Semua persetujuan' : $this->scopeLabel($row->scope),
            'starts_at' => $row->starts_at?->toDateString(),
            'ends_at' => $row->ends_at?->toDateString(),
            'reason' => $row->reason,
            'revoked_at' => $row->revoked_at?->toIso8601String(),
            'is_active' => $row->isActive(),
            'state' => $row->stateLabel(),
            'direction' => (int) $row->giver_user_id === (int) $viewer->getKey() ? 'given' : 'held',
            'can_revoke' => (int) $row->giver_user_id === (int) $viewer->getKey() || $viewer->can('iam.update'),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function scopeOptions(): array
    {
        $options = [];

        foreach ($this->approvablePrefixes() as $prefix) {
            $options[] = ['value' => $prefix, 'label' => $this->scopeLabel($prefix)];
        }

        return $options;
    }

    private function scopeLabel(string $prefix): string
    {
        $labels = [];

        foreach (ApprovableDocuments::all() as $entry) {
            if ($entry['prefix'] === $prefix) {
                $labels[] = $entry['label'];
            }
        }

        // Nama modul BUKAN yang dicetak: yang dibaca orang yang mendelegasikan
        // adalah dokumen apa yang ikut berpindah tangan.
        return $prefix.' — '.implode(', ', array_slice($labels, 0, 3))
            .(count($labels) > 3 ? ', dan '.(count($labels) - 3).' lainnya' : '');
    }

    /** @return list<string> */
    private function approvablePrefixes(): array
    {
        return array_values(array_unique(array_column(ApprovableDocuments::all(), 'prefix')));
    }

    private function normaliseScope(?string $scope): ?string
    {
        $scope = $scope === null ? null : trim($scope);

        if ($scope === null || $scope === '') {
            return null;
        }

        if (! in_array($scope, $this->approvablePrefixes(), true)) {
            throw ValidationException::withMessages([
                'scope' => "Lingkup {$scope} tidak dikenal: tidak ada jenis dokumen yang disetujui dengan awalan itu.",
            ]);
        }

        return $scope;
    }
}
