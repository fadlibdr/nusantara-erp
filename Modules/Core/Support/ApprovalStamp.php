<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Approval;

/**
 * Dua stempel pada baris core_approvals, ditulis di SATU tempat (F-1).
 *
 * `policy` pada baris `submitted` — aturan yang berlaku saat dokumen diajukan.
 * `on_behalf_of_user_id` pada baris keputusan — pemberi delegasi yang haknya
 * dipakai.
 *
 * DIPASANG SEBAGAI OBSERVER MODEL, bukan sebagai baris di dalam
 * Traits\Approvable, dengan alasan yang sama persis yang membuat AuditService
 * menjadi observer: TIDAK semua yang mengajukan dokumen memakai trait itu.
 * Payment punya enum status sendiri, ProjectBaseline berjalan lewat
 * BaselineService, JournalService menulis barisnya sendiri — tiga jalur yang
 * akan terlewat, dan tepat jalur yang terlewat itulah yang akan dicari sebuah
 * penyelidikan. Satu observer menangkap keempatnya sekaligus, termasuk jalur
 * yang ditulis orang berikutnya.
 *
 * TIDAK PERNAH MENJATUHKAN APA YANG DIAMATINYA. Ia berjalan di dalam transaksi
 * yang sedang menyimpan pengajuannya. Kegagalan dicatat dan ditelan — dan
 * akibatnya dinyatakan apa adanya di sini alih-alih disembunyikan: sebuah
 * stempel yang gagal berarti dokumen itu jatuh ke resolusi langsung saat
 * disetujui, yaitu perilaku sebelum paket ini. Lebih buruk daripada stempel,
 * tidak lebih buruk daripada kemarin, dan peringatannya bernama.
 */
final class ApprovalStamp
{
    /** Ditulis Approval::creating. */
    public static function stamp(Approval $approval): void
    {
        try {
            self::stampPolicy($approval);
            self::stampOnBehalfOf($approval);
        } catch (\Throwable $e) {
            Log::warning('Approval stamp failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    private static function stampPolicy(Approval $approval): void
    {
        if ($approval->action !== 'submitted' || $approval->policy !== null) {
            return;
        }

        if (! Schema::hasColumn('core_approvals', 'policy')) {
            return;
        }

        $document = self::documentOf($approval);

        if ($document === null) {
            return;
        }

        $policy = ApprovalPolicy::forDocument($document);

        if ($policy === null) {
            // Jenis yang tidak ada di registri: tidak ada kebijakan untuk
            // dicap, dan mengarang satu berarti mengarang ambang.
            return;
        }

        $approval->policy = $policy->stampFor(ApprovalPolicy::amountOf($document));
    }

    private static function stampOnBehalfOf(Approval $approval): void
    {
        if (! in_array($approval->action, ['approved', 'rejected'], true)) {
            return;
        }

        if ($approval->user_id === null || $approval->on_behalf_of_user_id !== null) {
            return;
        }

        if (! Schema::hasColumn('core_approvals', 'on_behalf_of_user_id')) {
            return;
        }

        $document = self::documentOf($approval);
        $approver = User::query()->find($approval->user_id);

        if ($document === null || $approver === null) {
            return;
        }

        $approval->on_behalf_of_user_id = ApprovalDelegations::actingForId(
            $approver,
            ApprovableDocuments::approvePermission($document),
        );
    }

    /**
     * Dokumen yang baris ini menempel padanya.
     *
     * Dibaca dari kolom morph dan bukan dari relasi: baris ini sedang DIBUAT,
     * relasinya belum bisa dimuat, dan kelasnya sudah tertulis di sana.
     */
    private static function documentOf(Approval $approval): ?Model
    {
        $type = (string) $approval->approvable_type;
        $id = $approval->approvable_id;

        if ($type === '' || $id === null) {
            return null;
        }

        $class = Model::getActualClassNameForMorph($type);

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($id);
    }
}
