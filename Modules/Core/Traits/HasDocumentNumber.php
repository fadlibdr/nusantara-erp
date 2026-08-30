<?php

namespace Modules\Core\Traits;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Core\Services\DocumentNumberService;

/**
 * Auto-fills the document number on create.
 *
 * The model declares:  public string $documentType = 'PO';
 * Optionally override the target column: protected string $documentNumberColumn = 'code';
 *
 * P8 — {PROJ}: when the effective mask for the type carries the {PROJ} token,
 * the trait resolves the model's project (the conventional `project` belongsTo
 * that every project-bound document already declares) and passes its CODE as
 * the sequence scope. The resolution runs ONLY when the mask asks for it, so
 * every token-less type keeps minting with zero extra queries — and a {PROJ}
 * mask configured onto a model that has no project relation, or a document
 * whose project is not set, fails loudly at mint instead of rendering a blank.
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
                $service = app(DocumentNumberService::class);
                $type = $model->documentType;

                $scope = $service->requiresProjectScope($type)
                    ? static::documentProjectScope($model, $type)
                    : null;

                $model->{$column} = $service->next($type, $scope);
            }
        });
    }

    /**
     * The project code this document's number is scoped by. Both refusals are
     * configuration errors, not user errors: the {PROJ} token is opt-in per
     * type on the settings screen, and switching it on for a document that
     * cannot answer "proyek yang mana?" must stop the mint, not blank the code.
     */
    protected static function documentProjectScope(Model $model, string $type): string
    {
        if (! method_exists($model, 'project')) {
            throw new LogicException(sprintf(
                'Mask penomoran %s memakai token {PROJ}, tetapi dokumen %s tidak punya relasi proyek — '
                    .'hapus {PROJ} dari documents.%s di Pengaturan. Nomor tidak diterbitkan.',
                $type,
                class_basename($model),
                $type,
            ));
        }

        $code = $model->project?->code;

        if ($code === null || trim((string) $code) === '') {
            throw new LogicException(sprintf(
                'Mask penomoran %s memakai token {PROJ}, tetapi dokumen ini belum menunjuk proyek — '
                    .'isi proyeknya dulu, atau hapus {PROJ} dari documents.%s di Pengaturan. '
                    .'Nomor tidak diterbitkan.',
                $type,
                $type,
            ));
        }

        return trim((string) $code);
    }
}
