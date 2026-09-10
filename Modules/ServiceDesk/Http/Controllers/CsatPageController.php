<?php

namespace Modules\ServiceDesk\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Modules\Core\Models\Company;
use Modules\ServiceDesk\Enums\CsatScore;
use Modules\ServiceDesk\Models\CsatRating;
use Modules\ServiceDesk\Models\Ticket;
use Modules\ServiceDesk\Services\CsatService;

/**
 * Halaman penilaian PUBLIK (CSAT, F-9) — layar kedua sistem ini yang dibuka
 * tanpa login, sesudah /persetujuan/{token}. Kemampuannya adalah tokennya.
 *
 * APA YANG DIBEDAKAN OLEH TOKEN YANG SALAH — perangkap F, diputuskan dan
 * ditulis di sini karena di sinilah ia ditegakkan:
 *
 *   TANPA memegang token yang sah, hanya ADA SATU jawaban yang bisa dilihat:
 *   404 dengan halaman yang sama persis untuk setiap token yang tidak ada di
 *   tabel. Tidak ada kode tiket, tidak ada nama, tidak ada panjang isi yang
 *   berbeda — dua tebakan berbeda mendapat byte yang sama. Itulah pelajaran
 *   AttachmentController::reachable(): dua jawaban yang berbeda adalah orakel
 *   enumerasi.
 *
 *   DENGAN token yang sah, jawabannya memang berbeda-beda — dan itu DISENGAJA.
 *   Pemegangnya adalah pelanggan yang kita undang sendiri; menyembunyikan
 *   darinya bahwa tautannya kedaluwarsa (dan menampilkan 404 yang sama dengan
 *   tebakan acak) berarti ia menyangka alamatnya salah ketik dan diam. Yang
 *   dibedakan hanyalah SEBABNYA; isinya tetap sekurang mungkin: kode tiket dan
 *   kalimat sebabnya, tidak pernah nama penerima, nama teknisi, atau skor
 *   milik tautan lain.
 *
 * Enam keadaan:
 *   unknown   404  token tak dikenal — tidak membocorkan apa pun
 *   form      200  formulir: kode + judul + tanggal selesai, 5 tombol, komentar
 *   receipt   200  struk penilaian YANG INI — pemberi nilai berhak melihatnya
 *   revoked   410  dicabut penerbitnya
 *   expired   410  masa berlaku habis
 *   taken     410  tiket sudah dinilai lewat tautan LAIN (tanpa skornya)
 *   reopened  409  tiket sedang dikerjakan kembali — tautannya masih berlaku
 *
 * 409, bukan 410 dan bukan 422, untuk tiket yang dibuka kembali: 410 berarti
 * hilang selamanya (tautannya tidak hilang, ia kembali hidup saat tiketnya
 * selesai lagi) dan 422 berarti isian pelanggannya salah (isiannya benar; yang
 * berubah adalah keadaan dunia).
 */
class CsatPageController extends Controller
{
    public function __construct(private readonly CsatService $service) {}

    public function show(string $token): Response
    {
        $row = $this->service->findByToken($token);

        if ($row === null) {
            return $this->unknown();
        }

        return $this->stateFor($row, $token);
    }

