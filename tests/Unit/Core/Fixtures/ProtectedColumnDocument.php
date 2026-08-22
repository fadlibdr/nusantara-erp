<?php

namespace Tests\Unit\Core\Fixtures;

use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * Declares the column override exactly as the HasDocumentNumber docblock spells
 * it: "protected string $documentNumberColumn = 'code';".
 */
class ProtectedColumnDocument extends BaseModel
{
    use HasDocumentNumber;

    public string $documentType = 'PR';

    protected string $documentNumberColumn = 'doc_no';

    protected $table = 'test_documents';
}
