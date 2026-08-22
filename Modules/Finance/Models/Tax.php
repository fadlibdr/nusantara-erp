<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Enums\TaxType;

class Tax extends BaseModel
{
    use SoftDeletes;

    protected $table = 'fin_taxes';

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'tax_type' => TaxType::class,
        ];
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'coa_account_id');
    }

    public function amountOn(float $base): float
    {
        return round($base * (float) $this->rate / 100, 2);
    }

    /**
     * Canonical tax code for a PPh final jasa konstruksi scheme key from
     * config('erp.tax.pph_final_construction'), e.g.
     * 'pelaksanaan_bersertifikat' => 'PPH4A2-PELAKSANAAN-BERSERTIFIKAT'.
     */
    public static function pphFinalCodeForScheme(string $schemeKey): string
    {
        return 'PPH4A2-'.strtoupper(str_replace('_', '-', $schemeKey));
    }
}
