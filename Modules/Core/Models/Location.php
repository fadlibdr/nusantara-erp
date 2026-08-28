<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\LocationKind;

/**
 * P1-ENG: one node of a project's site breakdown (core_locations).
 *
 * NO relation to Project on purpose: Core may depend on no module
 * (ARCHITECTURE.md). project_id is a bare column; whoever needs the project
 * joins from their own side of the dependency arrow.
 *
 * THE HIERARCHY INVARIANTS LIVE HERE, on saving/deleting hooks, not in a
 * service. This table has TWO writers — the API controller and the master-data
 * importer, which creates and fills rows directly (MasterDataImportService::
 * commit) — and a guard in a service the importer never calls is a guard that
 * does not guard. The exceptions are ValidationException, so both writers
 * answer 422 with the sentence below rather than persisting a broken tree.
 */
class Location extends BaseModel
{
    use SoftDeletes;

    protected $table = 'core_locations';

    protected function casts(): array
    {
        return [
            'kind' => LocationKind::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Location $location): void {
            $location->assertParentIsCoherent();
        });

        static::deleting(function (Location $location): void {
            // Refuse while children exist — orphaning them would silently
            // reattach whole floors to nothing. forceDeleting is not special-
            // cased: nothing in this application force-deletes locations.
            $children = $location->children()->count();

            if ($children > 0) {
                throw ValidationException::withMessages(['parent_id' => sprintf(
                    'Lokasi %s masih memiliki %d sub-lokasi; hapus atau pindahkan dulu sub-lokasinya.',
                    $location->code,
                    $children,
                )]);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** "Tower 1 › Lantai 3 › Zona A" — for pickers and printed sheets. */
    public function path(): string
    {
        $names = [$this->name];
        $node = $this;

        while (($node = $node->parent) !== null) {
            array_unshift($names, $node->name);
        }

        return implode(' › ', $names);
    }

    private function assertParentIsCoherent(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        $parent = self::query()->find($this->parent_id);

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => 'Induk lokasi tidak ditemukan.']);
        }

        if ((int) $parent->project_id !== (int) $this->project_id) {
            throw ValidationException::withMessages(['parent_id' => sprintf(
                'Induk lokasi %s berada pada proyek lain; induk dan anak harus pada proyek yang sama.',
                $parent->code,
            )]);
        }

        // Walk up from the chosen parent; meeting ourselves means the edit
        // would close a loop (a tower under its own floor). Bounded by the
        // real chain length, which is five kinds deep in practice.
        for ($node = $parent; $node !== null; $node = $node->parent) {
            if ($this->exists && (int) $node->getKey() === (int) $this->getKey()) {
                throw ValidationException::withMessages(['parent_id' => sprintf(
                    'Lokasi %s tidak boleh menjadi induk dari dirinya sendiri (siklus hirarki).',
                    $this->code,
                )]);
            }
        }
    }
}
