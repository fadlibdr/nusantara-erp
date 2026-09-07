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
 * `submitted` di core_approvals dan MELEWATKAN dokumen yang diajukan tanpa
 * aktor — by design, untuk dokumen yang dicetak mesin. Tetapi kotak masuk
 * tidak boleh MENAWARKAN dokumen yang jelas milik pembacanya: PR/2026/III/0002
 * "Diminta oleh admin" tampil sebagai "menunggu persetujuan Anda" untuk admin,
 * dan satu klik menyetujuinya (4 Sep 2026, produksi). Jadi bila jejak
 * pengajuan tidak ada, kolom kepemilikan yang ada di tabel (requested_by,
 * created_by, submitted_by, employee_id → users.employee_id) dipakai sebagai
 * pengganti — dan sejak T3.4 penjaga memakai kolom yang sama untuk MENOLAK,
 * sehingga dokumen yang tidak ditawarkan di sini juga tidak lolos di sana.
 *
 * "MILIK PEMBERI DELEGASI" adalah bentuk kedua dari aturan yang sama, dan ia
 * terlewat sampai putaran verifikasi F-1. Sebuah delegasi membuat dokumen
 * pemberinya TERLIHAT di antrean penerimanya (Gate::before meminjamkan
 * <awalan>.approve), lalu SegregationOfDuties menolaknya saat Setujui ditekan.
 * Diukur pada dataset demo: sesudah Administrator Sistem mendelegasikan ke
 * login finance, 2 dari 4 baris yang ditawarkan dijamin gagal, dan "Setujui
 * massal" menawarkan centang pada keduanya. Predikat yang dipakai di sini
 * adalah predikat yang MENOLAK (ApprovalDelegations::refusesBorrowedApproval),
 * bukan salinannya.
 */
class ApprovalQueue
{
    /**
     * Publik sejak F-1: ApprovalPolicy mencap nilai dokumen pada baris
     * `submitted` dan harus memindai kolom yang SAMA, dalam urutan yang sama.
     * Dua daftar akan berarti kotak masuk menampilkan satu angka dan stempel
     * kebijakan mengukur ambangnya terhadap angka lain.
     */
    public const AMOUNT_KEYS = ['total', 'total_payable', 'net_payable', 'grand_total', 'value', 'total_budget', 'total_net', 'amount', 'total_amount'];

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
        $approvable = self::resourcesWithAnApproveEndpoint();

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
                // `policy` ikut dalam kueri yang SAMA, bukan satu kueri per
                // baris: antrean butuh jawaban "dokumen ini bisa menuntut
                // direktur?" untuk saringan delegasi di bawah, dan itu
                // pertanyaan yang stempelnya sudah jawab.
                $columns = ['approvable_id', 'user_id', 'created_at'];

                if (ApprovalPolicy::approvalsCarryAPolicyColumn()) {
                    $columns[] = 'policy';
                }

                $submissions = DB::table('core_approvals')
                    ->select($columns)
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

                    /*
                     * F-1 (putaran verifikasi) — DAN BUKAN PULA PEKERJAAN
                     * PEMBERI DELEGASI YANG SEDANG DIPAKAI.
                     *
                     * Delegasi membuat baris-baris ini TERLIHAT (Gate::before
                     * meminjamkan <awalan>.approve), lalu penjaga
                     * maker-checker menolaknya saat Setujui ditekan. Terukur
                     * pada dataset demo: setelah Administrator Sistem
                     * mendelegasikan ke login finance, 2 dari 4 baris antrean
                     * delegat itu dijamin gagal — dan "Setujui massal"
                     * menawarkan centang pada keduanya. Predikatnya sama persis
                     * dengan yang menolak, jadi keduanya tidak bisa berbeda
                     * pendapat.
                     */
                    if ($forUser !== null && ApprovalDelegations::refusesBorrowedApproval(
                        $forUser,
                        $ownerId,
                        $entry['prefix'],
                        ApprovalDelegations::documentMayNeedADirector(
                            $doc,
                            ApprovalPolicy::decodeStamp($sub->policy ?? null),
                        ),
                    )) {
                        continue;
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
                        // F-1 — endpoint Setujui MILIK MODULNYA, atau null.
                        // Setujui massal memanggil URL ini satu per satu; baris
                        // yang tidak punya (jenis dokumen yang menyetujui lewat
                        // pintu lain) tidak bisa dipilih, dan layar
                        // mengatakannya alih-alih memanggil 404.
                        'approve_url' => in_array($entry['resource'], $approvable, true)
                            ? "{$entry['resource']}/{$doc->getKey()}/approve"
                            : null,
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

    /**
     * Resource yang BENAR-BENAR punya rute POST <resource>/{id}/approve.
     *
     * Dibaca dari tabel rute, bukan diasumsikan dari pola. Setujui massal
     * memanggil endpoint modulnya sendiri satu per satu — itulah yang membuat
     * maker-checker, ambang direktur, catatan dan pemberitahuan tetap berjalan
     * — jadi sebuah baris yang endpoint-nya tidak ada harus DIKETAHUI di sini,
     * bukan ditemukan sebagai 404 di tengah antrean sepuluh dokumen.
     *
     * @return list<string>
     */
    private static function resourcesWithAnApproveEndpoint(): array
    {
        $resources = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('POST', $route->methods(), true)) {
                continue;
            }

            if (preg_match('#^api/(.+)/\{[^}]+\}/approve$#', $route->uri(), $matches) === 1) {
                $resources[] = $matches[1];
            }
        }

        return array_values(array_unique($resources));
    }
}
