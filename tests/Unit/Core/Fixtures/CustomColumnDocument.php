<?php

namespace Tests\Unit\Core\Fixtures;

use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * Numbered document that stores its number somewhere other than `code`.
 */
class CustomColumnDocument extends BaseModel
{
    use HasDocumentNumber;

    public string $documentType = 'PR';

    public string $documentNumberColumn = 'doc_no';

    protected $table = 'test_documents';
}
