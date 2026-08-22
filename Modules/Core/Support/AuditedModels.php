<?php

namespace Modules\Core\Support;

use App\Models\User;
use Modules\Core\Models\Setting;
use Modules\Crm\Models\Contract;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Tax;
use Modules\HrPayroll\Models\Employee;
use Modules\Procurement\Models\Vendor;

/**
 * What is worth recording, and what must never be recorded.
 *
 * Deliberately NOT everything. An audit log that captures every save on every
 * model is one nobody reads: a goods receipt posting forty stock lines would
 * bury the one row that matters, and the log becomes larger than the data. The
 * list below is the changes an auditor or a fraud investigation actually asks
 * about — master data that redirects money, the parameters that compute it, and
 * who has access.
 *
 * Documents are absent on purpose. Their lifecycle is already recorded in
 * core_approvals, and their edits are constrained to draft state; duplicating
 * that here would add noise without adding evidence. The exception is the
 * contract, because its value is the basis of every termin invoice.
 */
class AuditedModels
{
    /**
     * @var array<class-string, string> model => the attribute to label rows by
     */
    private const AUDITED = [
        // Redirects money. The classic invoice-fraud vector.
        Vendor::class => 'name',
        BankAccount::class => 'name',

        // Computes money.
        Setting::class => 'key',
        Tax::class => 'code',
        Account::class => 'code',

        // Who can do any of it.
        User::class => 'email',

        // Contract value is the basis of every termin invoice raised against it.
        Contract::class => 'code',

        // Salary, bank account and PTKP status all feed payroll.
        Employee::class => 'code',
    ];

    /**
     * Attributes that must never reach the log, on any model.
     *
     * A password hash is still a credential, and an audit log is read by more
     * people than the users table is. remember_token and API tokens are session
     * credentials outright. The timestamps are noise — every update changes
     * updated_at, and a log where every entry contains it reads as though
     * something changed when nothing did.
     */
    public const NEVER_LOGGED = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** @return list<class-string> */
    public static function classes(): array
    {
        return array_keys(self::AUDITED);
    }

    public static function isAudited(object|string $model): bool
    {
        return isset(self::AUDITED[is_string($model) ? $model : $model::class]);
    }

    /**
     * A human handle for the row, so the log reads without joining back to a
     * record that may since have been deleted.
     */
    public static function labelFor(object $model): ?string
    {
        $attribute = self::AUDITED[$model::class] ?? null;

        if ($attribute === null) {
            return null;
        }

        $value = $model->getAttribute($attribute);

        return $value === null ? null : mb_substr((string) $value, 0, 160);
    }
}
