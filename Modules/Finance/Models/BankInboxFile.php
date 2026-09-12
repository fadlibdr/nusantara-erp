<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Satu baris ledger folder terpantau (P-3c): satu (jalur relatif, sha256).
 * Lihat migrasi 001503 untuk semantik status dan mengapa tidak ada FK.
 */
class BankInboxFile extends BaseModel
{
    public const IMPORTED = 'imported';

    public const FAILED = 'failed';

    public const DUPLICATE = 'duplicate';

    public const IGNORED = 'ignored';

    /** Label layar — dari sini, bukan disusun SPA. */
    public const STATUS_LABELS = [
        self::IMPORTED => 'Diimpor',
        self::FAILED => 'Gagal',
        self::DUPLICATE => 'Salinan',
        self::IGNORED => 'Diabaikan',
    ];

    protected $table = 'fin_bank_inbox_files';

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'file_mtime' => 'datetime',
            'first_seen_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id')->withTrashed();
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
