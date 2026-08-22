<?php

namespace Modules\HrPayroll\Enums;

/**
 * The six departments an employee can belong to.
 *
 * hr_employees.department is a plain string column and STAYS one: casting it
 * would make every existing row a liability, because a value outside this list
 * — a typo, a department renamed years ago, a row imported before the list
 * settled — would throw on read rather than simply being unusual. The
 * EmployeeStore/UpdateRequest Rule::in already keeps new rows inside the list;
 * this enum exists so the SAME six labels the SPA shows (public/app/js/enums.js
 * "department") can be printed on paper.
 *
 * Which is the failure it was written for: the pengajuan cuti form printed
 * "DEPARTEMEN : hrga" straight off the column, while every screen the request
 * was raised on says "HR & GA". A form the employee, the supervisor and HR all
 * sign should not spell the department differently from the system it came out
 * of.
 */
enum Department: string
{
    case Proyek = 'proyek';
    case Engineering = 'engineering';
    case Keuangan = 'keuangan';
    case Hrga = 'hrga';
    case Procurement = 'procurement';
    case Servis = 'servis';

    public function label(): string
    {
        return match ($this) {
            self::Proyek => 'Proyek',
            self::Engineering => 'Engineering',
            self::Keuangan => 'Keuangan',
            self::Hrga => 'HR & GA',
            self::Procurement => 'Procurement',
            self::Servis => 'Servis',
        };
    }

    /**
     * The label for a stored slug, or the slug itself when it is not one of
     * ours.
     *
     * An unrecognised value prints AS ITSELF. Not blank — that would hide a
     * fact the database holds — and never mapped onto the nearest known
     * department, which would put another department's name on a signed form.
     */
    public static function labelFor(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom($value)?->label() ?? $value;
    }
}
