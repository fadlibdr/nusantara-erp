<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pekerjaan Saya — dokumen yang MILIK pembacanya dan masih di tangan orang lain
 * (submitted) atau kembali ke tangannya (rejected). Cermin ApprovalQueue (ERRATA
 * E-9, UX-F1): registri yang sama (ApprovableDocuments), kueri lewat kelas yang
 * terdaftar, core_approvals dibaca lewat DB::table, dan KEPEMILIKAN dihitung oleh
 * fungsi yang sama (ApprovalQueue::ownerOf / ownedByEmployee) — kotak masuk
 * melewatkan yang dilewatkan di sini, dan sebaliknya; dua salinan aturan itu
 * berarti sebuah dokumen tanpa pemilik, atau milik dua orang.
 *
 * Tidak ada gerbang izin tersendiri: tiap jenis disaring `<awalan>.view` — jenis
 * yang tidak boleh dibaca pembacanya tidak menyumbang baris. Jenis yang gagal
 * dimuat masuk `failed` (kejujuran yang sama dengan kotak masuk: "tidak dapat
 * dimuat" bukan "tidak ada").
 */
class MyWork
{
    /**
     * @return array{rows: list<array<string, mixed>>, failed: list<string>}
     */
    public static function for(User $user, ?Carbon $now = null): array
    {
        $now ??= now();
        $rows = [];
        $failed = [];
        $userId = (int) $user->getKey();
        $employeeId = $user->employee_id ?? null;
        $onBehalf = Schema::hasColumn('core_approvals', 'on_behalf_of_user_id');

        foreach (ApprovableDocuments::all() as $class => $entry) {
            if (! $user->can("{$entry['prefix']}.view")) {
                continue;
            }

            try {
                $docs = $class::query()->whereIn('status', ['submitted', 'rejected'])->get();
                if ($docs->isEmpty()) {
                    continue;
                }

                $morph = (new $class)->getMorphClass();
                $columns = ['approvable_id', 'action', 'user_id', 'note', 'created_at'];
                if ($onBehalf) {
                    $columns[] = 'on_behalf_of_user_id';
                }
                $trail = DB::table('core_approvals')
                    ->select($columns)
                    ->where('approvable_type', $morph)
                    ->whereIn('action', ['submitted', 'rejected'])
                    ->whereIn('approvable_id', $docs->modelKeys())
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();
                // keyBy menyimpan baris TERAKHIR per id = pengajuan / penolakan terbaru.
                $submissions = $trail->where('action', 'submitted')->keyBy('approvable_id');
                $rejections = $trail->where('action', 'rejected')->keyBy('approvable_id');
                $names = DB::table('users')
                    ->whereIn('id', $trail->pluck('user_id')->merge($onBehalf ? $trail->pluck('on_behalf_of_user_id') : collect())->filter()->unique())
                    ->pluck('name', 'id');

                foreach ($docs as $doc) {
                    $attrs = $doc->getAttributes();
                    $sub = $submissions->get($doc->getKey());
                    $ownerId = ApprovalQueue::ownerOf($attrs, $sub);
                    if ($ownerId !== $userId && ! ApprovalQueue::ownedByEmployee($attrs, $employeeId)) {
                        continue;
                    }

                    $status = (string) ($attrs['status'] ?? '');
                    $submittedAt = $sub->created_at ?? ($attrs['updated_at'] ?? null);
                    // Siapa yang mengajukan — sama dengan baris kotak masuk (`submitted_by`, `submitted_by_id`). NULL
                    // bila dokumennya tidak punya baris pengajuan: layar tidak boleh mengarang "oleh Anda" untuk dokumen
                    // yang diajukan orang lain atas nama karyawan ini (mis. cuti yang diketik petugas HR).
                    $submitterId = $sub->user_id ?? null;
                    $row = [
                        'id' => $doc->getKey(),
                        'code' => $attrs['code'] ?? ('#'.$doc->getKey()),
                        'label' => $entry['label'],
                        'resource' => $entry['resource'],
                        'title' => ApprovalQueue::titleOf($attrs),
                        'amount' => ApprovalQueue::amountOf($attrs),
                        'submitted_at' => $submittedAt,
                        'submitted_by' => $submitterId ? ($names[$submitterId] ?? null) : null,
                        'submitted_by_id' => $submitterId ? (int) $submitterId : null,
                        'days_waiting' => $submittedAt ? (int) floor(abs(Carbon::parse($submittedAt)->diffInDays($now))) : null,
                        'link' => "#/d/{$entry['resource']}/{$doc->getKey()}",
                        'status' => $status,
                        'bucket' => $status === 'rejected' ? 'needs_fix' : 'waiting',
                    ];

                    if ($status === 'rejected') {
                        $rej = $rejections->get($doc->getKey());
                        $decidedBy = $rej && $rej->user_id ? ($names[$rej->user_id] ?? null) : null;
                        // "Budi a.n. Sari" — hak yang dipakai pinjaman (F-1); bentuk yang sama dengan garis waktu.
                        if ($decidedBy !== null && $onBehalf && ! empty($rej->on_behalf_of_user_id) && isset($names[$rej->on_behalf_of_user_id])) {
                            $decidedBy .= ' a.n. '.$names[$rej->on_behalf_of_user_id];
                        }
                        $row['decided_at'] = $rej->created_at ?? null;
                        $row['decided_by'] = $decidedBy;
                        $row['note'] = $rej->note ?? null;
                    }

                    $rows[] = $row;
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = $entry['label'];
            }
        }

        // Yang perlu diperbaiki dulu (penolakan tertua di atas), lalu yang menunggu (pengajuan tertua di atas).
        usort($rows, static function (array $a, array $b): int {
            if ($a['bucket'] !== $b['bucket']) {
                return $a['bucket'] === 'needs_fix' ? -1 : 1;
            }
            $key = $a['bucket'] === 'needs_fix' ? 'decided_at' : 'submitted_at';

            return strcmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? ''));
        });

        return ['rows' => $rows, 'failed' => $failed];
    }
}
