<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Services\FormPrintService;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\Project;

class ArInvoice extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'fin_ar_invoices';

    public string $documentType = 'INV';

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'dpp' => 'decimal:2',
            'ppn_rate' => 'decimal:4',
            'ppn_amount' => 'decimal:2',
            'retention_withheld' => 'decimal:2',
            // P3 — the owner claim's three deductions and the advance flag.
            'is_advance' => 'boolean',
            'advance_recovery_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'date',
            // T3.7 — surat penagihan ke-1/2/3: the highest letter issued and when.
            'dunning_level' => 'integer',
            'last_dunning_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status' => DocumentStatus::class,
        ];
    }

    /**
     * Surat penagihan ke-1, ke-2, ke-3 — dan berhenti di situ. Surat ketiga
     * adalah surat TERAKHIR (FinanceFormService::DUNNING_TITLES); langkah
     * setelahnya bukan surat lagi, melainkan ketentuan kontraknya.
     */
    public const DUNNING_LEVELS = 3;

    /**
     * Mengapa surat penagihan berikutnya TIDAK boleh diterbitkan sekarang,
     * atau null bila boleh. Satu definisi untuk tombol (ArInvoiceResource::
     * dunning_next_level), untuk layanan yang menaikkan tingkatnya, dan untuk
     * lembar cetaknya — aturan yang disalin ke tiga tempat adalah aturan yang
     * akan berbeda di salah satunya.
     *
     * "Sudah jatuh tempo" dibaca due_date <= hari ini: tier LEWAT pengawas
     * ar_invoice_due (WatchedDeadlines, lead 0) menyebut invoice pada hari
     * jatuh temponya, dan surat pertama boleh dicetak pagi yang sama —
     * badan suratnya menyatakan "telah jatuh tempo pada <tanggal>", yang
     * sebelum tanggal itu adalah klaim palsu di atas kop perusahaan. Hari
     * ini = Carbon::today() zona aplikasi (Asia/Jakarta), jam yang sama
     * dengan DeadlineWatchCommand.
     */
    public function dunningRefusal(): ?string
    {
        if ($this->isCancelled()) {
            return "Invoice {$this->code} sudah dibatalkan; tidak ada tagihan yang perlu disurati.";
        }

        if ($this->status !== DocumentStatus::Approved) {
            return "Invoice {$this->code} belum disetujui ({$this->status->label()}); surat penagihan hanya untuk invoice yang sudah disetujui.";
        }

        if ($this->isFullyPaid()) {
            return "Invoice {$this->code} sudah lunas; tidak ada sisa tagihan yang perlu disurati.";
        }

        if ($this->due_date !== null && $this->due_date->gt(Carbon::today())) {
            return "Invoice {$this->code} belum jatuh tempo (".FormPrintService::dateText($this->due_date).'); surat penagihan dicetak setelah tanggal jatuh temponya.';
        }

        if ((int) $this->dunning_level >= self::DUNNING_LEVELS) {
            return "Invoice {$this->code} sudah pada surat penagihan ke-".self::DUNNING_LEVELS.' (terakhir); penyelesaian selanjutnya mengikuti ketentuan kontrak, bukan surat lagi.';
        }

        return null;
    }

    /** Surat penagihan yang boleh dicetak berikutnya (1..3), atau null. */
    public function dunningNextLevel(): ?int
    {
        return $this->dunningRefusal() === null ? (int) $this->dunning_level + 1 : null;
    }

    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::Cancelled;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function termin(): BelongsTo
    {
        return $this->belongsTo(ContractTermin::class, 'termin_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** P3 — the owner opname this claim was assembled from, when it was. */
    public function measurement(): BelongsTo
    {
        return $this->belongsTo(ProgressMeasurement::class, 'measurement_id');
    }

    public function retentions(): HasMany
    {
        return $this->hasMany(ArRetention::class, 'source_invoice_id');
    }

    /**
     * Sisa tagihan menurut buku besar.
     *
     * Pembatalan sudah membalik piutangnya, jadi nol di sini bukan pembulatan
     * melainkan kenyataan: tanpa ini daftar invoice memasang angka penuh di
     * kolom "Sisa" tepat di sebelah lencana Dibatalkan, dan penagihan mengejar
     * uang yang tidak lagi ada di 1-1300. Pembatalan mensyaratkan amount_paid
     * nol, jadi angka mentahnya selalu SELURUH nilai invoice.
     */
    public function outstanding(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    /** Dibatalkan bukan lunas — jangan diturunkan dari outstanding() yang nol. */
    public function isFullyPaid(): bool
    {
        return ! $this->isCancelled() && $this->outstanding() <= 0.0;
    }
}
