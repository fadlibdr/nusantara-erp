<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Services\BankStatementImportService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Importing a bank statement.
 *
 * The property under test throughout is that a file which is WRONG is refused,
 * not imported. A parser that drops a transaction produces a statement whose
 * opening, lines and closing are mutually consistent and short by one movement,
 * and nothing downstream can ever tell — the reconciliation simply reports the
 * missing amount as a timing difference, forever.
 */
class BankStatementImportTest extends ErpTestCase
{
    use FinanceFixtures;

    private BankStatementImportService $imports;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->imports = app(BankStatementImportService::class);
        $this->bank = $this->makeBankAccount('1-1210');
    }

    // --------------------------------------------------------------- MT940

    private function mt940(string $lines, string $opening = 'C260301IDR1000000000,00', string $closing = 'C260331IDR1200000000,00'): string
    {
        return implode("\n", [
            ':20:STMT260331',
            ':25:BCA/1234567890',
            ':28C:00003/001',
            ':60F:'.$opening,
            $lines,
            ':62F:'.$closing,
        ]);
    }

    public function test_a_statement_that_ties_out_is_imported_with_its_lines(): void
    {
        $statement = $this->imports->import($this->bank, 'mt940', $this->mt940(implode("\n", [
            ':61:2603100310C150000000,00NTRFINV-1//BCA0001',
            ':86:Transfer masuk PT Graha Sentosa',
            ':61:2603150315D50000000,00NTRFPAY-1//BCA0002',
            ':86:Pembayaran vendor',
            ':61:2603200320C100000000,00NTRFINV-2//BCA0003',
            ':86:Transfer masuk termin 2',
        ])));

        $this->assertSame(3, $statement->line_count);
        $this->assertSame('1000000000.00', $statement->opening_balance);
        $this->assertSame('1200000000.00', $statement->closing_balance);
        $this->assertStringStartsWith('BST/2026/', $statement->code);

        $first = $statement->lines->first();
        $this->assertSame('credit', $first->direction->value);
        $this->assertSame('150000000.00', $first->amount);
        $this->assertSame('2026-03-10', $first->entry_date->toDateString());
        $this->assertSame('INV-1', $first->customer_reference);
        $this->assertSame('BCA0001', $first->bank_reference);
        $this->assertStringContainsString('Graha Sentosa', $first->description);
    }

    /**
     * The whole point of the tie-out: a file missing one movement is internally
     * plausible and externally wrong.
     */
    public function test_a_file_whose_balances_do_not_match_its_movements_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak seimbang/');

        $this->imports->import($this->bank, 'mt940', $this->mt940(
            ':61:2603100310C150000000,00NTRFINV-1//BCA0001'
        ));
    }

    public function test_the_refusal_names_the_amount_that_is_missing(): void
    {
        $preview = $this->imports->preview($this->bank, 'mt940', $this->mt940(
            ':61:2603100310C150000000,00NTRFINV-1//BCA0001'
        ));

        $this->assertFalse($preview['can_import']);
        $this->assertStringContainsString('Rp -50.000.000,00', $preview['blockers'][0]);
    }

    /**
     * A delivery file holds one {4:…-} block per statement. Reading only the
     * first gives a result that ties out and is missing every other day.
     */
    public function test_every_application_block_in_a_batch_file_is_read(): void
    {
        $batch = '{1:F01BCAXIDJAXXX}{2:O940}{4:'."\n"
            .implode("\n", [
                ':20:DAY1',
                ':25:BCA/1234567890',
                ':28C:00001/001',
                ':60F:C260301IDR1000000000,00',
                ':61:2603010301C100000000,00NTRFA//R1',
                ':62F:C260301IDR1100000000,00',
            ])."\n-}\n"
            .'{1:F01BCAXIDJAXXX}{2:O940}{4:'."\n"
            .implode("\n", [
                ':20:DAY2',
                ':25:BCA/1234567890',
                ':28C:00002/001',
                ':60F:C260302IDR1100000000,00',
                ':61:2603020302C200000000,00NTRFB//R2',
                ':62F:C260302IDR1300000000,00',
            ])."\n-}";

        $statement = $this->imports->import($this->bank, 'mt940', $batch);

        $this->assertSame(2, $statement->line_count);
        $this->assertSame('1000000000.00', $statement->opening_balance);
        $this->assertSame('1300000000.00', $statement->closing_balance);
    }

    /**
     * Pages 1..2 of a 3-page statement chain perfectly and tie out. ":62M:" is
     * the only thing that says page 3 exists.
     */
    public function test_a_statement_truncated_before_its_final_page_is_refused(): void
    {
        $truncated = implode("\n", [
            ':20:STMT', ':25:BCA/1234567890', ':28C:00003/001',
            ':60F:C260301IDR1000000000,00',
            ':61:2603010301C100000000,00NTRFA//R1',
            ':62M:C260301IDR1100000000,00',
            ':20:STMT', ':25:BCA/1234567890', ':28C:00003/002',
            ':60M:C260301IDR1100000000,00',
            ':61:2603020302C100000000,00NTRFB//R2',
            ':62M:C260302IDR1200000000,00',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/terpotong/');

        $this->imports->import($this->bank, 'mt940', $truncated);
    }

    /**
     * :86: describes the :61: it follows. Banks also emit a statement-level
     * :86:, and they put it at either end. Pairing by COUNT rather than by
     * position means a trailing one shifts every description onto the next
     * movement — no error, no count mismatch, and the operator matches
     * transactions against other transactions' narratives.
     */
    public function test_a_statement_level_description_at_the_end_does_not_shift_the_others(): void
    {
        $statement = $this->imports->import($this->bank, 'mt940', implode("\n", [
            ':20:STMT', ':25:BCA/1234567890', ':28C:00003/001',
            ':60F:C260301IDR1000000000,00',
            ':61:2603100310C150000000,00NTRFA//R1',
            ':86:TRANSFER MASUK GRAHA SENTOSA',
            ':61:2603150315C50000000,00NTRFB//R2',
            ':86:TRANSFER MASUK BANK ARTHA',
            ':62F:C260331IDR1200000000,00',
            ':86:REKENING KORAN MARET 2026 - PT NUSANTARA KARYA INTEGRASI',
        ]));

        $this->assertStringContainsString('GRAHA SENTOSA', $statement->lines[0]->description);
        $this->assertStringContainsString('BANK ARTHA', $statement->lines[1]->description);
        $this->assertStringNotContainsString('REKENING KORAN MARET', (string) $statement->lines[1]->description);
    }

    public function test_a_statement_level_description_at_the_start_is_not_attached_to_a_movement(): void
    {
        $statement = $this->imports->import($this->bank, 'mt940', implode("\n", [
            ':20:STMT', ':25:BCA/1234567890', ':28C:00003/001',
            ':60F:C260301IDR1000000000,00',
            ':86:REKENING KORAN MARET 2026',
            ':61:2603100310C200000000,00NTRFA//R1',
            ':86:TRANSFER MASUK GRAHA SENTOSA',
            ':62F:C260331IDR1200000000,00',
        ]));

        $this->assertStringContainsString('GRAHA SENTOSA', $statement->lines[0]->description);
        $this->assertStringNotContainsString('REKENING KORAN', (string) $statement->lines[0]->description);
    }

    /**
     * An application block whose "-}" terminator was lost in transit matches
     * nothing, so it would simply disappear — leaving a file that ties out and
     * is missing a day.
     */
    public function test_an_unterminated_application_block_is_refused(): void
    {
        $truncated = '{1:F01BCAXIDJAXXX}{2:O940}{4:'."\n"
            .implode("\n", [
                ':20:DAY1', ':25:BCA/1234567890', ':28C:00001/001',
                ':60F:C260301IDR1000000000,00',
                ':61:2603010301C100000000,00NTRFA//R1',
                ':62F:C260301IDR1100000000,00',
            ])."\n-}\n"
            .'{1:F01BCAXIDJAXXX}{2:O940}{4:'."\n"
            .implode("\n", [
                ':20:DAY2', ':25:BCA/1234567890', ':28C:00002/001',
                ':60F:C260302IDR1100000000,00',
                ':61:2603020302C200000000,00NTRFB//R2',
                ':62F:C260302IDR1300000000,00',
            ]);   // no closing -}

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/terpotong saat dikirim/');

        $this->imports->import($this->bank, 'mt940', $truncated);
    }

    /**
     * Sorting a delivery file by the page part of :28C: alone interleaves page 1
     * of every statement with page 1 of every other, and the balance chain then
     * refuses a perfectly good file.
     */
    public function test_several_multi_page_statements_in_one_file_keep_their_order(): void
    {
        $statement = $this->imports->import($this->bank, 'mt940', implode("\n", [
            ':20:S1', ':25:BCA/1234567890', ':28C:00001/001',
            ':60F:C260301IDR0,00', ':61:2603010301C100000000,00NTRFA//R1', ':62M:C260301IDR100000000,00',
            ':20:S1', ':25:BCA/1234567890', ':28C:00001/002',
            ':60M:C260301IDR100000000,00', ':61:2603020302C100000000,00NTRFB//R2', ':62F:C260302IDR200000000,00',
            ':20:S2', ':25:BCA/1234567890', ':28C:00002/001',
            ':60F:C260303IDR200000000,00', ':61:2603030303C100000000,00NTRFC//R3', ':62M:C260303IDR300000000,00',
            ':20:S2', ':25:BCA/1234567890', ':28C:00002/002',
            ':60M:C260303IDR300000000,00', ':61:2603040304C100000000,00NTRFD//R4', ':62F:C260304IDR400000000,00',
        ]));

        $this->assertSame(4, $statement->line_count);
        $this->assertSame('400000000.00', $statement->closing_balance);
    }

    public function test_the_same_message_appearing_twice_in_one_file_is_refused(): void
    {
        $page = [
            ':20:S1', ':25:BCA/1234567890', ':28C:00001/001',
            ':60F:C260301IDR1000000,00',
            ':61:2603010301C500000,00NTRFA//R1',
            ':61:2603010301D500000,00NTRFB//R2',
            ':62F:C260301IDR1000000,00',
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/dua kali/');

        $this->imports->import($this->bank, 'mt940', implode("\n", array_merge($page, $page)));
    }

    public function test_a_file_holding_two_accounts_is_refused_and_names_them(): void
    {
        $twoAccounts = implode("\n", [
            ':20:A', ':25:BCA/1234567890', ':28C:00001/001',
            ':60F:C260301IDR0,00', ':61:2603010301C1000,00NTRFA//R1', ':62F:C260301IDR1000,00',
            ':20:B', ':25:BCA/9999999999', ':28C:00002/001',
            ':60F:C260301IDR0,00', ':61:2603010301C1000,00NTRFB//R2', ':62F:C260301IDR1000,00',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/lebih dari satu rekening/');

        $this->imports->import($this->bank, 'mt940', $twoAccounts);
    }

    /**
     * RC reverses a credit, so its effect on the account is a DEBIT. Getting
     * this backwards moves money the wrong way on the lines nobody checks.
     */
    public function test_a_reversal_moves_money_in_the_opposite_direction(): void
    {
        $statement = $this->imports->import($this->bank, 'mt940', $this->mt940(
            implode("\n", [
                ':61:2603100310C300000000,00NTRFA//R1',
                ':61:2603110311RC100000000,00NTRFB//R2',
            ]),
            'C260301IDR1000000000,00',
            'C260331IDR1200000000,00',
        ));

        $reversal = $statement->lines->last();
        $this->assertSame('debit', $reversal->direction->value);
        $this->assertTrue($reversal->is_reversal);
    }

    /**
     * NONREF//6127001795151001 — a greedy 16-character match on subfield 7
     * swallows the separator, returning "NONREF//61270017" and no bank
     * reference. No error; just the loss of the best matching signal there is.
     */
    public function test_the_bank_reference_survives_a_long_reference_pair(): void
    {
        $statement = $this->imports->import($this->bank, 'mt940', $this->mt940(
            ':61:2603100310C200000000,00NTRFNONREF//6127001795151001',
            'C260301IDR1000000000,00',
            'C260331IDR1200000000,00',
        ));

        $line = $statement->lines->first();
        $this->assertSame('NONREF', $line->customer_reference);
        $this->assertSame('6127001795151001', $line->bank_reference);
    }

    public function test_a_foreign_currency_statement_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/mata uang USD/');

        $this->imports->import($this->bank, 'mt940', $this->mt940(
            ':61:2603100310C200000000,00NTRFA//R1',
            'C260301USD1000000000,00',
            'C260331USD1200000000,00',
        ));
    }

    /**
     * SWIFT constrains only the first two characters of the currency code
     * between the opening and closing balance, so a file can legally carry two
     * — and its arithmetic would then be adding different money together.
     */
    public function test_a_statement_whose_balances_use_two_currencies_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/lebih dari satu mata uang/');

        $this->imports->import($this->bank, 'mt940', $this->mt940(
            ':61:2603100310C200000000,00NTRFA//R1',
            'C260301IDR1000000000,00',
            'C260331USD1200000000,00',
        ));
    }

    public function test_an_interim_mt942_report_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/MT942/');

        $this->imports->import($this->bank, 'mt940', implode("\n", [
            ':20:INTRADAY', ':25:BCA/1234567890', ':28C:00001',
            ':34F:IDRD0,00', ':13D:2603101200+0700',
            ':61:2603100310C200000000,00NTRFA//R1',
        ]));
    }

    // ------------------------------------------------------------- identity

    public function test_the_same_file_cannot_be_imported_twice(): void
    {
        $file = $this->mt940(':61:2603100310C200000000,00NTRFA//R1', 'C260301IDR1000000000,00', 'C260331IDR1200000000,00');
        $this->imports->import($this->bank, 'mt940', $file);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah diimpor/');

        $this->imports->import($this->bank, 'mt940', $file);
    }

    /**
     * The same statement arriving over two channels differs only in the SWIFT
     * envelope and line endings — bytes the parser discards. If identity were
     * taken over raw bytes, the retry an idempotency guarantee exists to stop
     * would sail through.
     */
    public function test_the_same_statement_wrapped_differently_is_still_a_duplicate(): void
    {
        $plain = $this->mt940(':61:2603100310C200000000,00NTRFA//R1', 'C260301IDR1000000000,00', 'C260331IDR1200000000,00');
        $wrapped = "{1:F01BCAXIDJAXXX0000000000}{2:O9401200}{4:\r\n".str_replace("\n", "\r\n", $plain)."\r\n-}";

        $this->imports->import($this->bank, 'mt940', $plain);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah diimpor/');

        $this->imports->import($this->bank, 'mt940', $wrapped);
    }

    /**
     * Choosing the wrong bank account on a second attempt is the ordinary
     * mistake, and it reconciles one bank against another bank's movements.
     */
    public function test_the_same_file_cannot_be_imported_against_a_different_account(): void
    {
        $other = $this->makeBankAccount('1-1220', ['name' => 'Mandiri Proyek']);
        $file = $this->mt940(':61:2603100310C200000000,00NTRFA//R1', 'C260301IDR1000000000,00', 'C260331IDR1200000000,00');

        $this->imports->import($this->bank, 'mt940', $file);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Periksa rekening yang Anda pilih/');

        $this->imports->import($other, 'mt940', $file);
    }

    // ---------------------------------------------------------------- chain

    public function test_a_statement_overlapping_an_imported_period_is_refused(): void
    {
        $this->importMonth('2026-03-01', '2026-03-31', 0, 100_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/bertumpang tindih/');

        $this->importMonth('2026-03-15', '2026-04-15', 50_000_000, 150_000_000);
    }

    /**
     * A gap between statements is not a missing statement, it is a hole in the
     * reconciliation: the movements in between exist in the ledger and nowhere
     * on the bank side, so they surface as an unexplained difference nobody can
     * clear. The balances not chaining is what proves the gap.
     */
    public function test_a_statement_that_does_not_continue_the_previous_balance_is_refused(): void
    {
        $this->importMonth('2026-03-01', '2026-03-31', 0, 100_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/belum diimpor di antaranya/');

        $this->importMonth('2026-05-01', '2026-05-31', 250_000_000, 300_000_000);
    }

    public function test_consecutive_statements_that_chain_are_accepted(): void
    {
        $this->importMonth('2026-03-01', '2026-03-31', 0, 100_000_000);
        $this->importMonth('2026-04-01', '2026-04-30', 100_000_000, 175_000_000);

        $this->assertSame(2, BankStatement::query()->count());
    }

    /**
     * Backfilling an earlier period is legitimate, and the chain is checked in
     * both directions so it cannot be used to sneak a gap in.
     */
    public function test_an_earlier_statement_must_chain_into_the_one_already_imported(): void
    {
        $this->importMonth('2026-04-01', '2026-04-30', 100_000_000, 175_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/belum diimpor di antaranya/');

        $this->importMonth('2026-03-01', '2026-03-31', 0, 90_000_000);
    }

    // ---------------------------------------------------------------- CSV

    private function csvMapping(array $overrides = []): array
    {
        return array_merge([
            'delimiter' => ';',
            'skip_rows' => 1,
            'date_column' => 0,
            'date_format' => 'dd/mm/yyyy',
            'description_column' => 1,
            'amount_mode' => 'debit_credit',
            'debit_column' => 2,
            'credit_column' => 3,
            'number_format' => 'id',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'opening_balance' => 1_000_000_000,
            'closing_balance' => 1_200_000_000,
        ], $overrides);
    }

    public function test_a_csv_with_separate_debit_and_credit_columns_imports(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit;Saldo',
            '10/03/2026;TRSF E-BANKING CR PT GRAHA;;250.000.000,00;1.250.000.000,00',
            '15/03/2026;BIAYA ADM;50.000.000,00;;1.200.000.000,00',
        ]);

        $statement = $this->imports->import($this->bank, 'csv', $csv, $this->csvMapping());

        $this->assertSame(2, $statement->line_count);
        $this->assertSame('credit', $statement->lines[0]->direction->value);
        $this->assertSame('250000000.00', $statement->lines[0]->amount);
        $this->assertSame('debit', $statement->lines[1]->direction->value);
        $this->assertSame('2026-03-15', $statement->lines[1]->entry_date->toDateString());
    }

    /**
     * The operator types both endpoints for a CSV, so "opening + movements =
     * closing" can be satisfied by adjusting an endpoint. The bank's own Saldo
     * column is the only independent check, and it names the row.
     */
    public function test_a_mapped_balance_column_catches_a_dropped_row_at_its_own_line(): void
    {
        // Row 3's saldo assumes a movement that is not in the file.
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit;Saldo',
            '10/03/2026;TRSF MASUK;;250.000.000,00;1.250.000.000,00',
            '15/03/2026;BIAYA ADM;50.000.000,00;;1.150.000.000,00',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Baris 3: saldo berjalan tidak cocok/');

        $this->imports->import($this->bank, 'csv', $csv, $this->csvMapping([
            'balance_column' => 4,
            'closing_balance' => 1_150_000_000,
        ]));
    }

    public function test_without_a_balance_column_the_operator_is_told_the_check_is_weaker(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit',
            '10/03/2026;TRSF MASUK;;250.000.000,00',
            '15/03/2026;BIAYA ADM;50.000.000,00;',
        ]);

        $preview = $this->imports->preview($this->bank, 'csv', $csv, $this->csvMapping());

        $this->assertTrue($preview['can_import']);
        $this->assertStringContainsString('Anda ketik sendiri', implode(' ', $preview['statement']['warnings']));
    }

    /**
     * "500.000,00 DB" in one cell: the marker has to come off before the number
     * parser runs, or the documented BCA layout is refused by its own preset.
     */
    public function test_an_indicator_inside_the_amount_cell_is_understood(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Mutasi',
            '10/03/2026;TRSF MASUK;250.000.000,00 CR',
            '15/03/2026;BIAYA ADM;50.000.000,00 DB',
        ]);

        $statement = $this->imports->import($this->bank, 'csv', $csv, $this->csvMapping([
            'amount_mode' => 'single_with_indicator',
            'amount_column' => 2,
            'debit_column' => null,
            'credit_column' => null,
        ]));

        $this->assertSame('credit', $statement->lines[0]->direction->value);
        $this->assertSame('debit', $statement->lines[1]->direction->value);
    }

    public function test_a_wrapped_description_is_appended_not_treated_as_a_movement(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit',
            '10/03/2026;TRSF E-BANKING CR;;250.000.000,00',
            ';PT GRAHA SENTOSA PROPERTINDO;;',
            '15/03/2026;BIAYA ADM;50.000.000,00;',
        ]);

        $statement = $this->imports->import($this->bank, 'csv', $csv, $this->csvMapping());

        $this->assertSame(2, $statement->line_count);
        $this->assertStringContainsString('PT GRAHA SENTOSA', $statement->lines[0]->description);
    }

    /**
     * 31/12 on a January statement belongs to the previous year. Deriving it
     * from the declared period is deterministic; a "did the date jump backwards"
     * heuristic gets this exact row wrong.
     */
    public function test_a_dd_mm_date_takes_the_year_that_falls_inside_the_period(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit',
            '31/12;SALDO AWAL TRANSFER;;100.000.000,00',
            '05/01;BIAYA ADM;100.000.000,00;',
        ]);

        $statement = $this->imports->import($this->bank, 'csv', $csv, $this->csvMapping([
            'date_format' => 'dd/mm',
            'period_start' => '2025-12-28',
            'period_end' => '2026-01-27',
            'opening_balance' => 1_000_000_000,
            'closing_balance' => 1_000_000_000,
        ]));

        $this->assertSame('2025-12-31', $statement->lines[0]->entry_date->toDateString());
        $this->assertSame('2026-01-05', $statement->lines[1]->entry_date->toDateString());
    }

    public function test_an_unparseable_amount_is_refused_rather_than_guessed(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit',
            '10/03/2026;TRSF MASUK;;250,000,000.00',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/bukan angka dalam format Indonesia/');

        $this->imports->import($this->bank, 'csv', $csv, $this->csvMapping());
    }

    public function test_an_unknown_delimiter_is_a_domain_refusal_not_a_crash(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Pemisah kolom/');

        $this->imports->import($this->bank, 'csv', "a\tb", $this->csvMapping(['delimiter' => '\t']));
    }

    public function test_a_statement_for_another_account_number_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sedangkan yang Anda pilih/');

        $this->imports->import($this->bank, 'mt940', implode("\n", [
            ':20:STMT', ':25:BCA/9988776655', ':28C:00001/001',
            ':60F:C260301IDR0,00',
            ':61:2603100310C200000000,00NTRFA//R1',
            ':62F:C260331IDR200000000,00',
        ]));
    }

    /**
     * Banks prefix the number with a BIC or branch code and pad it differently
     * between channels, so the check compares digits by containment — an
     * equality test would refuse almost every real file.
     */
    public function test_a_bic_prefixed_account_identification_still_matches(): void
    {
        $statement = $this->imports->import($this->bank, 'mt940', implode("\n", [
            ':20:STMT', ':25:CENAIDJA/0001234567890', ':28C:00001/001',
            ':60F:C260301IDR0,00',
            ':61:2603100310C200000000,00NTRFA//R1',
            ':62F:C260331IDR200000000,00',
        ]));

        $this->assertSame(1, $statement->line_count);
    }

    /**
     * A CSV amount long enough to overflow the cents arithmetic must be a
     * message about the column mapping, not a TypeError leaving the controller
     * as an HTTP 500.
     */
    public function test_an_absurdly_long_number_is_refused_with_a_message(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit',
            '10/03/2026;SALAH KOLOM;;12345678901234567890',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/terlalu besar/');

        $this->imports->import($this->bank, 'csv', $csv, $this->csvMapping());
    }

    /** 31/02 matches the pattern and is not a date; Eloquent would roll it into March. */
    public function test_a_date_that_does_not_exist_is_refused(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit',
            '31/02/2026;TANGGAL MUSTAHIL;;250.000.000,00',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/bukan tanggal yang ada/');

        $this->imports->import($this->bank, 'csv', $csv, $this->csvMapping());
    }

    public function test_a_balance_column_that_is_blank_everywhere_says_so(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit;Catatan',
            '10/03/2026;TRSF MASUK;;250.000.000,00;',
            '15/03/2026;BIAYA ADM;50.000.000,00;;',
        ]);

        $preview = $this->imports->preview($this->bank, 'csv', $csv, $this->csvMapping(['balance_column' => 4]));

        $this->assertStringContainsString('kosong pada semua baris', implode(' ', $preview['statement']['warnings']));
    }

    /** An overdrawn running balance is written with a marker, not only a sign. */
    public function test_a_debit_marked_running_balance_is_read_as_negative(): void
    {
        $csv = implode("\n", [
            'Tanggal;Keterangan;Debit;Kredit;Saldo',
            '10/03/2026;PEMBAYARAN VENDOR;300.000.000,00;;200.000.000,00 DB',
        ]);

        $statement = $this->imports->import($this->bank, 'csv', $csv, $this->csvMapping([
            'balance_column' => 4,
            'opening_balance' => 100_000_000,
            'closing_balance' => -200_000_000,
        ]));

        $this->assertSame(1, $statement->line_count);
        $this->assertSame('-200000000.00', $statement->closing_balance);
    }

    // --------------------------------------------------------------- delete

    /**
     * Deleting from the middle of the chain re-creates exactly the gap import()
     * refuses to accept.
     */
    public function test_only_the_newest_statement_of_an_account_can_be_deleted(): void
    {
        $march = $this->importMonth('2026-03-01', '2026-03-31', 0, 100_000_000);
        $this->importMonth('2026-04-01', '2026-04-30', 100_000_000, 175_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/bukan yang terbaru/');

        $this->imports->delete($march);
    }

    public function test_a_statement_with_no_matches_can_be_deleted_and_re_imported(): void
    {
        $file = $this->mt940(':61:2603100310C200000000,00NTRFA//R1', 'C260301IDR1000000000,00', 'C260331IDR1200000000,00');
        $statement = $this->imports->import($this->bank, 'mt940', $file);

        $this->imports->delete($statement);

        $this->assertSame(0, BankStatement::query()->count());
        $this->assertSame(1, $this->imports->import($this->bank, 'mt940', $file)->line_count);
    }

    // -------------------------------------------------------------- helpers

    private function importMonth(string $start, string $end, float $opening, float $closing): BankStatement
    {
        $movement = $closing - $opening;
        $direction = $movement >= 0 ? 'C' : 'D';
        $amount = number_format(abs($movement), 2, ',', '');

        $body = implode("\n", [
            ':20:STMT'.str_replace('-', '', $start),
            ':25:BCA/1234567890',
            ':28C:00001/001',
            ':60F:C'.$this->yymmdd($start).'IDR'.number_format($opening, 2, ',', '').'',
            ':61:'.$this->yymmdd($start).$direction.$amount.'NTRFREF'.$start.'//B'.$start,
            ':62F:C'.$this->yymmdd($end).'IDR'.number_format($closing, 2, ',', ''),
        ]);

        return $this->imports->import($this->bank, 'mt940', $body);
    }

    private function yymmdd(string $date): string
    {
        return date('ymd', strtotime($date));
    }
}
