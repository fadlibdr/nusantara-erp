<?php

namespace Modules\Procurement\Services;

use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Support\Money;
use Modules\Finance\Services\BudgetRealisationService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Subcontract\Models\Subcontract;

/**
 * Temuan #33 — gate anggaran pada pengajuan PO dan SPK.
 *
 * CommitmentService menghitung "berapa yang sudah dijanjikan" sejak lama,
 * tetapi hanya untuk laporan: tidak ada satu pintu pun yang membacanya
 * SEBELUM janji baru ditandatangani. PO/SPK yang menjebol RAP lolos diam-diam
 * dan baru kelihatan di laporan profitabilitas, saat komitmennya sudah jadi.
 *
 * EMBER PERBANDINGANNYA MENGIKUTI CARA KOMITMEN BISA DIPILAH. CommitmentService
 * memisahkan komitmen menjadi dua: PO dan SPK — sebuah PO tidak membawa
 * kategori biaya (satu PO bisa mencampur material dan alat), jadi memilah
 * lebih halus dari itu berarti mengarang angka. Maka:
 *
 *   SPK  diukur terhadap anggaran RAP kategori SUBKON
 *        (realisasi subkon + komitmen SPK berjalan + SPK ini);
 *   PO   diukur terhadap anggaran RAP NON-subkon — material+upah+alat+overhead
 *        (realisasi non-subkon + komitmen PO berjalan + PO ini).
 *
 * Kedua sisi menjumlah menjadi persis "sisa anggaran = RAP − aktual −
 * komitmen" milik laporan profitabilitas, hanya dibelah pada garis yang bisa
 * dipertanggungjawabkan kedua sumbernya.
 *
 * Semua nilai DPP, tanpa PPN — satuan yang sama dengan CommitmentService dan
 * fin_project_costs; membandingkan total ber-PPN dengan anggaran tanpa PPN
 * akan menuduh setiap dokumen 11% lebih rakus dari kenyataannya.
 *
 * Perilaku pelanggaran adalah KEBIJAKAN (erp.procurement.budget_gate):
 *   'warn'  (bawaan) pengaju harus mengakui pelampauannya secara eksplisit —
 *           422 pada kunci `budget` (pola confirm-resubmit temuan #72) sampai
 *           payload membawa confirm_over_budget;
 *   'block' 422 keras, konfirmasi tidak menolong; jalan keluarnya revisi RAP
 *           atau kebijakan;
 *   'off'   gate mati.
 * Nilai tak dikenal diperlakukan sebagai 'warn' — salah ketik kebijakan tidak
 * boleh diam-diam mematikan gate.
 *
 * Tanpa proyek atau tanpa RAP disetujui gate diam: tidak ada anggaran berarti
 * tidak ada angka yang bisa dilampaui, dan menolak pembelian non-proyek atas
 * nama anggaran yang tidak ada hanyalah mengarang blokir.
 *
 * F-2 — KETIGA ANGKANYA TIDAK LAGI DIHITUNG DI SINI. Anggaran, realisasi dan
 * komitmen pindah ke Finance\Services\BudgetRealisationService, tempat layar
 * portofolio dan layar anggaran bulanan MEMBACA YANG SAMA. Sebelumnya ketiganya
 * hidup di tiga metode privat kelas ini, satu-satunya pembacanya adalah gerbang
 * ini, dan sebuah layar yang menjawab pertanyaan yang sama pasti menulis SQL
 * keduanya — hari mereka berselisih adalah hari seorang manajer proyek membaca
 * "sisa Rp 80 juta" lalu ditolak saat memesan Rp 50 juta. Kalimat penolakan,
 * pembelahan subkon/non-subkon, kebijakan warn/block/off dan setiap ambangnya
 * TIDAK berubah; yang berubah hanya dari mana ketiga angkanya datang, dan
 * BudgetPortfolioEqualityTest mengajukan PO/SPK sungguhan tepat di batasnya
 * untuk membuktikan layar dan gerbang menjawab satu angka yang sama.
 */
class BudgetGateService
{
    public function __construct(private readonly BudgetRealisationService $budgets) {}

    public function assertPoWithinBudget(PurchaseOrder $po, bool $confirmed): void
    {
        $this->assertWithinBudget(
            $po->project_id !== null ? (int) $po->project_id : null,
            subconSide: false,
            documentDpp: (float) $po->dpp,
            documentName: "PO {$po->code}",
            confirmed: $confirmed,
        );
    }

    public function assertSpkWithinBudget(Subcontract $spk, bool $confirmed): void
    {
        $this->assertWithinBudget(
            $spk->project_id !== null ? (int) $spk->project_id : null,
            subconSide: true,
            documentDpp: (float) $spk->value,
            documentName: "SPK {$spk->code}",
            confirmed: $confirmed,
        );
    }

    private function assertWithinBudget(
        ?int $projectId,
        bool $subconSide,
        float $documentDpp,
        string $documentName,
        bool $confirmed,
    ): void {
        $mode = (string) config('erp.procurement.budget_gate', 'warn');

        if ($mode === 'off' || $projectId === null) {
            return;
        }

        $side = $this->budgets->side($projectId, $subconSide);

        if ($side['budget'] === null) {
            return;
        }

        $budget = (float) $side['budget'];
        $actual = $side['actual'];
        $committed = $side['committed'];

        $remaining = (float) $side['remaining'];
        $overshoot = round($documentDpp - $remaining, 2);

        if ($overshoot <= 0) {
            return;
        }

        $sideLabel = $subconSide ? 'subkon' : 'non-subkon (material/upah/alat/overhead)';
        $committedLabel = $subconSide ? 'komitmen SPK berjalan' : 'komitmen PO berjalan';

        $numbers = sprintf(
            'Anggaran RAP %s proyek ini %s; realisasi %s dan %s %s menyisakan %s, '
            .'sedangkan %s membawa DPP %s — melampaui anggaran %s.',
            $sideLabel,
            Money::format($budget, false),
            Money::format($actual, false),
            $committedLabel,
            Money::format($committed, false),
            Money::format(max(0.0, $remaining), false),
            $documentName,
            Money::format($documentDpp, false),
            Money::format($overshoot, false),
        );

        if ($mode === 'block') {
            // LogicException, bukan ValidationException: 'block' tidak punya
            // jalur konfirmasi, jadi pesannya tidak boleh berbentuk tawaran
            // yang bisa di-resubmit SPA.
            throw new LogicException(
                $numbers.' Kebijakan gate anggaran disetel "block": revisi RAP-nya, atau ubah '
                .'erp.procurement.budget_gate bila kebijakan perusahaan memang berbeda.'
            );
        }

        if ($confirmed) {
            // Pengakuan datang dari dialog SPA yang menampilkan kalimat angka
            // di atas apa adanya; jejaknya baris `submitted` di core_approvals
            // atas nama pengaju yang mengonfirmasi.
            return;
        }

        throw ValidationException::withMessages([
            'budget' => $numbers.' Ajukan ulang dengan konfirmasi bila komitmen ini memang harus jalan.',
        ]);
    }
}
