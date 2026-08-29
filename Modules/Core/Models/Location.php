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

    /**
     * How deep subtreeIds() is willing to walk. The vocabulary is five kinds
     * deep (LocationKind), and the doubling is slack for a site broken down
     * more finely than the enum's names suggest — never an invitation.
     */
    private const MAX_SUBTREE_DEPTH = 10;

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

    /**
     * This node's id together with every id BENEATH it.
     *
     * A LOCATION IS A PLACE, NOT A POINT. This table is a hierarchy (Tower ›
     * Lantai › Zona › As › Ruang, PANDUAN §16) and a fact recorded at the room
     * is a fact inside the zone containing it, because that is where an
     * inspector actually stands when he writes it down. Anything that asks
     * "what is open in this zone" by comparing a foreign location_id to one key
     * asks about the node and answers about the place — which is how a zone
     * with an open NCR one level down stayed freely markable "Selesai" on a
     * BAPP (ZoneCertificateService::openNcrCodes).
     *
     * One query per LEVEL, not per node, so a floor with sixty rooms costs the
     * same as a floor with one. Trashed nodes stay out, by the model's own
     * scope: a deleted room is not on the drawing any more, and the deleting
     * hook above refuses while children exist, so no live node hides beneath a
     * deleted one.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [(int) $this->getKey()];
        $frontier = $ids;

        // Bounded. The saving hook refuses a cycle, but it only guards rows
        // that pass through this model, and a loop reached from here would spin
        // inside a request instead of refusing one.
        for ($depth = 0; $depth < self::MAX_SUBTREE_DEPTH && $frontier !== []; $depth++) {
            $frontier = self::query()
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $ids = array_merge($ids, $frontier);
        }

        return $ids;
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
