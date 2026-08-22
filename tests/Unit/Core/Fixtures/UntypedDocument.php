<?php

namespace Tests\Unit\Core\Fixtures;

use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * Uses the trait but declares no $documentType — the hook must leave the
 * document alone instead of numbering it under an empty type.
 */
class UntypedDocument extends BaseModel
{
    use HasDocumentNumber;

    protected $table = 'test_documents';
}
