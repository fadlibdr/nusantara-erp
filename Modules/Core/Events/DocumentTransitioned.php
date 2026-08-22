<?php

namespace Modules\Core\Events;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A document moved through its approval lifecycle: submitted, approved or
 * rejected. Fired by Modules\Core\Traits\Approvable for all twelve approvable
 * document types.
 *
 * Listeners must treat this as advisory. It is dispatched from inside the
 * business transaction, so anything a listener does has to be incapable of
 * failing it.
 */
class DocumentTransitioned
{
    use Dispatchable;

    public function __construct(
        public readonly Model $document,
        public readonly string $action,   // submitted|approved|rejected
        public readonly ?User $actor = null,
        public readonly ?string $note = null,
    ) {}
}
