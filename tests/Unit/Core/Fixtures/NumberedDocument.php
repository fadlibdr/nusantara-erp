<?php

namespace Tests\Unit\Core\Fixtures;

use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * Numbered document using the default `code` column, like every module model
 * that declares a document type.
 */
class NumberedDocument extends BaseModel
{
    use HasDocumentNumber;

    public string $documentType = 'PO';

    protected $table = 'test_documents';
}
