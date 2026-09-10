<?php

namespace Modules\ServiceDesk\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Services\NotificationService;
use Modules\ServiceDesk\Enums\CsatScore;
use Modules\ServiceDesk\Enums\TicketStatus;
use Modules\ServiceDesk\Models\CsatRating;
use Modules\ServiceDesk\Models\Ticket;

/**
 * CSAT tiket layanan (F-9) — undangan menilai lewat tautan sekali pakai, dan
 * rata-rata yang jujur.
 *
 * BUKAN PORTAL. Roadmap menolak portal pelanggan secara tertulis: tidak ada
 * akun pelanggan, tidak ada kata sandi, tidak ada sesi. Tokennya ADALAH
 * kapabilitasnya, persis seperti halaman persetujuan eksternal — dan bentuk
 * ini dipilih ulang di sini bukan karena kebetulan mirip, melainkan karena
 * pertanyaannya sama: bagaimana memberi tepat satu kemampuan kepada seseorang
 * yang tidak dikenal sistem ini.
 *
 * ENAM ATURAN YANG DITEGAKKAN DI SINI, BUKAN DI CONTROLLER:
 *
 *  1. TOKEN POLOS HIDUP SATU KALI. issue() mengembalikannya sekali di nilai
 *     balik dan menyimpan sha256-nya saja. Tidak ada endpoint membaca ulang,
 *     dan tidak satu pun jalur log menyentuh teks polosnya.
 *
 *  2. HANYA TIKET YANG SUDAH SELESAI BOLEH DINILAI, dan itu diperiksa DUA
 *     KALI: saat tautan terbit, dan lagi saat pelanggan menekan tombol
 *     (RATABLE). Alasannya bukan kerapian — tiket bisa DIBUKA KEMBALI di
 *     antara keduanya (TicketStatus: resolved → in_progress), dan sebuah
 *     penilaian atas pekerjaan yang ternyata belum selesai adalah angka yang
 *     menjawab pertanyaan yang salah. Tautannya tidak dibunuh, hanya
 *     ditidurkan: begitu tiketnya selesai lagi, tautan yang sama hidup lagi
 *     sampai masa berlakunya habis. Menulis "dicabut" ke barisnya akan
 *     menjadikan pembukaan ulang sebuah penulisan, dan pembukaan ulang bukan
 *     urusan tabel ini.
 *
 *  3. SATU PENILAIAN PER TIKET. Beberapa undangan boleh terbit (yang pertama
 *     hilang di WhatsApp, PIC-nya berganti), tetapi hanya SATU yang menjadi
 *     penilaian tiket itu — kalau tidak, "rata-rata per tiket" dan "rata-rata
 *     per jawaban" adalah dua angka berbeda yang sama-sama disebut CSAT.
 *     Ditegakkan dengan baca ulang TERKUNCI di dalam transaksi (idiom TOCTOU
 *     rumah), jadi dua klik serentak dari dua tautan berbeda pada tiket yang
 *     sama tidak pernah mencatat dua kali.
 *
 *  4. YANG DINILAI TIDAK MENERBITKAN TAUTANNYA SENDIRI. Teknisi yang namanya
 *     ada di tiket memegang svc.update (peran `teknisi`), dan penerbit tautan
 *     MELIHAT token polosnya tepat sekali — artinya ia bisa menilai dirinya
 *     sendiri bintang lima tanpa satu pun pelanggan menyentuh apa pun. Ini
 *     maker-checker yang sama dengan ExternalApprovalService::
 *     assertIssuerIsNotMaker, dengan sisi "maker" yang berbeda: di sana
 *     pengaju dokumen, di sini orang yang dinilai.
 *
 *  5. TIDAK ADA SUREL YANG DIKIRIM. MAIL_MAILER=log di pengembangan DAN di
 *     produksi (SMTP adalah paket P-3a yang belum dikerjakan), jadi tidak satu
 *     baris pun di sini mengirim apa pun dan tidak satu kalimat pun di layar
 *     boleh mengatakan "tautan sudah dikirim". Penerbit menyalin URL-nya dan
 *     mengirimnya lewat salurannya sendiri — persis cara persetujuan eksternal
 *     menyerahkan tautannya kepada manusia. recipient_email disimpan sebagai
 *     ARSIP untuk siapa undangan diterbitkan, bukan sebagai alamat kirim.
 *
 *  6. KOMENTAR PELANGGAN ADALAH TEKS BEBAS TENTANG SEORANG TEKNISI YANG
 *     NAMANYA ADA DI TIKET. Ia hidup di gerbang yang sama dengan tiketnya
 *     (svc.view) dan tidak pernah lebih longgar. Karena itu lonceng yang
 *     diterbitkan di bawah membawa SKOR dan kode tiketnya saja: badan
 *     notifikasi dibaca pemegang svc.update — himpunan yang tidak dijamin sama
 *     dengan pemegang svc.view oleh apa pun selain kebetulan seeding peran —
 *     jadi komentarnya tinggal di tiketnya, di mana gerbangnya diperiksa.
 */
