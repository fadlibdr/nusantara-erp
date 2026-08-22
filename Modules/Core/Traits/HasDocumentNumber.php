<?php

namespace Modules\Core\Traits;

use Modules\Core\Services\DocumentNumberService;

/**
 * Auto-fills the document number on create.
 *
 * The model declares:  public string $documentType = 'PO';
 * Optionally override the target column: protected string $documentNumberColumn = 'code';
 */
trait HasDocumentNumber
{
    public static function bootHasDocumentNumber(): void
    {
        static::creating(function ($model): void {
            $column = property_exists($model, 'documentNumberColumn')
                ? $model->documentNumberColumn
                : 'code';

            if (empty($model->{$column}) && property_exists($model, 'documentType')) {
                $model->{$column} = app(DocumentNumberService::class)->next($model->documentType);
            }
        });
    }
}
