<?php

namespace Modules\Core\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Events\DocumentTransitioned;
use Modules\Core\Exceptions\ApprovalLevelException;
use Modules\Core\Models\Approval;
use Modules\Core\Support\ApprovableDocuments;
use Modules\Core\Support\ApprovalLevels;
use Modules\Core\Support\ApprovalPolicy;
use Modules\Core\Support\Money;
use Modules\Core\Support\SegregationOfDuties;

/**
 * Draft -> submitted -> approved/rejected lifecycle for documents.
 * The model needs a `status` column cast to DocumentStatus.
 */
trait Approvable
{
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function submit(?User $by = null): static
    {
        $this->assertStatus([DocumentStatus::Draft, DocumentStatus::Rejected], 'submit');

        $this->forceFill(['status' => DocumentStatus::Submitted])->save();
        $this->recordApproval('submitted', $by);

        return $this;
    }

    /**
     * MAKER-CHECKER. Whoever submitted this document cannot be the one who
     * approves it — otherwise a single finance login raises a fictitious vendor
     * bill, approves it and pays it in one sitting, and the approval trail it
     * leaves behind is indistinguishable from a real one.
     *
     * The guard runs AFTER assertStatus deliberately. A draft must still be
     * refused with "while status is draft", which is both the more fundamental
     * error and the message several suites assert verbatim.
     *
     * reject() is NOT guarded, and that is not an oversight: rejecting your own
     * document returns it to your own desk, moves no money and asserts nothing.
     * Guarding it would strand documents whenever the second approver is away.
     */
    public function approve(User $by, ?string $note = null): static
    {
        $this->assertStatus([DocumentStatus::Submitted], 'approve');
        SegregationOfDuties::assertNotSubmitter($this, $by);

        // A document that opts into the n-level ladder (P2) needs several
        // distinct approvers and flips to Approved only at the last of them —
        // everything else keeps the single-approval lifecycle unchanged.
        if ($this->requiredApprovalLevels() > 1) {
            return $this->approveLevelled($by, $note);
        }

        $this->assertStampedDirectorLevel($by);

        $this->forceFill(['status' => DocumentStatus::Approved])->save();
        $this->recordApproval('approved', $by, $note);

        return $this;
    }

    /**
     * F-1 — tuntutan direktur yang DICAP pada baris `submitted`.
     *
     * Berlaku hanya untuk mode single_director, dan hanya untuk jenis dokumen
     * yang TIDAK membawa gerbangnya sendiri. Tiga tabel membawa
     * needs_director_approval (PO, SPK, addendum SPK) dan modulnyalah yang
     * menegakkannya, lebih dulu, dengan kalimat penolakannya sendiri yang
     * menyebut kedua angkanya; menggerbangi ulang di sini berarti dua
     * penolakan untuk satu aturan, dengan dua kalimat yang bisa berbeda.
     * ApprovalPolicy::modeIsLocked menjawab pertanyaan itu dari SKEMA, bukan
     * dari daftar kelas yang harus diingat orang berikutnya.
     *
     * PADA INSTALASI YANG BELUM DISUNTING PEMILIKNYA, INI DIAM. Dua puluh
     * empat dari dua puluh delapan jenis dikirim tanpa ambang, jadi stempelnya
     * berbunyi director=false dan tidak ada satu pun keputusan yang berubah
     * karena paket ini dipasang. Ia menyala pada baris yang pemiliknya isi.
     *
     * @throws ApprovalLevelException
     */
    protected function assertStampedDirectorLevel(User $by): void
    {
        $stamp = ApprovalPolicy::stampedFor($this);

        if ($stamp === null || ($stamp['director'] ?? false) !== true) {
            return;
        }

        if (ApprovalPolicy::modeIsLocked((string) ($stamp['type'] ?? ''))) {
            return;
        }

        $permission = ($stamp['prefix'] ?? '') === '' ? null : "{$stamp['prefix']}.approve-director";

        if ($permission !== null && $by->can($permission)) {
            return;
        }

        throw new ApprovalLevelException(sprintf(
            '%s %s senilai %s mencapai ambang persetujuan direktur %s yang berlaku saat dokumen ini '
            .'DIAJUKAN; ia hanya dapat disetujui oleh pemegang izin %s. Mengubah ambangnya di '
            .'Pengaturan → Matriks Persetujuan sekarang tidak mengubah tuntutan dokumen ini — '
            .'aturan yang berlaku adalah aturan saat pengajuan.',
            ApprovableDocuments::label($this),
            (string) ($this->code ?? $this->getKey()),
            Money::format((float) ($stamp['amount'] ?? 0), false),
            Money::format((float) ($stamp['threshold'] ?? 0), false),
            $permission ?? 'persetujuan direktur',
        ));
    }

    /**
     * The n-level path. Each distinct approver records one 'approved' row; the
     * document stays Submitted until the required number of DISTINCT approvers
     * is reached, and only the completing approval announces the transition —
     * an intermediate level is a real approval in the audit trail but is not
     * yet an approved document, so it must not notify the submitter that it is.
     *
     * ApprovalLevels enforces the two extra rules on top of maker-checker: a
     * person cannot supply two of the distinct approvals, and levels 2+ demand
     * the module's director permission.
     */
    protected function approveLevelled(User $by, ?string $note): static
    {
        $this->assertLadderIsCarriedByThisModule();

        $prior = ApprovalLevels::distinctApprovals($this);
        ApprovalLevels::assertMayApproveNext($this, $by, $prior);

        $isFinal = ($prior + 1) >= $this->requiredApprovalLevels();

        if ($isFinal) {
            $this->forceFill(['status' => DocumentStatus::Approved])->save();
            $this->recordApproval('approved', $by, $note); // row + DocumentTransitioned

            return $this;
        }

        // Intermediate level: record the approval (it counts toward the ladder)
        // but stay Submitted and stay quiet.
        $this->approvals()->create([
            'action' => 'approved',
            'user_id' => $by->id,
            'note' => $note,
        ]);

        return $this;
    }

