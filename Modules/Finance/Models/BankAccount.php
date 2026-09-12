<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;

class BankAccount extends BaseModel
{
    use SoftDeletes;

    /**
     * Kode rekening = nama sub-folder folder terpantau (P-3c): huruf/angka/titik/strip/garis bawah,
     * tanpa spasi, tanpa pemisah jalur. SATU pola untuk Request (kode baru), pemindai folder, dan
     * kartu Kesiapan (kode lama) — V-permukaan-3.
     */
    public const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/';

    public const CODE_RULE_MESSAGE = 'Kode rekening hanya boleh huruf, angka, titik, strip, dan garis bawah (tanpa spasi) — ia menjadi nama sub-folder folder terpantau.';

    protected $table = 'fin_bank_accounts';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'import_preset' => 'array',
        ];
    }

    /** Preset impor per rekening (P-3c) — null selama belum disimpan dari pratinjau yang berhasil. */
    public function importPreset(): ?array
    {
        $preset = $this->import_preset;

        return is_array($preset) && $preset !== [] ? $preset : null;
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'coa_account_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'bank_account_id');
    }
}
