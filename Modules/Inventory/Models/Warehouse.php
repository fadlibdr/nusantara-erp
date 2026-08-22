<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Project;

class Warehouse extends BaseModel
{
    use SoftDeletes;

    protected $table = 'inv_warehouses';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'warehouse_id');
    }

    /**
     * The job a site warehouse belongs to; null on a central one.
     *
     * Cross-module belongsTo with no database constraint behind it (see
     * docs/CONVENTIONS.md §3), so nothing stops a project being soft-deleted
     * out from under a warehouse that is still stacked with its material.
     *
     * withTrashed, and the distinction it draws is the whole point — the same
     * one Issue::project() draws, in the same words. A warehouse with NO
     * project_id is a CENTRAL store and prints as one: no PROYEK box, no
     * contract day count, and that is honest. A warehouse whose project has
     * since been deleted is still the site store of that job, and every sheet
     * drawn on it — penerimaan-barang, berita-acara-opname, retur-pembelian,
     * surat-jalan-transfer, saldo-stok — is a job document. Loaded plainly the
     * relation collapsed the two into each other: the PROYEK line emptied and
     * the sheet demoted, silently, to an office document.
     *
     * The five registry entries above each declare `withTrashed` on their own
     * eager load, so they were already right; a sixth caller reading the
     * relation plainly was not, and the guarantee belongs on the relation
     * rather than on five declarations in a file none of them owns.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id')->withTrashed();
    }

    /** The storeman answerable for what is on these shelves. */
    public function keeper(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'keeper_employee_id');
    }

    public function isSiteWarehouse(): bool
    {
        return $this->project_id !== null;
    }
}