    /**
     * SEBUAH PERSETUJUAN YANG BERHENTI DI TENGAH HANYA BOLEH TERJADI DI TEMPAT
     * MODULNYA MEMBACA STATUSNYA SESUDAHNYA.
     *
     * approveLevelled() menulis baris `approved` pertama TANPA memindahkan
     * dokumen dari `submitted`. Pemanggilnya — service modul — menjalankan
     * akibat persetujuan di baris berikutnya, dan hanya AwardDecisionService
     * yang bertanya lebih dulu apakah dokumennya sudah benar-benar disetujui.
     * Diukur 7 Sep 2026 pada jalur AR: sebuah stempel bertingkat dua pada
     * invoice termin memposting JV/2026/09/0001 pada dokumen yang masih
     * `submitted`, lalu JV/2026/09/0002 pada persetujuan kedua — Rp 2,22
     * miliar piutang/pendapatan/PPN keluaran terbukukan dua kali, tanpa satu
     * penolakan pun. Hal yang sama pada tagihan AP Rp 111 juta.
     *
     * Sejak putaran verifikasi F-1 layar tidak lagi menawarkan mode itu di
     * sana (SettingService::approvalMatrixGroup) dan resolvernya memaksa
     * single_director (ApprovalPolicy::forType). Penjaga ini adalah lapis
     * ketiga, untuk stempel yang sudah TERLANJUR tertulis sebelum keduanya:
     * sebuah penolakan yang dapat dibaca, bukan sebuah jurnal ganda.
     *
     * @throws ApprovalLevelException
     */
    protected function assertLadderIsCarriedByThisModule(): void
    {
        $type = ApprovalPolicy::slugFor($this);

        if ($type === null || ApprovalPolicy::supportsExtraLevel($type)) {
            return;
        }

        throw new ApprovalLevelException(sprintf(
            '%s %s membawa stempel kebijakan "tambahan tingkat", tetapi jenis dokumen ini tidak dapat '
            .'berhenti di tengah persetujuan — modulnya menjalankan akibat persetujuan (jurnal, stok) '
            .'begitu Setujui ditekan. Kembalikan "cara ambang berlaku" jenis ini ke "satu penyetuju" di '
            .'Pengaturan → Matriks Persetujuan, lalu ajukan ulang dokumen ini.',
            ApprovableDocuments::label($this),
            (string) ($this->code ?? $this->getKey()),
        ));
    }

    /**
     * The ladder key a document opts into, or null for the single-approval
     * default every existing Approvable keeps. A model returning a key must
     * also override approvalAmount() so the ladder has an amount to resolve.
     */
    public function approvalLadderKey(): ?string
    {
        return null;
    }

    /** The signed amount an amount-tiered ladder is resolved against. */
    public function approvalAmount(): float
    {
        return 0.0;
    }

    /**
     * How many distinct approvers this document needs.
     *
     * F-1 — DIBACA DARI STEMPEL, bukan diselesaikan ulang. Sampai paket ini,
     * jenjangnya dibaca dari config setiap kali seseorang menekan Setujui,
     * jadi menaikkan sebuah ambang siang hari MENGURANGI tuntutan setiap
     * dokumen yang sedang menunggu — surut, dan tanpa satu baris pun yang
     * mencatatnya. Yang mengikat sekarang adalah aturan saat dokumen DIAJUKAN.
     *
     * Dokumen yang diajukan sebelum kolomnya ada tidak punya stempel dan
     * jatuh ke jalur lama, yang persis perilaku kemarin: maju-saja, tidak ada
     * stempel yang ditulis surut, tidak ada riwayat yang dikarang.
     */
    public function requiredApprovalLevels(): int
    {
        $stamp = ApprovalPolicy::stampedFor($this);

        if ($stamp !== null) {
            return max(1, (int) ($stamp['levels'] ?? 1));
        }

        $key = $this->approvalLadderKey();

        if ($key === null) {
            return 1;
        }

        return ApprovalLevels::forAmount($key, $this->approvalAmount());
    }

    public function reject(User $by, ?string $note = null): static
    {
        $this->assertStatus([DocumentStatus::Submitted], 'reject');

        $this->forceFill(['status' => DocumentStatus::Rejected])->save();
        $this->recordApproval('rejected', $by, $note);

        return $this;
    }

    /**
     * Records the transition and announces it. The announcement is an event
     * rather than a direct call so this trait — which twelve document models
     * use — stays ignorant of who wants to hear about it.
     */
    protected function recordApproval(string $action, ?User $by, ?string $note = null): void
    {
        $this->approvals()->create([
            'action' => $action,
            'user_id' => $by?->id,
            'note' => $note,
        ]);

        DocumentTransitioned::dispatch($this, $action, $by, $note);
    }

    protected function assertStatus(array $allowed, string $action): void
    {
        $current = $this->status instanceof DocumentStatus
            ? $this->status
            : DocumentStatus::from((string) $this->status);

        if (! in_array($current, $allowed, true)) {
            throw new LogicException(
                "Cannot {$action} document {$this->code} while status is {$current->value}."
            );
        }
    }
}
