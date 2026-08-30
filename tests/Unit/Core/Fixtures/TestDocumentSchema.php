<?php

namespace Tests\Unit\Core\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Throwaway table backing the trait fixtures below. The Approvable and
 * HasDocumentNumber traits are generic, so they are exercised against a
 * purpose-built document instead of a real module model — a failure then
 * points at the trait and nothing else.
 */
trait TestDocumentSchema
{
    protected function createTestDocumentTable(): void
    {
        Schema::create('test_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->nullable();
            $table->string('doc_no', 40)->nullable();
            $table->string('title')->nullable();
            $table->string('status', 30)->default('draft');
            // P8 — {PROJ}: the conventional project pointer a scoped document carries.
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamps();
        });
    }
}
