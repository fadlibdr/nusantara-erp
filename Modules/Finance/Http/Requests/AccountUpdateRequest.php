<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Support\Money;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\NormalBalance;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;

class AccountUpdateRequest extends FormRequest
{
    /**
     * The four columns that stop being master data the moment the account
     * carries a journal line, each with its refusal clause. The field names in
     * those clauses are the labels the edit form uses (public/app/js/schema.js
     * 'finance/accounts'), so the 422 names the control the operator is
     * looking at.
     */
    private const SEALED_ONCE_POSTED = [
        'code' => 'Kode akun tidak dapat diubah lagi',
        'account_type' => 'Tipe akun tidak dapat diubah lagi',
        'normal_balance' => 'Saldo normal tidak dapat diubah lagi',
        'is_postable' => 'Dapat diposting tidak dapat dimatikan lagi',
    ];

    public function authorize(): bool
    {
        return true; // permission:fin.update guards the route
    }

    public function rules(): array
    {
        $accountId = $this->route('account')?->id;

        return [
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('fin_accounts', 'code')->ignore($accountId)],
            'name' => ['sometimes', 'string', 'max:150'],
            'account_type' => ['sometimes', Rule::enum(AccountType::class)],
            'parent_id' => ['nullable', 'integer', Rule::exists('fin_accounts', 'id'), Rule::notIn([$accountId])],
            'is_postable' => ['boolean'],
            'normal_balance' => ['sometimes', Rule::enum(NormalBalance::class)],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->rejectMovingAnAccountThatCarriesHistory($validator);
        });
    }

    /**
     * The chart of accounts is editable underneath posted history, and the
     * reports read the chart rather than the ledger — so a master-data edit
     * can move or hide money nobody touched.
     *
     * ReportService::trialBalance(), profitLoss() and balanceSheet() all
     * iterate `Account::where('is_postable', true)` while sumsPerAccount()
     * sums every posted line: an account_id present in the sums but absent
     * from the loop is dropped from the rows AND from the totals. Ticking
     * "Dapat diposting" off 1-1220 Bank Mandiri Proyek on the demo dataset
     * takes Rp 10.767.000.000 of bank cash out of the balance sheet (assets
     * 10.890.010.000 -> 123.010.000) with the ledger untouched and still
     * balanced to the rupiah, and PeriodCloseService's `trial_balance_balanced`
     * item is a BLOCK with no acknowledge path — so that month, and because
     * closes run oldest-first every month after it, cannot be closed.
     *
     * The account_type half is the quieter and worse one: flipping 1-1220 from
     * asset to expense moved the same Rp 10.767.000.000 from the balance sheet
     * to the P&L with BOTH `balanced` flags still true and the close checklist
     * still clean — a misstatement with no alarm anywhere. And a code rename
     * breaks every service that resolves an account by its code, e.g.
     * ArInvoiceService::approve() dying on "COA account 1-1300 does not exist".
     *
     * This is the rule the codebase already applies at the three other points
     * an account can move — AccountController::destroy() refuses to delete an
     * account with journal lines, UpdateSettingsRequest::
     * rejectRepointingAnAccountInUse() refuses to repoint a settings account
     * that still carries a balance, and migration 2026_08_01_001109 flips
     * 1-1100 only when no journal line ever posted to it — so its absence here
     * was an omission, not a policy.
     *
     * Only a REAL change is refused. The edit form submits every field on
     * every save, so comparing against the stored value is what keeps renaming
     * an account, re-parenting it or deactivating it possible for as long as
     * it lives.
     */
    private function rejectMovingAnAccountThatCarriesHistory(Validator $validator): void
    {
        $account = $this->route('account');

        if (! $account instanceof Account) {
            return;
        }

        // The same test destroy() applies: ANY journal line, not only posted
        // ones. A draft line is a posted line the moment somebody presses
        // Posting, and post() re-checks the balance and the period but never
        // re-checks that the account is still postable.
        $lines = $account->journalLines()->count();

        if ($lines === 0) {
            return;
        }

        $carries = $this->historyPhrase($account, $lines);

        foreach (self::SEALED_ONCE_POSTED as $field => $refusal) {
            // A field the request never mentioned cannot be a change, and a
            // field that already failed its own rule gets one message, not two.
            if (! $this->has($field) || $validator->errors()->has($field)) {
                continue;
            }

            if (! $this->wouldMoveHistory($account, $field)) {
                continue;
            }

            $validator->errors()->add($field, sprintf(
                'Akun %s %s %s, sehingga %s. %s Buat akun baru lalu pindahkan saldonya lewat jurnal '
                    .'jika bagan akun memang harus berubah.',
                $account->code,
                $account->name,
                $carries,
                $refusal,
                $this->consequence($field),
            ));
        }
    }

    /**
     * What the operator is about to move, in their own units: the posted
     * balance when there is one, otherwise the number of journal lines (a
     * chart edited while the only journals against it are still drafts).
     */
    private function historyPhrase(Account $account, int $lines): string
    {
        $balance = $this->postedBalance($account);

        return $balance === 0.0
            ? "sudah dipakai pada {$lines} baris jurnal"
            : 'sudah memuat saldo terposting '.Money::format(abs($balance));
    }

    /**
     * Signed balance of the account over posted, non-deleted journals.
     */
    private function postedBalance(Account $account): float
    {
        $row = $account->journalLines()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->whereNull('fin_journals.deleted_at')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) as debit, COALESCE(SUM(fin_journal_lines.credit), 0) as credit')
            ->first();

        return round((float) $row->debit - (float) $row->credit, 2);
    }

    /**
     * Whether the submitted value really differs from the stored one, compared
     * on the same basis the column is cast to: the booleans through
     * boolean() (the SPA sends 0/1, the API may send true/false) and the enums
     * through their backing value.
     */
    private function wouldMoveHistory(Account $account, string $field): bool
    {
        if ($field === 'is_postable') {
            // Only the direction that HIDES the balance is refused. An account
            // that carries lines while not postable is already the broken
            // state — its balance is invisible to trial balance, neraca and
            // laba rugi alike — so ticking the box back ON is the repair for a
            // flip made before this guard existed, not a new hazard. Refusing
            // it too would leave a wrongly-flipped 1-1220 unrecoverable through
            // the UI and would need database surgery to undo.
            return (bool) $account->is_postable && ! $this->boolean('is_postable');
        }

        $current = match ($field) {
            'account_type' => $account->account_type?->value,
            'normal_balance' => $account->normal_balance?->value,
            default => (string) $account->code,
        };

        return trim((string) $this->input($field)) !== (string) $current;
    }

    private function consequence(string $field): string
    {
        return match ($field) {
            'is_postable' => 'Neraca saldo dan neraca hanya memuat akun yang dapat diposting, sehingga '
                .'saldo itu hilang dari kedua laporan padahal buku besarnya utuh, dan tutup periode '
                .'terkunci pada penghalang "neraca saldo tidak seimbang" yang tidak dapat di-override.',
            'account_type' => 'Tipe akun menentukan laporan tempat saldo muncul, sehingga mengubahnya '
                .'memindahkan saldo itu antara neraca dan laba rugi tanpa satu pun penanda "seimbang" '
                .'ikut berubah — salah saji yang tidak memicu peringatan di mana pun.',
            'normal_balance' => 'Saldo normal menentukan tanda saldo akun di setiap laporan, sehingga '
                .'membaliknya membalik penyajian seluruh riwayat yang sudah terposting.',
            default => 'Mesin pemosting mencari akun lewat kodenya — ArInvoiceService::approve() memakai '
                .'1-1300 — sehingga penggantian kode menggagalkan pemostingan berikutnya dengan '
                .'"COA account 1-1300 does not exist".',
        };
    }
}