class CsatService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Masa berlaku bawaan undangan CSAT.
     *
     * 14 hari, bukan 7 seperti persetujuan eksternal: MK yang ditunggu tanda
     * tangannya membuka tautannya hari itu juga karena pekerjaan berhenti
     * menunggunya, sedangkan pelanggan yang diminta menilai tidak menunggu apa
     * pun — undangan yang mati di hari ketujuh adalah undangan yang hilang
     * bersama cuti seminggu satu orang. Angkanya dipaku LITERAL di ujinya,
     * bukan dibaca dari konstanta ini (pelajaran F-6).
     */
    public const DEFAULT_VALIDITY_DAYS = 14;

    /**
     * Status tiket yang boleh dinilai — dan satu-satunya daftarnya.
     *
     * `resolved` dan `closed`, bukan `cancelled`: tiket yang dibatalkan tidak
     * pernah dikerjakan, jadi tidak ada apa pun untuk dinilai dan sebuah
     * bintang satu atasnya akan mengukur pembatalan, bukan layanan.
     *
     * @var list<string>
     */
    public const RATABLE = ['resolved', 'closed'];

    /** Panjang komentar pelanggan yang diterima; sisanya dipotong di tepi. */
    public const MAX_COMMENT = 1000;

    // ---------------------------------------------------------------- issue

    /**
     * @return array{rating: CsatRating, token: string, url: string}
     */
    public function issue(User $by, Ticket $ticket, array $data): array
    {
        $this->assertTicketIsRatable($ticket);
        $this->assertTicketIsNotRatedYet($ticket);
        $this->assertIssuerIsNotTheRatedTechnician($ticket, $by);

        $token = Str::random(40);

        $rating = CsatRating::query()->create([
            'ticket_id' => $ticket->getKey(),
            'recipient_name' => $data['recipient_name'],
            'recipient_email' => $data['recipient_email'] ?? null,
            'token_hash' => hash('sha256', $token),
            'expires_at' => isset($data['expires_at'])
                ? Carbon::parse($data['expires_at'])
                : now()->addDays(self::DEFAULT_VALIDITY_DAYS),
            'issued_by' => $by->id,
        ]);

        return [
            'rating' => $rating,
            'token' => $token,
            'url' => url('penilaian/'.$token),
        ];
    }

    public function revoke(CsatRating $rating, User $by): CsatRating
    {
        return DB::transaction(function () use ($rating, $by): CsatRating {
            /** @var CsatRating $locked */
            $locked = CsatRating::query()->whereKey($rating->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isRated()) {
                throw ValidationException::withMessages(['revoke' => sprintf(
                    'Tautan ini sudah dipakai menilai (%s, %s) — penilaian adalah bukti dan tidak dapat dicabut.',
                    $locked->score?->label(),
                    $locked->rated_at?->format('d-m-Y H:i'),
                )]);
            }

            if ($locked->isRevoked()) {
                throw ValidationException::withMessages(['revoke' => sprintf(
                    'Tautan sudah dicabut pada %s.',
                    $locked->revoked_at?->format('d-m-Y H:i'),
                )]);
            }

            $locked->forceFill(['revoked_at' => now(), 'revoked_by' => $by->id])->save();

            return $locked;
        });
    }

    // ----------------------------------------------------------- public path

    public function findByToken(string $token): ?CsatRating
    {
        return CsatRating::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Jalan masuk halaman publik: token → penilaian, sekali saja.
     *
     * Semua penjaga dibaca ulang DI DALAM transaksi dengan baris terkunci.
     * Dua klik pada tautan yang sama — atau pada dua tautan berbeda milik satu
     * tiket — berarti dua transaksi; yang kalah membaca keadaan yang sudah
     * berubah dan ditolak di sini, bukan oleh salinan usang yang kebetulan
     * dipegang controller-nya.
     */
    public function rate(string $token, int $score, ?string $comment): CsatRating
    {
        $value = CsatScore::from($score);

        return DB::transaction(function () use ($token, $value, $comment): CsatRating {
            /** @var CsatRating|null $row */
            $row = CsatRating::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw (new ModelNotFoundException)->setModel(CsatRating::class);
            }

            if ($row->isRated()) {
                throw new LogicException('Tautan sudah digunakan — penilaian pertama yang berlaku dan tidak ditimpa.');
            }

            if ($row->isRevoked()) {
                throw new LogicException('Tautan sudah dicabut oleh penerbitnya.');
            }

            if ($row->isExpired()) {
                throw new LogicException(sprintf(
                    'Tautan sudah kedaluwarsa sejak %s.',
                    $row->expires_at?->format('d-m-Y H:i'),
                ));
            }

            /** @var Ticket|null $ticket */
            $ticket = Ticket::query()->whereKey($row->ticket_id)->lockForUpdate()->first();

            if ($ticket === null) {
                throw new LogicException('Tiket yang dimintakan penilaian sudah tidak ada di sistem.');
            }

            if (! in_array($this->statusOf($ticket), self::RATABLE, true)) {
                throw new LogicException(
                    'Tiket ini sedang dikerjakan kembali, jadi belum bisa dinilai. '
                    .'Tautan Anda tetap berlaku — cobalah lagi setelah pekerjaannya dinyatakan selesai.'
                );
            }

            // Undangan LAIN pada tiket yang sama sudah menjadi penilaiannya.
            $other = CsatRating::query()
                ->where('ticket_id', $ticket->getKey())
                ->whereKeyNot($row->getKey())
                ->whereNotNull('rated_at')
                ->exists();

            if ($other) {
                throw new LogicException('Tiket ini sudah dinilai lewat tautan lain — satu tiket satu penilaian.');
            }

            $comment = $this->trimComment($comment);

            $row->forceFill([
                'score' => $value,
                'comment' => $comment,
                'rated_at' => now(),
                'rated_via' => CsatRating::VIA_LINK,
            ])->save();

            $this->afterRating($row, $ticket);

            return $row;
        });
    }

    // ------------------------------------------------------------ side effects

    /**
     * Lonceng untuk pemegang svc.update — dan SENGAJA tanpa komentarnya.
     *
     * Badan notifikasi adalah permukaan yang gerbangnya svc.update, bukan
     * svc.view; keduanya kebetulan dipegang peran yang sama hari ini, tetapi
     * "kebetulan dipegang peran yang sama" bukan penegakan. Skornya boleh
     * lewat (ia angka tentang layanan), komentarnya tinggal di tiket, di mana
     * gerbangnya diperiksa setiap kali dibaca.
     */
    private function afterRating(CsatRating $rating, Ticket $ticket): void
    {
        $this->notifications->system(
            'svc.update',
            "Penilaian pelanggan masuk: {$ticket->code}",
            sprintf(
                '%s (%d dari 5) dari %s.%s',
                $rating->score?->label(),
                $rating->score?->value,
                $rating->recipient_name,
                filled($rating->comment) ? ' Komentarnya ada di tiketnya.' : '',
            ),
            "#/d/servicedesk/tickets/{$ticket->getKey()}",
            null,
            'csat:'.$rating->id,
        );
    }

    // ------------------------------------------------------------- summary

    /**
     * RATA-RATA HANYA DARI YANG DINILAI, dan tidak pernah sendirian.
     *
     * Sembilan dari sepuluh tiket yang belum dijawab BUKAN nol bintang, jadi
     * penyebut rata-rata adalah `rated`, bukan `ratable` dan bukan `invited`.
     * Ketiga angka itu dikembalikan bersama-sama justru supaya pemanggil tidak
     * bisa memajang rata-ratanya tanpa jumlah yang menopangnya: "4,6 dari 5"
     * dari dua jawaban dan "4,6 dari 5" dari dua ratus jawaban adalah dua
     * pernyataan yang sangat berbeda.
     *
     * `average` NULL — bukan 0.0 — ketika belum ada satu pun penilaian.
     * "Belum ada penilaian" adalah KALIMAT, dan kalimat itu ditulis layar dari
     * null ini. Sebuah 0,0 di sini akan menjadi 0,0 di layar, di ekspor, dan
     * di setiap tangkapan layar yang dikirim ke pemilik.
     *
     * @param  array{from?: ?string, to?: ?string, service_contract_id?: ?int, customer_id?: ?int}  $filters
     * @return array{ratable: int, invited: int, rated: int, average: float|null, distribution: array<int, int>, satisfied: int, response_rate: float|null, window: array{from: ?string, to: ?string}}
     */
    public function summary(array $filters = []): array
    {
        $tickets = $this->ratableTicketQuery($filters);

        $ratable = (clone $tickets)->count();

        $invited = (clone $tickets)
            ->whereExists(fn ($query) => $query->from('svc_csat_ratings')
                ->whereColumn('svc_csat_ratings.ticket_id', 'svc_tickets.id'))
            ->count();

        $rated = CsatRating::query()
            ->whereNotNull('rated_at')
            ->whereIn('ticket_id', (clone $tickets)->select('svc_tickets.id'))
            ->get(['score']);

        $distribution = [];

        foreach (CsatScore::ascending() as $score) {
            $distribution[$score->value] = 0;
        }

        /*
         * PEMBILANG DAN PENYEBUT DIHITUNG DI LOOP YANG SAMA, dan itu bukan
         * gaya melainkan koreksi: sebuah baris ber-rated_at TANPA skor (impor,
         * sunting tangan — rate() tidak bisa membuatnya) yang dilewati dari
         * penjumlahan tetapi ikut dihitung sebagai penyebut menjadikannya nol
         * bintang lewat pintu belakang. Terukur 10 Sep 2026: satu baris cacat
         * di samping satu skor 5 mencetak 2,5. Yang tidak punya skor bukan
         * penilaian, jadi ia tidak masuk pembilang MAUPUN penyebut.
         */
        $count = 0;
        $sum = 0;
        $satisfied = 0;

        foreach ($rated as $row) {
            $score = $row->score;

            if ($score === null) {
                continue;
            }

            $count++;
            $distribution[$score->value]++;
            $sum += $score->value;
            $satisfied += $score->isSatisfied() ? 1 : 0;
        }

        return [
            'ratable' => $ratable,
            'invited' => $invited,
            'rated' => $count,
            'average' => $count === 0 ? null : round($sum / $count, 2),
            'distribution' => $distribution,
            'satisfied' => $satisfied,
            'response_rate' => $invited === 0 ? null : round($count / $invited, 4),
            'window' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
        ];
    }

    /**
     * Penilaian yang SUDAH masuk, terbaru dulu — untuk layar ringkasan.
     *
     * Membawa komentarnya, jadi pemanggilnya WAJIB sudah memeriksa svc.view.
     * Tidak ada varian "tanpa komentar" di sini: dua bentuk jawaban untuk satu
     * pertanyaan adalah dua tempat yang bisa berselisih tentang siapa boleh
     * membaca apa.
     *
     * @return Builder<CsatRating>
     */
    public function ratedQuery(array $filters = [])
    {
        return CsatRating::query()
            ->whereNotNull('rated_at')
            ->whereIn('ticket_id', $this->ratableTicketQuery($filters)->select('svc_tickets.id'))
            ->with('ticket:id,code,title,customer_id,service_contract_id,assigned_to,status')
            ->orderByDesc('rated_at')
            ->orderByDesc('id');
    }

    /** Penilaian tiket ini, bila sudah ada. */
    public function ratingFor(Ticket $ticket): ?CsatRating
    {
        return CsatRating::query()
            ->where('ticket_id', $ticket->getKey())
            ->whereNotNull('rated_at')
            ->first();
    }

    // ---------------------------------------------------------------- guards

    /**
     * Tiket yang MASUK HITUNGAN: sudah selesai — ATAU sudah pernah dinilai,
     * apa pun statusnya hari ini. Yang dibuang tetap keluar (softDeletes).
     *
     * Ini penyebut "ratable" dan juga saringan setiap angka lain, supaya tidak
     * ada dua definisi "tiket yang layak dinilai" di dalam satu berkas — dan
     * SATU definisi itulah yang menjaga penyebutnya tidak pernah lebih kecil
     * daripada pembilangnya (dua kueri yang berbeda bisa mencetak tingkat
     * jawaban di atas 100 %).
     *
     * KENAPA "atau sudah pernah dinilai": sebuah penilaian adalah bukti atas
     * pekerjaan yang saat itu DINYATAKAN SELESAI, dan membuka tiketnya kembali
     * tidak membatalkan bukti itu. Yang paling sering membuat tiket dibuka
     * kembali justru pekerjaan yang dinilai buruk — jadi menyaring baris
     * ternilai dengan status HARI INI membuat rata-ratanya NAIK tepat ketika
     * sebuah pekerjaan harus diulang, penyebutnya menyusut diam-diam, dan
     * komentar keluhannya lenyap dari kartunya sementara kartu CSAT di tiketnya
     * masih menampilkannya (terukur 10 Sep 2026: 4,2 → 4,3, "13 dari 49" →
     * "12 dari 48"). Tiket yang dibuka kembali dan BELUM dinilai tetap keluar:
     * ia belum menjadi bukti apa pun.
     *
     * Jendela waktu tetap diukur pada `resolved_at`, jadi tiket yang sedang
     * dikerjakan kembali (resolved_at kosong) tidak muncul di ringkasan
     * BERJENDELA sampai pekerjaannya dinyatakan selesai lagi — layar #/csat
     * hari ini tidak berjendela dan menampilkan seluruh riwayat.
     *
     * @return Builder<Ticket>
     */
    private function ratableTicketQuery(array $filters = [])
    {
        $query = Ticket::query()->where(function ($query): void {
            $query->whereIn('status', self::RATABLE)
                ->orWhereExists(fn ($sub) => $sub->from('svc_csat_ratings')
                    ->whereColumn('svc_csat_ratings.ticket_id', 'svc_tickets.id')
                    ->whereNotNull('svc_csat_ratings.rated_at'));
        });

        // Jendela waktu diukur pada SELESAINYA pekerjaan, bukan pada
        // dilaporkannya: CSAT bulan Agustus adalah kepuasan atas pekerjaan
        // yang selesai di Agustus, betapa pun lamanya tiket itu terbuka.
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('resolved_at', '>=', $filters['from']);
        }

        if (filled($filters['to'] ?? null)) {
            $query->whereDate('resolved_at', '<=', $filters['to']);
        }

        if (filled($filters['service_contract_id'] ?? null)) {
            $query->where('service_contract_id', (int) $filters['service_contract_id']);
        }

        if (filled($filters['customer_id'] ?? null)) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        return $query;
    }

    private function assertTicketIsRatable(Ticket $ticket): void
    {
        if (in_array($this->statusOf($ticket), self::RATABLE, true)) {
            return;
        }

        throw ValidationException::withMessages(['ticket_id' => sprintf(
            'Tautan penilaian hanya dapat diterbitkan untuk tiket yang sudah selesai (%s) — tiket %s saat ini %s.',
            implode('/', self::RATABLE),
            $ticket->code,
            $this->statusOf($ticket),
        )]);
    }

    private function assertTicketIsNotRatedYet(Ticket $ticket): void
    {
        $rating = $this->ratingFor($ticket);

        if ($rating === null) {
            return;
        }

        throw ValidationException::withMessages(['ticket_id' => sprintf(
            'Tiket %s sudah dinilai pada %s (%s) — satu tiket satu penilaian, jadi tidak ada tautan baru yang bisa diterbitkan untuknya.',
            $ticket->code,
            $rating->rated_at?->format('d-m-Y H:i'),
            $rating->score?->label(),
        )]);
    }

    /**
     * Maker-checker CSAT: yang dinilai tidak memegang undangannya.
     *
     * Penerbit melihat token polosnya tepat sekali, jadi penerbit BISA
     * membukanya sendiri. Bila penerbit itu teknisi yang namanya ada di tiket,
     * bintang lima yang tercatat mengukur dirinya sendiri. Ditolak di meja yang
     * benar — saat terbit — dengan kalimat yang menyebut jalan keluarnya.
     */
    private function assertIssuerIsNotTheRatedTechnician(Ticket $ticket, User $by): void
    {
        $employeeId = $ticket->assigned_to === null ? null : (int) $ticket->assigned_to;

        if ($employeeId === null || $by->employee_id === null) {
            return;
        }

        if ((int) $by->employee_id !== $employeeId) {
            return;
        }

        throw ValidationException::withMessages(['ticket_id' => 'Teknisi yang mengerjakan tiket ini tidak boleh menerbitkan tautan penilaian untuknya — '
            .'penerbit melihat tautannya sekali dan bisa membukanya sendiri, jadi penilaiannya akan mengukur dirinya sendiri. '
            .'Minta rekan atau atasan Anda yang menerbitkannya.',
        ]);
    }

    private function statusOf(Ticket $ticket): string
    {
        return $ticket->status instanceof TicketStatus ? $ticket->status->value : (string) $ticket->status;
    }

    private function trimComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : mb_substr($comment, 0, self::MAX_COMMENT);
    }
}
