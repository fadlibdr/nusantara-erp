<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Modules\Core\Enums\ExternalDecision;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Core\Models\Company;
use Modules\Core\Models\ExternalApproval;
use Modules\Core\Services\ExternalApprovalService;
use Modules\Core\Support\ExternalApprovableDocuments;

/**
 * Halaman keputusan PUBLIK — satu-satunya layar sistem ini yang dibuka tanpa
 * login. Kemampuannya adalah tokennya: siapa pun yang memegang tautan
 * sekali-pakai boleh memutuskan, tepat sekali, dan tidak bisa apa-apa lagi.
 *
 * Kejujuran halaman terminal:
 *  - token tak dikenal → 404 yang TIDAK menyebut apa pun (bukan orakel
 *    enumerasi atas dokumen);
 *  - token terpakai → STRUK keputusan yang sudah tercatat, bukan formulir —
 *    dan bukan pesan kosong: pemutusnya berhak melihat apa yang ia putuskan;
 *  - dicabut/kedaluwarsa → 410 dengan alasannya dan kode dokumen, tidak lebih.
 *
 * Tanpa grup middleware 'web' dengan sengaja: tidak ada sesi, tidak ada cookie,
 * tidak ada CSRF — token sekali-pakai di URL adalah kapabilitasnya, dan CSRF
 * melindungi sesi yang di sini memang tidak ada. throttle:10,1 (preseden rute
 * login) yang menahan penebakan token.
 */
class ExternalApprovalPageController extends Controller
{
    public function __construct(private readonly ExternalApprovalService $service) {}

    public function show(string $token): Response
    {
        $row = $this->service->findByToken($token);

        if ($row === null) {
            return $this->unknown();
        }

        if ($row->isDecided()) {
            return $this->receipt($row, fresh: false);
        }

        if ($row->isRevoked()) {
            return $this->terminal($row, 'Tautan sudah dicabut oleh penerbitnya.', 410);
        }

        if ($row->isExpired()) {
            return $this->terminal($row, sprintf('Tautan sudah kedaluwarsa sejak %s.', $row->expires_at?->format('d-m-Y H:i')), 410);
        }

        return $this->form($row, $token);
    }

    public function decide(Request $request, string $token): Response
    {
        $row = $this->service->findByToken($token);

        if ($row === null) {
            return $this->unknown();
        }

        $decision = ExternalDecision::tryFrom((string) $request->input('decision'));

        if ($decision === null) {
            return $this->form($row, $token, error: 'Pilih salah satu keputusan terlebih dahulu.', status: 422);
        }

        $notes = mb_substr(trim((string) $request->input('notes', '')), 0, 1000);

        // Cermin aturan service, di sini supaya yang bersangkutan mendapat
        // FORMULIRNYA KEMBALI untuk menulis catatan — bukan halaman buntu.
        // Service tetap menegakkan hal yang sama sebagai jaring terakhir.
        if ($decision === ExternalDecision::ApprovedWithNotes && $notes === '') {
            return $this->form($row, $token,
                error: 'Keputusan "Setuju dengan catatan" harus menyertakan catatannya — '
                    .'tuliskan catatan Anda, atau pilih "Setuju".',
                status: 422);
        }

        try {
            $decided = $this->service->decide($token, $decision->value, $notes === '' ? null : $notes);

            return $this->receipt($decided, fresh: true);
        } catch (SelfApprovalException) {
            // Aturan pemisahan tugas internal menolak PENERAPAN keputusan;
            // transaksi digulung utuh, tautan belum terpakai. Nama-nama
            // internal dari pesan aslinya tidak ditampilkan ke luar.
            return $this->terminal($row->refresh(),
                'Keputusan tidak dapat dicatat: aturan pemisahan tugas internal kontraktor menolak penerapannya. '
                .'Tautan Anda belum terpakai — hubungi penerbit tautan.', 422);
        } catch (LogicException) {
            $row->refresh();

            if ($row->isDecided()) {
                // Kalah balapan dengan klik yang lain — struk keputusan yang
                // menang, bukan formulir dan bukan galat.
                return $this->receipt($row, fresh: false);
            }

            if ($row->isRevoked()) {
                return $this->terminal($row, 'Tautan sudah dicabut oleh penerbitnya.', 410);
            }

            if ($row->isExpired()) {
                return $this->terminal($row, sprintf('Tautan sudah kedaluwarsa sejak %s.', $row->expires_at?->format('d-m-Y H:i')), 410);
            }

            return $this->terminal($row,
                'Keputusan tidak dapat diterapkan pada dokumen — status dokumen sudah berubah di sistem. '
                .'Tautan Anda belum terpakai — hubungi penerbit tautan.', 422);
        } catch (QueryException) {
            /*
             * Kalah balapan pada tingkat KUNCI BASIS DATA, bukan tingkat
             * logika: dua klik serentak membuat yang kalah menabrak
             * "database is locked" SQLite di dalam transaksinya sendiri —
             * invarian sekali-pakai tetap utuh (persis satu keputusan
             * tercatat), tetapi tanpa cabang ini yang kalah menerima 500
             * telanjang alih-alih struk. Baca ulang: bila pemenang sudah
             * tercatat, tunjukkan struknya; bila belum (kunci sesaat oleh
             * penulis lain), minta muat ulang dengan jujur.
             */
            $row->refresh();

            if ($row->isDecided()) {
                return $this->receipt($row, fresh: false);
            }

            return $this->terminal($row,
                'Sistem sedang memproses klik lain pada tautan ini — muat ulang halaman untuk melihat hasilnya.', 503);
        }
    }

