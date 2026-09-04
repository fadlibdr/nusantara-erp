<?php

namespace Tests\Feature\Finance;

use Tests\ErpTestCase;

/**
 * "Buat pembayaran" on an approved vendor bill (T3.1).
 *
 * The bill's approval is the last step anyone owns: BIL/2026/VII/0002
 * (Rp 48,5 jt) sat 69 days past due on production because "buat pembayaran"
 * was a memory, not a button (ANALISIS-PROSES-BISNIS-2026-09 §3, celah B1).
 * There is no JS runner in this repository, so the served text is pinned the
 * way ApprovalInboxGateTest pins the inbox predicate: the action must exist
 * on the ap-bills screen, open the payment form (not POST), and actions.js
 * must know how to open one.
 */
class ApBillPaymentButtonTest extends ErpTestCase
{
    public function test_an_approved_bill_offers_buat_pembayaran_that_opens_a_prefilled_payment(): void
    {
        $schema = $this->spa('schema.js');
        $start = strpos($schema, "'finance/ap-bills': {");
        $end = strpos($schema, "'finance/bank-accounts': {");
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $block = substr($schema, $start, $end - $start);

        $this->assertStringContainsString(
            "key: 'create-payment', label: 'Buat pembayaran', perm: 'fin.create'",
            $block,
            'Tombol berlabel kata kerjanya, hanya bagi pemegang fin.create — yang menyiapkan PAY.',
        );
        $this->assertStringContainsString("opens: 'finance/payments'", $block);
        $this->assertStringContainsString("when: (row) => row.status === 'approved' && Number(row.outstanding || 0) > 0", $block);
        $this->assertStringContainsString("direction: 'out'", $block);

        $this->assertStringContainsString(
            'if (action.opens) {',
            $this->spa('views/actions.js'),
            'actionButtons harus membuka formulir untuk aksi `opens`, bukan mengirim POST.',
        );
    }

    private function spa(string $file): string
    {
        return (string) file_get_contents(public_path('app/js/'.$file));
    }
}
