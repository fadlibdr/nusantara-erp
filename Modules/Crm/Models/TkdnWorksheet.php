<?php

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * P7: lembar hitung TKDN atas satu penawaran.
 *
 * Tidak ada accessor persentase di sini ON PURPOSE. Angkanya datang dari
 * TkdnService::summary(), satu tempat, bersama cakupan penilaiannya — sebuah
 * getter `tkdn_pct` di model akan membuat persentase itu bisa dibaca TANPA
 * cakupannya, dan persentase TKDN tanpa cakupan persis itulah kalimat yang
 * tidak boleh dicetak di atas tanda tangan.
 */
class TkdnWorksheet extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'crm_tkdn_worksheets';

    public string $documentType = 'TKD';

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function tenderPackage(): BelongsTo
    {
        return $this->belongsTo(TenderPackage::class, 'tender_package_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TkdnWorksheetItem::class, 'worksheet_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
