<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Enums\TaxMasaType;

/**
 * One (jenis pajak, masa) row of the kalender pajak — see the migration for
 * why the register is manual entry and carries no soft deletes.
 */
class TaxObligation extends BaseModel
{
    protected $table = 'fin_tax_obligations';

    protected function casts(): array
    {
        return [
            'tax_type' => TaxMasaType::class,
            'due_date' => 'date',
            'disetor_date' => 'date',
            'dilapor_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** The JV the operator picked as having settled this masa — nothing automatic. */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    /**
     * Derived, never stored: 'belum' -> 'disetor' -> 'dilapor'. Deriving it
     * from the dates means a cleared date rolls the status back with it — a
     * stored status column would let the two disagree.
     */
    public function status(): string
    {
        return match (true) {
            $this->dilapor_date !== null => 'dilapor',
            $this->disetor_date !== null => 'disetor',
            default => 'belum',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            'dilapor' => 'Dilapor',
            'disetor' => 'Disetor',
            default => 'Belum disetor',
        };
    }
}
