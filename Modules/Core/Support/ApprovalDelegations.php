<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Container\Container;
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
 *   refusesGiverSubmission()
 *                     maker-checker — persetujuan ini meminjam hak si
 *                     pengaju sendiri?
 *   activeFor()       banner "Anda menyetujui a.n. …"
 *
 * SATU-SATUNYA ABILITY YANG PERNAH DIBERIKAN adalah <awalan>.approve dan
 * <awalan>.approve-director. Polanya diperiksa SEBELUM satu baris pun dibaca,
 * jadi sebuah delegasi tidak pernah menjadi jalan memutar untuk fin.post,
 * iam.update atau apa pun. Ini bukan kehati-hatian berlebih: Gate::before
 * berjalan mendahului SETIAP policy dan setiap middleware permission di
 * aplikasi ini, jadi sebuah kebocoran di sini adalah kebocoran di mana-mana.
 *
 * DAN HANYA DI PINTU KEPUTUSAN DOKUMEN. Menyaring nama ability ternyata belum
 * cukup: <awalan>.approve sendiri menggerbangi 15 rute yang bukan keputusan
 * atas dokumen — memposting jurnal manual, membuka kembali periode fiskal,
 * advance payout dan retention release SPK, menutup proyek. Lihat
 * honouredOnThisRequest() untuk daftar terukurnya dan untuk buktinya.
 *
 * TIDAK BERANTAI. Pemberinya harus memegang izinnya SENDIRI — hasPermissionTo(),
 * bukan can(). Dua alasan, dan keduanya cukup sendirian: can() akan masuk lagi
 * ke Gate::before dan sebuah siklus delegasi (A→B, B→A) akan menggantung
 * proses; dan sebuah rantai tiga orang akan menyerahkan hak direktur kepada
 * orang yang tidak pernah dipilih siapa pun untuk memegangnya.
 *
 * DELEGAT TIDAK MENYETUJUI PEKERJAAN PEMBERINYA DENGAN HAK PEMBERI ITU.
 * Dipasang di dalam SegregationOfDuties::assertNotSubmitter, tempat
 * maker-checker sudah berdiri, karena aturannya adalah maker-checker yang
 * dilihat lewat delegasi: seorang proxy yang menyetujui dokumen yang diajukan
 * orang yang diwakilinya adalah persetujuan-sendiri yang memakai topi.
 *
 * Aturan itu dikirim lebih luas dari yang perlu dan disempitkan pada putaran
 * verifikasi F-1: ia dulu berlaku bahkan ketika delegatnya memegang hak
 * approve itu SENDIRI — yang berarti sebuah delegasi mencabut hak orang yang
 * menerimanya, siapa pun boleh membuat baris yang mencabutnya, dan yang
 * dicabut tidak dapat mengembalikannya. Lihat refusesGiverSubmission untuk
 * ketiga syaratnya dan untuk angka yang mengukur harganya.
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
     * Bentuk rute yang MENGHORMATI delegasi: keputusan atas SATU dokumen.
     *
     * Diturunkan dari bentuk URI, bukan dari daftar: rute ke-16 yang menuntut
     * fin.approve besok tertutup secara bawaan, bukan terbuka.
     */
    private const DECISION_ROUTE = '#/\{[^}]+\}/(approve|reject)$#';

    /**
     * DELEGASI HANYA BERLAKU DI PINTU KEPUTUSAN DOKUMEN.
     *
     * Ini perbaikan putaran verifikasi F-1, dan ia menutup jarak antara apa
     * yang dijanjikan tiga docblock dan PANDUAN-ADMINISTRATOR ("delegasi hanya
     * memberikan <awalan>.approve … tidak pernah membuat, mengubah, menghapus
     * atau memposting apa pun") dan apa yang benar-benar dibuka izin itu.
     * Diukur dari tabel rute 7 Sep 2026: <awalan>.approve menggerbangi 71 rute,
     * dan 15 di antaranya BUKAN keputusan atas dokumen —
     *
     *   fin.approve: memposting jurnal manual, MEMBUKA KEMBALI periode fiskal,
     *       menerbitkan nomor e-Bupot, advance payout dan retention release SPK
     *       (dua yang terakhir mencetak tagihan AP yang SUDAH disetujui, sampai
     *       Rp 104.000.000, yang dibayar PaymentService tanpa persetujuan lagi);
     *   prj.approve: menutup proyek, verify/waive/reopen defect, close/reopen
     *       insiden K3;
     *   crm.approve: mengaktifkan kontrak;
     *   eng.approve: dua keputusan submittal;
     *   qc.approve: verifikasi NCR.
     *
     * Dibuktikan ujung ke ujung: login dengan fin.view+fin.post menutup periode
     * 2026-06, ditolak 403 saat membukanya kembali, menerima delegasi cuti
     * biasa dari pemegang fin.approve, lalu membukanya — 403 menjadi 200.
     * Komentar rutenya sendiri menyatakan aturan yang dikalahkannya: "batasnya
     * HARUS lebih tinggi daripada yang membukanya … siapa pun yang bisa
     * memposting tidak boleh bisa membuka sendiri periode yang ingin diisinya".
     *
     * TIDAK ADA RUTE = BOLEH. Konsol, antrean dan pemanggilan langsung tidak
     * punya rute untuk diperiksa, dan seluruh permukaan serangan yang diukur di
     * atas adalah rute HTTP. Yang dijaga adalah permintaan web.
     */
    public static function honouredOnThisRequest(): bool
    {
        $route = Container::getInstance()->bound('request')
            ? Container::getInstance()->make('request')->route()
            : null;

        if ($route === null) {
            return true;
        }

        return preg_match(self::DECISION_ROUTE, '/'.ltrim((string) $route->uri(), '/')) === 1;
    }

    /**
     * Ability ini boleh dipakai orang ini lewat delegasi? true atau null,
     * TIDAK PERNAH false (false akan MENOLAK ability itu di seluruh aplikasi,
     * mendahului setiap policy dan setiap middleware permission).
     *
     * Ini jawaban MENTAHNYA, tanpa memandang rute. Gate::before membungkusnya
     * dengan honouredOnThisRequest(); ApprovalQueue memanggilnya langsung,
     * karena antrean adalah BACAAN — layar "Tugas Saya" harus menampilkan apa
     * yang boleh diputuskan orang ini nanti, dan ia bukan pintu keputusan.
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
     * KEMAMPUAN YANG SEDANG DIPINJAM ORANG INI, dan dari siapa.
     *
     * Ini jawaban yang sama dengan grants(), dibalik: bukan "boleh tidak
     * ability X?" melainkan "X apa saja yang terbuka karena sebuah delegasi?".
     * Ia ada karena sesi peramban tidak dapat menanyakan yang pertama —
     * GET /api/iam/auth/me mengirim getAllPermissions() milik Spatie, yang
     * TIDAK melewati Gate::before, jadi sebuah hak pinjaman tidak pernah sampai
     * ke layar dan seorang delegat murni tidak melihat satu pun tombol Setujui
     * (diukur di Chromium 7 Sep 2026: finance@nusantara.test dengan delegasi
     * penuh melihat ['Cetak'] pada dokumen yang admin lihat sebagai
     * ['Cetak','Setujui','Tolak']).
     *
     * DIKIRIM TERPISAH DARI `permissions`, TIDAK DILEBURKAN KE DALAMNYA. Daftar
     * itu menjawab "apa yang DIPEGANG orang ini", dan layar Matriks Persetujuan
     * bergantung pada jawaban itu tetap benar: menyunting ambang menuntut
     * <awalan>.approve-director yang DIPEGANG SENDIRI (holdsNatively), karena
     * sebuah delegasi meminjamkan hak menyetujui dokumen dan bukan hak menulis
     * ulang apa arti menyetujui.
     *
     * YANG SUDAH DIPEGANG SENDIRI TIDAK DIHITUNG PINJAMAN — aturan yang sama
     * persis dengan actingForId(), supaya tombol dan jejak tidak berbeda
     * pendapat tentang kapan sebuah persetujuan berbunyi "a.n.".
     *
     * TANPA MEMANDANG RUTE, seperti grants(): honouredOnThisRequest() adalah
     * penjaga di pintu, dan pemakainya (layar) harus menerapkan bentuk yang
     * sama — hanya pintu keputusan dokumen.
     *
     * @return array<string, list<string>> ability => nama pemberi, urut ability
     */
    public static function lentAbilitiesFor(User $delegate): array
    {
        $lent = [];

        foreach (self::activeFor($delegate) as $delegation) {
            $scope = $delegation['scope'] === null ? null : (string) $delegation['scope'];
            $prefixes = $scope === null ? self::approvablePrefixes() : [$scope];
            $giverId = (int) $delegation['giver_user_id'];

            foreach ($prefixes as $prefix) {
                foreach (["{$prefix}.approve", "{$prefix}.approve-director"] as $ability) {
                    // Lingkup yang menyebut awalan yang tidak dimiliki modul
                    // mana pun tidak meminjamkan apa pun — pemeriksaan yang
                    // sama dengan grants().
                    if (self::prefixOf($ability) === null) {
                        continue;
                    }

                    if (self::holdsNatively($delegate, $ability)) {
                        continue;
                    }

                    if (! self::giverHoldsNatively($giverId, $ability)) {
                        continue;
                    }

                    $name = self::giver($giverId)?->name ?? "pengguna #{$giverId}";

                    if (! in_array($name, $lent[$ability] ?? [], true)) {
                        $lent[$ability][] = $name;
                    }
                }
            }
        }

        ksort($lent);

        return $lent;
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
     * BENAR bila persetujuan ini akan MEMINJAM hak si pengaju sendiri.
     *
     * Satu predikat untuk dua pembaca — penolakan di bawah dan antrean
     * (ApprovalQueue::pending) — supaya kotak masuk tidak pernah menawarkan
     * baris yang dijamin ditolak, dan tidak pernah menyembunyikan baris yang
     * sebenarnya boleh.
     *
     * TIGA SYARAT, DAN KETIGANYA HARUS BENAR:
     *   1. pengajunya adalah pemberi delegasi yang sedang dipegang penyetuju,
     *      dalam lingkup yang mencakup awalan dokumen ini;
     *   2. pemberinya BENAR-BENAR memegang hak itu (kalau tidak, delegasinya
     *      tidak meminjamkan apa pun dan tidak boleh melarang apa pun);
     *   3. penyetujunya TIDAK memegang hak itu sendiri.
     *
     * SYARAT KETIGA ADALAH PERBAIKAN PUTARAN VERIFIKASI F-1, dan ia mencabut
     * sebuah kalimat yang dulu berdiri di sini: "aturan ini SENGAJA lebih
     * keras dari yang perlu — ia berlaku bahkan bila delegatnya memegang hak
     * approve itu sendiri, karena menentukan hak yang mana yang dipakainya
     * tidak dapat dilakukan sesudah kejadian". Yang kedua tidak benar:
     * actingForId() menjawab pertanyaan itu, deterministik, dan sudah
     * dipakai untuk mencap "a.n." pada jejak. Yang PERTAMA punya harga yang
     * terukur:
     *
     *   - pemakaian paling biasa dari fitur ini — Administrator Sistem
     *     menyerahkan haknya kepada direktur sebelum cuti — membuat 2 dari 4
     *     baris antrean direktur itu tidak dapat disetujui, hak yang
     *     dipegangnya sendiri sebelum delegasinya ada (diukur pada salinan
     *     dataset demo, 7 Sep 2026: 13 dari 14 pengajuan tercatat milik
     *     Administrator Sistem);
     *   - dan siapa pun boleh membuat baris yang menyebut DIRINYA sebagai
     *     pemberi, jadi pengguna tanpa satu izin pun bisa MERACUNI seorang
     *     direktur: POST /api/core/approval-delegations → 201, lalu setiap
     *     BOQ yang diajukan peracun itu ditolak 422 di tangan direktur yang
     *     memegang est.approve sendiri — dan direktur itu tidak dapat
     *     mencabutnya (dua direktur diracuni dalam satu uji).
     *
     * Yang tersisa sesudah penyempitan adalah aturan aslinya, utuh: seorang
     * proxy tidak menyetujui pekerjaan orang yang diwakilinya DENGAN HAK ORANG
     * ITU. Delegasi dari orang tanpa izin tidak melarang apa pun, karena ia
     * juga tidak memberi apa pun.
     */
    public static function refusesGiverSubmission(Model $document, User $approver, ?int $makerId): bool
    {
        if ($makerId === null || $makerId === (int) $approver->getKey()) {
            return false;
        }

        if (self::activeFor($approver) === []) {
            return false;
        }

        $prefix = ApprovableDocuments::all()[$document::class]['prefix'] ?? null;

        if ($prefix === null) {
            return false;
        }

        return self::refusesBorrowedApproval(
            $approver,
            $makerId,
            $prefix,
            self::documentMayNeedADirector($document, ApprovalPolicy::stampedFor($document)),
        );
    }

    /**
     * Bentuk yang sama, dijawab dari fakta yang sudah di tangan pemanggilnya.
     *
     * ApprovalQueue memanggil ini: ia sudah mengambil baris pengajuan dan
     * kolom `policy` untuk seluruh dokumen satu jenis dalam SATU kueri, jadi
     * ia tidak boleh membayar satu kueri stempel per baris hanya untuk
     * menjawab pertanyaan yang sama. Satu badan, dua pintu — antrean dan
     * penolakan tidak boleh berbeda pendapat.
     */
    public static function refusesBorrowedApproval(User $approver, ?int $makerId, string $prefix, bool $mayNeedADirector): bool
    {
        if ($makerId === null || $makerId === (int) $approver->getKey()) {
            return false;
        }

        $delegations = self::activeFor($approver);

        if ($delegations === []) {
            return false;
        }

        $abilities = ["{$prefix}.approve"];

        if ($mayNeedADirector) {
            $abilities[] = "{$prefix}.approve-director";
        }

        foreach ($abilities as $ability) {
            if (self::holdsNatively($approver, $ability)) {
                continue; // haknya sendiri: delegasinya tidak ada urusannya
            }

            foreach ($delegations as $delegation) {
                if ($delegation['scope'] !== null && $delegation['scope'] !== $prefix) {
                    continue;
                }

                if ((int) $delegation['giver_user_id'] !== $makerId) {
                    continue;
                }

                if (self::giverHoldsNatively($makerId, $ability)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Apakah persetujuan atas dokumen ini BISA menuntut hak direktur.
     *
     * Dibaca dari stempel kebijakan baris `submitted` (director, atau jenjang
     * di atas satu) atau dari kolom needs_director_approval yang dibawa
     * tabelnya sendiri.
     *
     * Untuk jenjang, pertanyaannya dijawab KONSERVATIF (setiap tingkat
     * dianggap bisa menuntut direktur) alih-alih menghitung tingkat ke berapa
     * persetujuan ini akan mengisi: jawabannya harus sama persis di sini dan
     * di ApprovalQueue, dan antrean tidak boleh membayar satu kueri per baris
     * untuk menghitungnya. Yang dikorbankan hanya satu keadaan yang sangat
     * jarang — tingkat PERTAMA sebuah keputusan pemenang yang diajukan
     * pemberinya, oleh delegat yang memegang prc.approve sendiri — dan ia
     * dikorbankan ke arah yang lebih keras, arah yang sama dengan maker-checker.
     *
     * @param  array<string, mixed>|null  $stamp  stempel kebijakan baris `submitted`
     */
    public static function documentMayNeedADirector(Model $document, ?array $stamp): bool
    {
        return ($stamp['director'] ?? false) === true
            || (int) ($stamp['levels'] ?? 1) > 1
            || ! empty($document->getAttributes()['needs_director_approval']);
    }

    /**
     * Menolak persetujuan oleh delegat atas dokumen yang diajukan PEMBERI
     * delegasinya — bila hak yang dipakainya memang hak pemberi itu.
     *
     * @throws SelfApprovalException
     */
    public static function assertNotGiverSubmission(Model $document, User $approver, ?int $makerId): void
    {
        if ($makerId === null || ! self::refusesGiverSubmission($document, $approver, $makerId)) {
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

        $giver = self::giver($giverId);
        $holds = $giver !== null && (bool) $giver->is_active && self::holdsNatively($giver, $ability);

        $memo->rememberGiver($giverId, $ability, $holds);

        return $holds;
    }

    /**
     * Pemberi #N untuk unit kerja ini — DIMUAT SEKALI, bukan sekali per ability.
     *
     * Sebelum putaran kedua verifikasi F-1 baris ini adalah
     * User::query()->find() di dalam giverHoldsNatively(): setiap (pemberi,
     * ability) yang belum dimemo memulangkan instance BARU, dan Spatie memuat
     * ulang izin serta peran instance itu. ApprovalQueue::pending menanyakan 10
     * awalan modul, jadi seorang delegat membayar tiga kueri itu sepuluh kali.
     *
     * Diukur pada GET /api/core/inbox yang sama (SQLite, 7 Sep 2026):
     * 8 kueri untuk pembaca tanpa delegasi, 46 untuk delegat lingkup penuh
     * sebelum, 26 sesudah. Dijaga
     * ApprovalDelegationCostTest::test_the_giver_is_loaded_once_for_the_whole_inbox_not_once_per_module.
     */
    private static function giver(int $giverId): ?User
    {
        $memo = app(ApprovalDelegationMemo::class);

        if (! $memo->hasGiverUser($giverId)) {
            $memo->rememberGiverUser($giverId, User::query()->find($giverId));
        }

        return $memo->giverUser($giverId);
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
