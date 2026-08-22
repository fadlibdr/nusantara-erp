<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imported bank statements and their lines (header/detail in one migration, as
 * with fin_payments).
 *
 * A statement is a COPY OF WHAT THE BANK SAID. Nothing here posts to the ledger
 * and nothing here may be edited after import — a line is reconciling evidence,
 * and evidence you can retype is not evidence. Three constraints carry that:
 *
 *  - unique(content_hash) across ALL accounts, so the same file cannot be
 *    imported twice, and cannot be imported "again" against a different bank
 *    account, which would silently reconcile one bank with another's movements;
 *  - unique(matched_type, matched_id), so one payment (or one manual journal
 *    line) can be claimed by at most one statement line. Together with the
 *    single matched_type/matched_id column pair on the line — which makes the
 *    reverse structural — that is the one-economic-event-one-claim invariant;
 *  - unique(bank_statement_id, line_no), so lines are identified positionally.
 *    Identity is NOT taken from the line's content: three identical Rp 50 juta
 *    progress payments on one day are ordinary, and content-deduping lines
 *    would delete real money.
 *
 * NOTE FOR THE NEXT ENGINEER: docs/CONVENTIONS.md gives Finance the migration
 * block 001100–001199. This file takes 001198. One slot is left. A correction
 * migration for this feature must claim 001199 — do not spill into 001200, that
 * is ServiceDesk's block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();          // BST/{Y}/{RM}/{N4}
            $table->foreignId('bank_account_id')->constrained('fin_bank_accounts');
            $table->string('source_format', 20);           // mt940|csv
            $table->string('statement_ref', 64)->nullable();          // MT940 :20:
            $table->string('statement_no', 40)->nullable();           // MT940 :28C:
            $table->string('account_identification', 70)->nullable(); // MT940 :25:
            $table->char('currency', 3)->default('IDR');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 18, 2);
            $table->decimal('closing_balance', 18, 2);
            $table->unsignedInteger('line_count')->default(0);
            // sha256 of the normalised, envelope-stripped file text.
            $table->char('content_hash', 64)->unique();
            // How a CSV was read (delimiter, column map, formats) — the audit
            // trail for a parse the file itself does not describe. Null for MT940,
            // whose layout is the standard.
            $table->json('parse_options')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bank_account_id', 'period_start']);
        });

        Schema::create('fin_bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('fin_bank_statements')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->date('entry_date');                    // the date the bank booked it
            $table->date('value_date')->nullable();
            // The BANK's side of the movement: 'credit' is money into the account.
            // The GL sees the mirror — a bank credit is a debit to the bank COA.
            $table->string('direction', 10);
            $table->decimal('amount', 18, 2);              // always positive
            $table->text('description')->nullable();
            $table->string('customer_reference', 64)->nullable();
            $table->string('bank_reference', 64)->nullable();
            $table->string('transaction_code', 8)->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->text('raw_line')->nullable();
            $table->string('match_status', 20)->default('open'); // open|matched|no_match
            $table->string('matched_type', 20)->nullable();      // payment|journal_line
            $table->unsignedBigInteger('matched_id')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            // Why there is nothing to match: bank_charge|interest|unrecorded_receipt|
            // unrecorded_payment|bank_error|other. Classification only — it changes
            // the guidance shown, never the reconciliation arithmetic.
            $table->string('note_reason', 30)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['bank_statement_id', 'line_no']);
            $table->unique(['matched_type', 'matched_id']);
            $table->index('match_status');
            $table->index('entry_date');
        });

        // One COA account may back at most one bank account: the reconciliation
        // reads the ledger by COA, so two accounts sharing 1-1210 would each
        // claim the other's movements as timing differences and neither number
        // would look wrong.
        //
        // Deliberately NOT a unique index. fin_bank_accounts soft-deletes, and a
        // unique index cannot see that — a trashed account would hold its COA
        // for ever, so replacing a bank account would be impossible and the
        // failure would arrive as a constraint violation with no explanation.
        // The rule is enforced where soft deletes are visible instead:
        // BankAccountStoreRequest/BankAccountUpdateRequest refuse it at the
        // door, and BankReconciliationService::assertSoleOwnerOfCoaAccount()
        // refuses to report on an installation that already has one.
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_statement_lines');
        Schema::dropIfExists('fin_bank_statements');
    }
};
