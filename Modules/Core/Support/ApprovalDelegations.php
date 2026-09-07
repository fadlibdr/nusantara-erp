<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Exceptions\SelfApprovalException;

/**
 * Delegasi "a.n." — dan tiga hal yang TIDAK boleh diberikannya (F-1).
 *
 * Satu kelas menjawab empat pertanyaan, supaya tidak ada dua yang bisa
 * berbeda pendapat:
 *
 *   grants()          Gate::before — ability ini boleh dipakai orang ini?
 *   actingForId()     baris persetujuan ini "a.n." siapa?
 *   giverIdsFor()     maker-checker — siapa saja yang pengajuannya haram
 *                     bagi orang ini?
 *   activeFor()       banner "Anda menyetujui a.n. …"
 *
 * SATU-SATUNYA ABILITY YANG PERNAH DIBERIKAN adalah <awalan>.approve dan
 * <awalan>.approve-director. Polanya diperiksa SEBELUM satu baris pun dibaca,
 * jadi sebuah delegasi tidak pernah menjadi jalan memutar untuk fin.post,
 * iam.update atau apa pun. Ini bukan kehati-hatian berlebih: Gate::before
 * berjalan mendahului SETIAP policy dan setiap middleware permission di
 * aplikasi ini, jadi sebuah kebocoran di sini adalah kebocoran di mana-mana.
 *
 * TIDAK BERANTAI. Pemberinya harus memegang izinnya SENDIRI — hasPermissionTo(),
 * bukan can(). Dua alasan, dan keduanya cukup sendirian: can() akan masuk lagi
 * ke Gate::before dan sebuah siklus delegasi (A→B, B→A) akan menggantung
 * proses; dan sebuah rantai tiga orang akan menyerahkan hak direktur kepada
 * orang yang tidak pernah dipilih siapa pun untuk memegangnya.
 *
 * DELEGAT TIDAK MENYETUJUI PEKERJAAN PEMBERINYA. Dipasang di dalam
 * SegregationOfDuties::assertNotSubmitter, tempat maker-checker sudah berdiri,
 * karena aturannya adalah maker-checker yang dilihat lewat delegasi: seorang
 * proxy yang menyetujui dokumen yang diajukan orang yang diwakilinya adalah
 * persetujuan-sendiri yang memakai topi. Aturan ini SENGAJA lebih keras dari
 * yang perlu: ia berlaku bahkan bila delegatnya memegang hak approve itu
 * sendiri dan tidak membutuhkan delegasinya — karena menentukan "hak yang
 * mana yang dipakainya tadi" tidak dapat dilakukan sesudah kejadian, dan
 * jawaban yang ditebak pada pertanyaan itu adalah jawaban yang salah.
 */
final class ApprovalDelegations
{
    /**
     * Satu-satunya bentuk ability yang boleh datang dari delegasi.
     *
     * Diikat pada awalan yang benar-benar ada di registri (bukan [a-z]+),
     * jadi sebuah izin bernama "x.approve" yang tidak dimiliki modul mana pun
     * tidak pernah cocok.
     */
    private const ABILITY = '/^(?<prefix>[a-z][a-z0-9]{1,9})\.approve(-director)?$/';

    /**
     * Gate::before. true = diberikan lewat delegasi; null = tidak berpendapat
     * (WAJIB null, bukan false: false akan MENOLAK setiap ability lain di
     * aplikasi ini, termasuk yang benar-benar dipegang pemakainya).
     */
    public static function grants(User $user, string $ability): ?bool
    {
        $prefix = self::prefixOf($ability);

        if ($prefix === null) {
            return null;
        }

        foreach (self::activeFor($user) as $delegation) {
            if ($delegation['scope'] !== null && $delegation['scope'] !== $prefix) {
                continue;
            }

            if (self::giverHoldsNatively((int) $delegation['giver_user_id'], $ability)) {
                return true;
            }
        }

        return null;
    }

    /**
     * Pemberi yang haknya dipakai baris persetujuan ini, atau null.
     *
     * "a.n." dicap HANYA ketika delegasinya yang membuat persetujuan itu
     * mungkin: seseorang yang memegang izin approve-nya sendiri menyetujui
     * atas namanya sendiri, punya delegasi atau tidak. Mencap "a.n." pada
     * persetujuan yang tidak membutuhkannya akan menuliskan sebuah fiksi ke
     * dalam jejak — dan jejak adalah satu-satunya hal yang dimiliki paket ini.
     */
    public static function actingForId(User $approver, ?string $ability): ?int
    {
        if ($ability === null || self::prefixOf($ability) === null) {
            return null;
        }

        if (self::holdsNatively($approver, $ability)) {
            return null;
        }

        $prefix = self::prefixOf($ability);

        foreach (self::activeFor($approver) as $delegation) {
            if ($delegation['scope'] !== null && $delegation['scope'] !== $prefix) {
                continue;
            }

            if (self::giverHoldsNatively((int) $delegation['giver_user_id'], $ability)) {
                return (int) $delegation['giver_user_id'];
            }
        }

        return null;
    }

