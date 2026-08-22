<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Finance\Enums\BankStatementFormat;

/**
 * One imported bank statement for one bank account.
 *
 * Deliberately NOT soft-deleting. A trashed row would keep its content_hash
 * slot forever, so a statement deleted because it was imported with a broken
 * column mapping could never be re-imported — the one case where deleting is
 * the intended remedy. Deletion is only permitted while no line is matched
 * (BankStatementImportService::delete()), so nothing can be orphaned by it.
 */
class BankStatement extends BaseModel
{
    use HasDocumentNumber;

    protected $table = 'fin_bank_statements';

    public string $documentType = 'BST';

    protected function casts(): array
    {
        return [
            'source_format' => BankStatementFormat::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'line_count' => 'integer',
            'parse_options' => 'array',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'bank_statement_id')->orderBy('line_no');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
