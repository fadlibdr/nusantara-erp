<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Satu baris ledger folder terpantau (P-3c): satu (jalur relatif, kunci).
 * Kunci = sha256 isi untuk berkas yang dibaca; untuk berkas yang ditolak
 * sebelum dibaca (tautan simbolik, sub-folder asing, > 2 MB, hak akses)
 * kunci diturunkan dari jalurnya (BankInboxService::pathKey). Lihat migrasi
 * 001503 untuk semantik status dan mengapa tidak ada FK.
 *
 * Status tersimpan: imported | failed | duplicate | ignored | superseded
 * (baris failed/ignored/duplicate untuk jalur yang isinya kemudian berganti —
 * sejarah, tidak dihitung ubin; putaran verifikasi V-folder-3). STATEMENT_DELETED
 * tidak pernah disimpan: dihitung saat dibaca untuk baris imported/duplicate yang
 * rekening korannya sudah dihapus (V-folder-4).
 */
class BankInboxFile extends BaseModel
{
    public const IMPORTED = 'imported';

    public const FAILED = 'failed';

    public const DUPLICATE = 'duplicate';

    public const IGNORED = 'ignored';

    public const SUPERSEDED = 'superseded';

    /** Hanya di muatan API/layar, bukan di kolom status. */
    public const STATEMENT_DELETED = 'statement_deleted';

    /** Label layar — dari sini, bukan disusun SPA. */
    public const STATUS_LABELS = [
        self::IMPORTED => 'Diimpor',
        self::FAILED => 'Gagal',
        self::DUPLICATE => 'Salinan',
        self::IGNORED => 'Diabaikan',
        self::SUPERSEDED => 'Digantikan',
        self::STATEMENT_DELETED => 'Rekening koran dihapus',
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