    /**
     * Setiap pemberi yang delegasinya kepada orang ini sedang hidup.
     *
     * @return list<int>
     */
    public static function giverIdsFor(User $delegate): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['giver_user_id'],
            self::activeFor($delegate),
        )));
    }

    /**
     * Menolak persetujuan oleh delegat atas dokumen yang diajukan PEMBERI
     * delegasinya.
     *
     * @throws SelfApprovalException
     */
    public static function assertNotGiverSubmission(Model $document, User $approver, ?int $makerId): void
    {
        if ($makerId === null) {
            return;
        }

        if (! in_array($makerId, self::giverIdsFor($approver), true)) {
            return;
        }

        $label = ApprovableDocuments::label($document);
        $code = (string) ($document->code ?? $document->getKey());
        $giver = User::query()->find($makerId)?->name ?? "pengguna #{$makerId}";

        throw new SelfApprovalException(
            "{$label} {$code} diajukan oleh {$giver}, dan Anda memegang delegasi persetujuan dari "
            ."{$giver}. Menyetujui atas nama pengaju berarti dokumen ini disetujui oleh haknya sendiri "
            .'— minta persetujuan pengguna lain yang berwenang.'
        );
    }

    /**
     * Delegasi hidup yang DIPEGANG orang ini (ia penerimanya), hari ini.
     *
     * @return list<array<string, mixed>>
     */
    public static function activeFor(User $delegate): array
    {
        $id = (int) $delegate->getKey();
        $memo = app(ApprovalDelegationMemo::class);

        if ($memo->has($id)) {
            return $memo->get($id);
        }

        if (! Schema::hasTable('core_approval_delegations')) {
            $memo->put($id, []);

            return [];
        }

        $today = Carbon::today()->toDateString();

        $rows = DB::table('core_approval_delegations')
            ->where('delegate_user_id', $id)
            ->whereNull('revoked_at')
            ->whereDate('starts_at', '<=', $today)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today))
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        $memo->put($id, $rows);

        return $rows;
    }

    /**
     * Membuang potret unit kerja ini — dipanggil setiap kali sebuah delegasi
     * ditulis atau dicabut, supaya permintaan yang SAMA melihat akibatnya.
     * Batas antar unit kerja dijaga oleh binding scoped()-nya, bukan oleh ini.
     */
    public static function flushMemo(): void
    {
        app(ApprovalDelegationMemo::class)->flush();
    }

    /** "prc" dari "prc.approve-director"; null bila ability bukan hak persetujuan. */
    private static function prefixOf(string $ability): ?string
    {
        if (! preg_match(self::ABILITY, $ability, $matches)) {
            return null;
        }

        $prefix = $matches['prefix'];

        return in_array($prefix, self::approvablePrefixes(), true) ? $prefix : null;
    }

    /** @return list<string> */
    private static function approvablePrefixes(): array
    {
        static $prefixes = null;

        return $prefixes ??= array_values(array_unique(array_column(ApprovableDocuments::all(), 'prefix')));
    }

    private static function giverHoldsNatively(int $giverId, string $ability): bool
    {
        $memo = app(ApprovalDelegationMemo::class);

        if ($memo->hasGiverAnswer($giverId, $ability)) {
            return $memo->giverAnswer($giverId, $ability);
        }

        $giver = User::query()->find($giverId);
        $holds = $giver !== null && (bool) $giver->is_active && self::holdsNatively($giver, $ability);

        $memo->rememberGiver($giverId, $ability, $holds);

        return $holds;
    }

    /**
     * Izin yang dipegang lewat peran/izin langsung — TIDAK lewat Gate, jadi
     * tidak lewat Gate::before, jadi tidak lewat delegasi.
     *
     * Publik sejak putaran tinjauan F-1: penjaga "mengubah approvals.* butuh
     * *.approve-director" HARUS memakai ini dan bukan can(). Sebuah delegasi
     * meminjamkan hak MENYETUJUI DOKUMEN; ia tidak boleh menjadi hak menulis
     * ulang apa arti menyetujui. Dengan can(), Budi yang memegang delegasi
     * Sari plus core.update bisa menurunkan ambang PO — sebuah kendali uang
     * yang berpindah tangan sebagai efek samping cuti.
     */
    public static function holdsNatively(User $user, string $ability): bool
    {
        try {
            return $user->hasPermissionTo($ability, 'web');
        } catch (\Throwable) {
            // Izin yang belum diseed (instalasi setengah jadi, tes yang
            // menyebut nama izin yang tidak ada): tidak dipegang siapa pun.
            return false;
        }
    }
}
