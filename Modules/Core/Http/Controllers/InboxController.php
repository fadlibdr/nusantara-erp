<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\ApiController;
use Modules\Core\Support\ApprovableDocuments;
use Throwable;

/**
 * Kotak masuk persetujuan pemanggil — SEMUA jenis dokumen di
 * ApprovableDocuments yang (a) berstatus submitted, (b) boleh disetujui
 * pemanggil, dan (c) bukan diajukan pemanggil sendiri (maker-checker akan
 * menolaknya, jadi tidak perlu ditawarkan).
 *
 * Diukur 2 Sep 2026: kartu dasbor lama menarik 11 jenis dokumen lewat 11
 * permintaan dan tidak pernah menampilkan 17 jenis lainnya — direktur dengan
 * hr.approve tidak melihat pengajuan cuti yang menunggu. Satu permintaan ini
 * menggantikannya dan berjalan atas registri yang sama dengan notifikasi,
 * jadi jenis dokumen baru ikut otomatis.
 *
 * Satu jenis yang gagal (tabel hilang, kolom berbeda) tidak menggelapkan
 * seluruh kotak: ia dilaporkan di `meta.failed` supaya daftar yang pendek
 * tidak terbaca sebagai daftar yang bersih — prinsip yang sama dengan
 * dasbor.
 */
class InboxController extends ApiController
{
    private const AMOUNT_KEYS = ['total', 'total_payable', 'net_payable', 'grand_total', 'value', 'total_budget', 'total_net', 'amount', 'total_amount'];
    private const TITLE_KEYS = ['title', 'name', 'description', 'purpose', 'reason', 'notes', 'subject'];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = [];
        $failed = [];
        $now = now();

        foreach (ApprovableDocuments::all() as $class => $entry) {
            $permission = "{$entry['prefix']}.approve";
            if (!$user->can($permission)) {
                continue;
            }

            try {
                $docs = $class::query()->where('status', 'submitted')->get();
                if ($docs->isEmpty()) {
                    continue;
                }

                // Siapa mengajukan yang mana — satu kueri per jenis, bukan per dokumen.
                $submissions = DB::table('core_approvals')
                    ->select('approvable_id', 'user_id', 'created_at')
                    ->where('approvable_type', (new $class)->getMorphClass())
                    ->where('action', 'submitted')
                    ->whereIn('approvable_id', $docs->modelKeys())
                    ->orderBy('created_at')
                    ->get()
                    ->keyBy('approvable_id'); // keyBy keeps the LAST row per id = latest submission

                $names = DB::table('users')->whereIn('id', $submissions->pluck('user_id')->unique())->pluck('name', 'id');

                foreach ($docs as $doc) {
                    $sub = $submissions->get($doc->getKey());
                    if ($sub && (int) $sub->user_id === (int) $user->getKey()) {
                        continue; // maker-checker: not yours to approve
                    }
                    $attrs = $doc->getAttributes();
                    $amount = null;
                    foreach (self::AMOUNT_KEYS as $key) {
                        if (isset($attrs[$key]) && is_numeric($attrs[$key])) { $amount = (float) $attrs[$key]; break; }
                    }
                    $title = null;
                    foreach (self::TITLE_KEYS as $key) {
                        if (!empty($attrs[$key]) && is_string($attrs[$key])) { $title = $attrs[$key]; break; }
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
                        'days_waiting' => $submittedAt ? (int) floor(abs(\Illuminate\Support\Carbon::parse($submittedAt)->diffInDays($now))) : null,
                        'link' => "#/d/{$entry['resource']}/{$doc->getKey()}",
                    ];
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = $entry['label'];
            }
        }

        // Yang paling lama menunggu di atas: kotak masuk adalah antrean, bukan berita.
        usort($rows, fn ($a, $b) => strcmp((string) $a['submitted_at'], (string) $b['submitted_at']));

        return $this->ok($rows, null, ['total' => count($rows), 'failed' => $failed, 'as_of' => $now->toDateTimeString()]);
    }
}
