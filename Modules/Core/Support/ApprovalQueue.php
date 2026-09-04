<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Antrean persetujuan — satu implementasi untuk tiga pembaca: kotak masuk
 * (InboxController), kartu dasbor, dan penjaga umur antrean
 * (erp:approval-watch). Berjalan atas ApprovableDocuments, registri yang sama
 * dengan notifikasi, jadi jenis dokumen baru ikut otomatis.
 *
 * Diukur di produksi 4 Sep 2026: PAY/2026/VIII/0002 (Rp 10 jt) berstatus
 * submitted 33 hari; bukan salah satu dari 11 jenis di kartu dasbor lama, dan
 * tidak ada satu pun tanggal di WatchedDeadlines yang mengawasi "menunggu
 * persetujuan". Kelas ini menutup keduanya.
 *
 * "MILIK SENDIRI". Maker-checker (SegregationOfDuties) membaca baris
 * `submitted` di core_approvals dan MELEWATKAN dokumen tanpa jejak pengajuan
 * — by design, untuk dokumen yang dicetak mesin. Tetapi kotak masuk tidak boleh
 * MENAWARKAN dokumen yang jelas milik pembacanya: PR/2026/III/0002 "Diminta
 * oleh admin" tampil sebagai "menunggu persetujuan Anda" untuk admin, dan satu
 * klik menyetujuinya (4 Sep 2026, produksi). Jadi bila jejak pengajuan tidak
 * ada, kolom kepemilikan yang ada di tabel (requested_by, created_by,
 * submitted_by, employee_id → users.employee_id) dipakai sebagai pengganti.
 */
class ApprovalQueue
{
    private const AMOUNT_KEYS = ['total', 'total_payable', 'net_payable', 'grand_total', 'value', 'total_budget', 'total_net', 'amount', 'total_amount'];

    private const TITLE_KEYS = ['title', 'name', 'description', 'purpose', 'reason', 'notes', 'subject'];

    private const OWNER_KEYS = ['requested_by', 'created_by', 'submitted_by', 'requester_id', 'user_id'];

    /**
     * $forUser = null → seluruh antrean (untuk pengawasan); selain itu hanya
     * yang boleh disetujui pengguna itu dan bukan miliknya sendiri.
     *
     * @return array{rows: list<array<string, mixed>>, failed: list<string>}
     */
    public static function pending(?User $forUser = null, ?Carbon $now = null): array
    {
        $now ??= now();
        $rows = [];
        $failed = [];
        $employeeId = $forUser?->employee_id ?? null;

        foreach (ApprovableDocuments::all() as $class => $entry) {
            $permission = "{$entry['prefix']}.approve";
            if ($forUser !== null && ! $forUser->can($permission)) {
                continue;
            }

            try {
                $docs = $class::query()->where('status', 'submitted')->get();
                if ($docs->isEmpty()) {
                    continue;
                }

                $morph = (new $class)->getMorphClass();
                $submissions = DB::table('core_approvals')
                    ->select('approvable_id', 'user_id', 'created_at')
                    ->where('approvable_type', $morph)
                    ->where('action', 'submitted')
                    ->whereIn('approvable_id', $docs->modelKeys())
                    ->orderBy('created_at')
                    ->get()
                    ->keyBy('approvable_id'); // keyBy keeps the LAST row per id = latest submission

                $names = DB::table('users')->whereIn('id', $submissions->pluck('user_id')->unique())->pluck('name', 'id');

                foreach ($docs as $doc) {
                    $attrs = $doc->getAttributes();
                    $sub = $submissions->get($doc->getKey());
                    $ownerId = $sub->user_id ?? null;
                    if ($ownerId === null) {
                        foreach (self::OWNER_KEYS as $key) {
                            if (! empty($attrs[$key])) {
                                $ownerId = (int) $attrs[$key];
                                break;
                            }
                        }
                    }
                    $ownEmployee = $employeeId !== null && ! empty($attrs['employee_id']) && (int) $attrs['employee_id'] === (int) $employeeId;

                    if ($forUser !== null && (($ownerId !== null && $ownerId === (int) $forUser->getKey()) || $ownEmployee)) {
                        continue; // maker-checker: not yours to approve
                    }

                    $amount = null;
                    foreach (self::AMOUNT_KEYS as $key) {
                        if (isset($attrs[$key]) && is_numeric($attrs[$key])) {
                            $amount = (float) $attrs[$key];
                            break;
                        }
                    }
                    $title = null;
                    foreach (self::TITLE_KEYS as $key) {
                        if (! empty($attrs[$key]) && is_string($attrs[$key])) {
                            $title = $attrs[$key];
                            break;
                        }
                    }
                    $submittedAt = $sub->created_at ?? ($attrs['updated_at'] ?? null);

                    $rows[] = [
                        'id' => $doc->getKey(),
                        'code' => $attrs['code'] ?? ('#'.$doc->getKey()),
                        'label' => $entry['label'],
                        'resource' => $entry['resource'],
                        'permission' => $permission,
                        'title' => $title,
                        'amount' => $amount,
                        'submitted_at' => $submittedAt,
                        'submitted_by' => $sub ? ($names[$sub->user_id] ?? null) : null,
                        'submitted_by_id' => $ownerId,
                        'days_waiting' => $submittedAt ? (int) floor(abs(Carbon::parse($submittedAt)->diffInDays($now))) : null,
                        'link' => "#/d/{$entry['resource']}/{$doc->getKey()}",
                    ];
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = $entry['label'];
            }
        }

        // Yang paling lama menunggu di atas: antrean, bukan berita.
        usort($rows, fn ($a, $b) => strcmp((string) $a['submitted_at'], (string) $b['submitted_at']));

        return ['rows' => $rows, 'failed' => $failed];
    }
}
