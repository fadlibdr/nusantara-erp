<?php

namespace Modules\HrPayroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\HrPayroll\Enums\CertificateType;

class Certificate extends BaseModel
{
    use SoftDeletes;

    protected $table = 'hr_certificates';

    protected function casts(): array
    {
        return [
            'certificate_type' => CertificateType::class,
            'issued_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Signed days until expiry: negative = already lapsed, null = never lapses.
     * startOfDay on both sides because the date cast stores midnight strings in
     * SQLite — an afternoon `now()` would otherwise shave the last day off.
     */
    public function daysToExpiry(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false);
    }
}