    public function rate(Request $request, string $token): Response
    {
        $row = $this->service->findByToken($token);

        if ($row === null) {
            return $this->unknown();
        }

        $score = $this->scoreFrom($request->input('score'));

        if ($score === null) {
            /*
             * KEADAAN BARIS MENANG ATAS "PILIH DULU", dan urutannya harus
             * sama persis dengan show() — termasuk cabang isRated() yang dulu
             * hilang di sini. Tanpa baris itu, satu POST kosong pada tautan
             * yang SUDAH dipakai mengembalikan FORMULIR berikut lima
             * tombolnya: janji "sesudah dipakai tidak pernah formulir lagi"
             * dibatalkan lewat pintu belakang, di jalan masuk kedua halaman
             * yang sama. Terukur 10 Sep 2026.
             *
             * stateFor() dipanggil apa adanya supaya tidak ada dua daftar
             * keadaan yang bisa berselisih; "pilih dulu bintangnya" hanya
             * ditambahkan ketika tautannya memang masih hidup.
             */
            if ($row->isRated() || $this->terminalFor($row) !== null) {
                return $this->stateFor($row, $token);
            }

            return $this->form($row, $token,
                error: 'Pilih dulu salah satu bintang penilaian Anda.', status: 422);
        }

        $comment = (string) $request->input('comment', '');

        try {
            $rated = $this->service->rate($token, $score->value, $comment);

            return $this->receipt($rated, fresh: true);
        } catch (LogicException) {
            $row->refresh();

            return $this->stateFor($row, $token);
        } catch (QueryException) {
            /*
             * Kalah balapan pada tingkat KUNCI BASIS DATA, bukan tingkat
             * logika (idiom ExternalApprovalPageController): dua klik serentak
             * membuat yang kalah menabrak "database is locked" SQLite di dalam
             * transaksinya sendiri. Invarian sekali-pakai tetap utuh; tanpa
             * cabang ini yang kalah menerima 500 telanjang alih-alih struk.
             */
            $row->refresh();

            if ($row->isRated()) {
                return $this->receipt($row, fresh: false);
            }

            return $this->terminal($row,
                'Sistem sedang memproses klik lain pada tautan ini — muat ulang halaman untuk melihat hasilnya.', 503);
        }
    }

