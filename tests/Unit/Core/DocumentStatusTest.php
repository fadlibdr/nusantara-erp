<?php

namespace Tests\Unit\Core;

use Modules\Core\Enums\DocumentStatus;
use Tests\ErpTestCase;

/**
 * The shared document status enum: its stored values, its Indonesian labels and
 * the editability rule every service leans on before mutating a document.
 */
class DocumentStatusTest extends ErpTestCase
{
    public function test_only_draft_and_rejected_are_editable(): void
    {
        $this->assertTrue(DocumentStatus::Draft->isEditable());
        $this->assertTrue(DocumentStatus::Rejected->isEditable());

        $this->assertFalse(DocumentStatus::Submitted->isEditable());
        $this->assertFalse(DocumentStatus::Approved->isEditable());
        $this->assertFalse(DocumentStatus::Closed->isEditable());
        $this->assertFalse(DocumentStatus::Cancelled->isEditable());
    }

    public function test_exactly_two_of_the_six_statuses_are_editable(): void
    {
        $editable = array_values(array_filter(
            DocumentStatus::cases(),
            fn (DocumentStatus $status): bool => $status->isEditable(),
        ));

        $this->assertSame([DocumentStatus::Draft, DocumentStatus::Rejected], $editable);
    }

    public function test_labels_are_indonesian(): void
    {
        $this->assertSame('Draf', DocumentStatus::Draft->label());
        $this->assertSame('Diajukan', DocumentStatus::Submitted->label());
        $this->assertSame('Disetujui', DocumentStatus::Approved->label());
        $this->assertSame('Ditolak', DocumentStatus::Rejected->label());
        $this->assertSame('Selesai', DocumentStatus::Closed->label());
        $this->assertSame('Dibatalkan', DocumentStatus::Cancelled->label());
    }

    public function test_the_stored_values_are_the_lower_case_english_names(): void
    {
        $this->assertSame(
            ['draft', 'submitted', 'approved', 'rejected', 'closed', 'cancelled'],
            array_column(DocumentStatus::cases(), 'value'),
        );
    }

    public function test_every_case_has_a_label(): void
    {
        foreach (DocumentStatus::cases() as $status) {
            $this->assertNotSame('', $status->label(), "Status [{$status->value}] has no label.");
        }
    }
}