    // ----------------------------------------------------------------- pages

    private function form(ExternalApproval $row, string $token, ?string $error = null, int $status = 200): Response
    {
        [$label, $code, $summary] = $this->document($row);

        if ($summary === null) {
            return $this->terminal($row, 'Dokumen yang dimintakan persetujuan sudah tidak ada di sistem. Hubungi penerbit tautan.', 410);
        }

        return response()->view('coredoc::external.persetujuan', [
            'state' => 'form',
            'row' => $row,
            'label' => $label,
            'code' => $code,
            'summary' => $summary,
            'token' => $token,
            'error' => $error,
            'company' => $this->companyName(),
        ], $status);
    }

    private function receipt(ExternalApproval $row, bool $fresh): Response
    {
        [$label, $code] = $this->document($row);

        return response()->view('coredoc::external.persetujuan', [
            'state' => 'receipt',
            'row' => $row,
            'label' => $label,
            'code' => $code,
            'fresh' => $fresh,
            'company' => $this->companyName(),
        ], 200);
    }

    private function terminal(ExternalApproval $row, string $message, int $status): Response
    {
        [$label, $code] = $this->document($row);

        return response()->view('coredoc::external.persetujuan', [
            'state' => 'terminal',
            'row' => $row,
            'label' => $label,
            'code' => $code,
            'message' => $message,
            'company' => $this->companyName(),
        ], $status);
    }

    private function unknown(): Response
    {
        return response()->view('coredoc::external.persetujuan', [
            'state' => 'unknown',
            'message' => 'Tautan tidak dikenal atau sudah tidak berlaku.',
            'company' => $this->companyName(),
        ], 404);
    }

    /**
     * Label, kode, dan ringkasan dokumen milik satu baris persetujuan.
     * Ringkasan null berarti dokumennya sudah tidak ada.
     *
     * @return array{0: string, 1: string, 2: list<array{label: string, value: string}>|null}
     */
    private function document(ExternalApproval $row): array
    {
        $slug = $row->document_slug;
        $label = ExternalApprovableDocuments::labelFor($slug);
        $class = ExternalApprovableDocuments::classFor($slug);
        $document = $class === null ? null : $class::query()->find($row->document_id);

        if ($document === null) {
            return [$label, '—', null];
        }

        return [
            $label,
            (string) ($document->code ?? $document->getKey()),
            ExternalApprovableDocuments::summarize($slug, $document),
        ];
    }

    private function companyName(): string
    {
        return (string) (Company::query()->value('name') ?: 'Kontraktor');
    }
}