    /**
     * Skor yang dikirim formulir, atau null.
     *
     * Perbandingan KETAT terhadap lima nilai yang benar-benar dicetak
     * halamannya — bukan `(int) $input`. Cast itu diam-diam menerima "4.5"
     * sebagai 4, "4abc" sebagai 4, dan " 5 " sebagai 5: sebuah nilai yang tidak
     * pernah ditawarkan halaman ini akan tercatat sebagai penilaian pelanggan,
     * dibulatkan tanpa satu kata pun. Terukur 10 Sep 2026 — "4.5" tercatat 4
     * sampai baris ini ada.
     */
    private function scoreFrom(mixed $input): ?CsatScore
    {
        if (! is_string($input) && ! is_int($input)) {
            return null;
        }

        foreach (CsatScore::ascending() as $score) {
            if ((string) $input === (string) $score->value) {
                return $score;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------- state

    /** Halaman yang benar untuk keadaan baris ini, apa pun jalan masuknya. */
    private function stateFor(CsatRating $row, string $token): Response
    {
        if ($row->isRated()) {
            return $this->receipt($row, fresh: false);
        }

        return $this->terminalFor($row) ?? $this->form($row, $token);
    }

    /**
     * Halaman mati untuk baris ini, atau null bila tautannya masih hidup.
     *
     * SATU tempat yang memutuskan urutan sebabnya, dipanggil dari show(),
     * rate() yang gagal, dan rate() tanpa pilihan — supaya ketiganya tidak
     * bisa berselisih tentang keadaan baris yang sama.
     */
    private function terminalFor(CsatRating $row): ?Response
    {
        if ($row->isRevoked()) {
            return $this->terminal($row, 'Tautan penilaian ini sudah dicabut oleh penerbitnya.', 410);
        }

        if ($row->isExpired()) {
            return $this->terminal($row, sprintf(
                'Tautan penilaian ini sudah kedaluwarsa sejak %s.',
                $row->expires_at?->format('d-m-Y H:i'),
            ), 410);
        }

        $ticket = $this->ticket($row);

        if ($ticket === null) {
            return $this->terminal($row, 'Tiket yang dimintakan penilaian sudah tidak ada di sistem.', 410);
        }

        // Tautan LAIN pada tiket yang sama sudah menjadi penilaiannya. Skor dan
        // komentarnya TIDAK ditampilkan: pemegang tautan ini bukan yang menulis.
        $ratedElsewhere = CsatRating::query()
            ->where('ticket_id', $ticket->getKey())
            ->whereKeyNot($row->getKey())
            ->whereNotNull('rated_at')
            ->exists();

        if ($ratedElsewhere) {
            return $this->terminal($row,
                'Tiket ini sudah dinilai lewat tautan lain. Terima kasih — satu tiket cukup dinilai sekali.', 410);
        }

        if (! in_array($ticket->status?->value, CsatService::RATABLE, true)) {
            return $this->terminal($row,
                'Tiket ini sedang dikerjakan kembali, jadi belum bisa dinilai. Tautan Anda tetap berlaku — '
                .'silakan buka lagi setelah pekerjaannya dinyatakan selesai.', 409);
        }

        return null;
    }

    // ----------------------------------------------------------------- pages

    private function form(CsatRating $row, string $token, ?string $error = null, int $status = 200): Response
    {
        $ticket = $this->ticket($row);

        return $this->view([
            'state' => 'form',
            'row' => $row,
            'ticket' => $this->summarize($ticket),
            'scores' => CsatScore::ascending(),
            'token' => $token,
            'error' => $error,
        ], $status);
    }

    private function receipt(CsatRating $row, bool $fresh): Response
    {
        return $this->view([
            'state' => 'receipt',
            'row' => $row,
            'ticket' => $this->summarize($this->ticket($row)),
            'fresh' => $fresh,
        ], 200);
    }

    private function terminal(CsatRating $row, string $message, int $status): Response
    {
        return $this->view([
            'state' => 'terminal',
            'row' => $row,
            'ticket' => $this->summarize($this->ticket($row)),
            'message' => $message,
        ], $status);
    }

    /**
     * Token tak dikenal. TIDAK menerima baris apa pun dan tidak menerima
     * tokennya: halaman ini harus byte-identik untuk setiap tebakan.
     */
    private function unknown(): Response
    {
        return $this->view([
            'state' => 'unknown',
            'message' => 'Tautan penilaian tidak dikenal atau sudah tidak berlaku.',
        ], 404);
    }

    private function view(array $data, int $status): Response
    {
        return response()->view('svcdesk::public.penilaian', $data + [
            'company' => $this->companyName(),
        ], $status);
    }

    // ------------------------------------------------------------- the ticket

    private function ticket(CsatRating $row): ?Ticket
    {
        return Ticket::query()->find($row->ticket_id);
    }

    /**
     * APA YANG BOLEH DILIHAT HALAMAN PUBLIK tentang tiketnya — ditulis tangan,
     * pola summarize() registri persetujuan eksternal, dan sengaja tanpa
     * fallback yang mencetak kolom sembarang.
     *
     * Kode, judul, dan tanggal selesai: cukup bagi pelanggan untuk mengenali
     * kunjungan mana yang sedang ia nilai. TIDAK ADA nama teknisi (nama seorang
     * karyawan pada halaman yang bisa dibuka siapa pun yang menerima teruskan
     * tautan tidak membeli apa pun — pelanggannya sudah tahu siapa yang
     * datang), tidak ada deskripsi masalah, tidak ada catatan penyelesaian,
     * tidak ada nama pelapor, dan tidak ada satu pun tiket lain.
     *
     * @return array{code: string, title: string, finished_at: ?string}|null
     */
    private function summarize(?Ticket $ticket): ?array
    {
        if ($ticket === null) {
            return null;
        }

        $finished = $ticket->closed_at ?? $ticket->resolved_at;

        return [
            'code' => (string) $ticket->code,
            'title' => (string) $ticket->title,
            'finished_at' => $finished?->format('d-m-Y'),
        ];
    }

    private function companyName(): string
    {
        return (string) (Company::query()->value('name') ?: 'Kontraktor');
    }
}
