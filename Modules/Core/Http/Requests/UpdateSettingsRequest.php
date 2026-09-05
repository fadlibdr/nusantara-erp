<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Validator;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\Money;

/**
 * Bulk update of ERP parameters.
 *
 * The body is a flat map of dotted setting keys to values:
 * {"settings": {"tax.ppn_rate": 12, "payroll.overtime.divisor": null}}.
 * A null value resets that parameter to its config/erp.php default.
 *
 * The per-key rules come straight from the registry, so the API can never drift
 * from the settings screen. Two things the registry cannot express are handled
 * here: unknown keys must be rejected (Laravel silently drops unruled array
 * members), and an "account" value must resolve to a postable row of the chart
 * of accounts — a check Core cannot make itself, because Finance is optional and
 * sits below Core in the dependency graph.
 *
 * This class is the UX layer, not the safety net. SettingService::set() applies
 * the same registry rules to every write and throws InvalidArgumentException on
 * a violation, so a value cannot reach core_settings by going around the
 * controller. What is added here is Indonesian, field-attributed messages an
 * operator can act on, plus the one cross-module check above.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission:core.update guards the route
    }

    /**
     * Registry-derived rules. Keys are escaped ("settings.tax\.ppn_rate")
     * because the setting keys themselves contain dots.
     */
    public function rules(): array
    {
        return $this->settings()->validationRules();
    }

    /**
     * Rule messages are generic per rule: the registry holds dozens of keys, and
     * the attribute name below already tells the operator which one failed.
     *
     * 'regex' is the exception worth spelling out. It is used by exactly one type
     * in the registry — document_format — and "format tidak cocok" would tell an
     * operator nothing about why 'PO-{N4}' is refused. The message therefore
     * names both required tokens and states the consequence of leaving {Y} out,
     * because the failure it prevents does not appear until the following January.
     * It also names the tokens and characters a format may contain, since the
     * same rule refuses an invented token and anything outside a safe alphabet.
     * See SettingService::DOCUMENT_FORMAT_PATTERN.
     */
    public function messages(): array
    {
        return [
            'settings.required' => 'Tidak ada parameter yang dikirim.',
            'settings.array' => 'Parameter harus dikirim sebagai objek {kunci: nilai}.',
            'settings.min' => 'Tidak ada parameter yang dikirim.',
            'numeric' => 'Nilai :attribute harus berupa angka.',
            'integer' => 'Nilai :attribute harus berupa bilangan bulat.',
            'boolean' => 'Nilai :attribute harus bernilai true atau false.',
            'string' => 'Nilai :attribute harus berupa teks.',
            'in' => 'Nilai :attribute tidak termasuk pilihan yang tersedia.',
            'min' => 'Nilai :attribute tidak boleh kurang dari :min.',
            'max' => 'Nilai :attribute tidak boleh lebih dari :max.',
            'regex' => 'Format :attribute harus memuat token tahun {Y} dan salah satu token nomor '
                .'urut {N3}, {N4} atau {N5}. Nomor urut direset ke 1 setiap awal tahun, sehingga '
                .'format tanpa {Y} akan menghasilkan nomor yang sama persis dengan tahun sebelumnya '
                .'pada 1 Januari dan dokumen baru gagal disimpan karena nomor harus unik. '
                .'Token yang dikenal hanya {Y}, {M2}, {RM}, {N3}, {N4}, {N5} dan {PROJ}; selain token, '
                .'format hanya boleh berisi huruf, angka, spasi dan tanda / . _ - dalam satu baris.',
        ];
    }

    /**
     * Show the Indonesian label of the parameter instead of the dotted path.
     * Laravel decodes the escaped dots before looking an attribute up, so the
     * keys here are the plain dotted paths.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->settings()->editableKeys() as $key => $definition) {
            $attributes['settings.'.$key] = $definition['label'];
        }

        return $attributes;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->rejectUnknownKeys($validator);
            $this->rejectUnpostableAccounts($validator);
            $this->rejectRepointingAnAccountInUse($validator);
        });
    }

    /**
     * The overrides to apply, keyed by dotted setting key. A null value means
     * "reset to the shipped default".
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        return $this->validated()['settings'] ?? [];
    }

    /**
     * Anything outside the registry is a client mistake, not something to drop
     * silently — validated() would otherwise strip it without a word.
     *
     * Two different mistakes, told apart because the operator's next step
     * differs. A key config/erp.php does not define at all is a typo or a stale
     * client. A key it DOES define but the registry does not describe is an
     * install-time constant — accounting.perpetual_inventory, which elects the
     * inventory accounting method and was withdrawn from the screen in audit A2
     * precisely because one checkbox flip corrupted the ledger. Telling that
     * operator "tidak dikenal" would be false and would send them looking for a
     * spelling error; the value exists, it just does not change from here.
     */
    private function rejectUnknownKeys(Validator $validator): void
    {
        $editable = $this->settings()->editableKeys();

        foreach (array_keys($this->submitted()) as $key) {
            $key = (string) $key;

            if (array_key_exists($key, $editable)) {
                continue;
            }

            // Ditulis erp:heartbeat, dibaca pengawas: nilai yang bisa
            // "diperbaiki" dari formulir membuat penjadwal mati tampak hidup.
            if ($this->settings()->isInternal($key)) {
                $validator->errors()->add(
                    'settings.'.$key,
                    "Parameter {$key} ditulis oleh sistem (penjadwal) dan tidak dapat diubah dari layar ini.",
                );

                continue;
            }

            $validator->errors()->add(
                'settings.'.$key,
                config()->has("erp.{$key}")
                    ? "Parameter {$key} ditetapkan saat instalasi di config/erp.php dan tidak dapat "
                        .'diubah dari layar ini; mengubahnya membutuhkan deploy.'
                    : "Parameter {$key} tidak dikenal.",
            );
        }
    }

    /**
     * Repointing an account setting while the account it names still carries a
     * balance strands that balance: the engine stops posting to the old code, so
     * nothing will ever relieve it, and only a hand-written journal voucher can.
     *
     * This is the same hazard accounting.perpetual_inventory was withdrawn from
     * the screen for. The account codes stay editable because an installation
     * genuinely has to map them onto its own chart — but only while the account
     * is empty, which is exactly the install-time window that mapping belongs in.
     */
    private function rejectRepointingAnAccountInUse(Validator $validator): void
    {
        if (! Schema::hasTable('fin_accounts') || ! Schema::hasTable('fin_journal_lines')) {
            return;
        }

        $editable = $this->settings()->editableKeys();

        foreach ($this->submitted() as $key => $value) {
            $key = (string) $key;

            if (($editable[$key]['type'] ?? null) !== 'account') {
                continue;
            }

            $current = (string) $this->settings()->get($key);
            $requested = $value === null ? (string) $this->settings()->default($key) : (string) $value;

            if ($requested === $current) {
                continue;
            }

            $balance = $this->accountBalance($current);

            if ($balance === 0.0) {
                continue;
            }

            $validator->errors()->add(
                'settings.'.$key,
                "Akun {$current} masih memiliki saldo ".Money::format(abs($balance))
                    .'; memindahkannya akan meninggalkan saldo itu tanpa dokumen yang dapat '
                    .'menutupnya. Nolkan akun tersebut lewat jurnal terlebih dahulu.',
            );
        }
    }

    /**
     * Signed balance of one account code over posted journals; 0.0 when the
     * account does not exist or has never been posted to.
     */
    private function accountBalance(string $code): float
    {
        $accountId = DB::table('fin_accounts')
            ->whereNull('deleted_at')
            ->where('code', $code)
            ->value('id');

        if ($accountId === null) {
            return 0.0;
        }

        $row = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->whereNull('fin_journals.deleted_at')
            // Literal rather than Modules\Finance\Enums\PostingStatus: Core is the
            // kernel every module depends on, so it must not depend on Finance.
            // The whole check is already guarded on the tables existing.
            ->where('fin_journals.status', 'posted')
            ->where('fin_journal_lines.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) as debit, COALESCE(SUM(fin_journal_lines.credit), 0) as credit')
            ->first();

        return round((float) $row->debit - (float) $row->credit, 2);
    }

    /**
     * Account-typed parameters feed the automatic journal engine, so the code
     * must exist in the chart of accounts and be postable. Finance is optional,
     * hence the table guard.
     */
    private function rejectUnpostableAccounts(Validator $validator): void
    {
        if (! Schema::hasTable('fin_accounts')) {
            return;
        }

        $editable = $this->settings()->editableKeys();
        $codes = [];

        foreach ($this->submitted() as $key => $value) {
            if (($editable[$key]['type'] ?? null) !== 'account') {
                continue;
            }

            // null resets to the default, and a non-string already failed rules().
            if (is_string($value) && $value !== '') {
                $codes[$key] = $value;
            }
        }

        if ($codes === []) {
            return;
        }

        $postable = DB::table('fin_accounts')
            ->whereIn('code', array_values(array_unique($codes)))
            ->whereNull('deleted_at')
            ->where('is_postable', true)
            ->pluck('code')
            ->all();

        foreach ($codes as $key => $code) {
            if (! in_array($code, $postable, true)) {
                $validator->errors()->add(
                    'settings.'.$key,
                    "Akun {$code} tidak ada di bagan akun atau bukan akun yang dapat diposting.",
                );
            }
        }
    }

    /**
     * Raw submitted map, before validated() strips anything.
     *
     * @return array<array-key, mixed>
     */
    private function submitted(): array
    {
        $settings = $this->input('settings');

        return is_array($settings) ? $settings : [];
    }

    private function settings(): SettingService
    {
        return app(SettingService::class);
    }
}
