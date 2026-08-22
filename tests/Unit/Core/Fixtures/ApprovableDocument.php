<?php

namespace Tests\Unit\Core\Fixtures;

use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;

/**
 * Minimal document carrying only what Approvable requires: a `status` column
 * cast to DocumentStatus and a `code` for the exception message.
 */
class ApprovableDocument extends BaseModel
{
    use Approvable;

    protected $table = 'test_documents';

    protected function casts(): array
    {
        return ['status' => DocumentStatus::class];
    }
}
