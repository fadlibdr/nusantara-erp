<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Enums\PeriodStatus;

class FiscalPeriod extends BaseModel
{
    /** Bulan dalam bahasa Indonesia — label() is read by finance, not by code. */
    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    protected $table = 'fin_fiscal_periods';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'status' => PeriodStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }

    public static function forDate(\DateTimeInterface|string $date): ?self
    {
        $date = $date instanceof \DateTimeInterface
            ? Carbon::instance($date)
            : Carbon::parse($date);

        return static::query()
            ->where('year', $date->year)
            ->where('month', $date->month)
            ->first();
    }

    /*
     * Everything above this line is the hot path: every posting in the system
     * goes through forDate() + isOpen(), so both are left byte-identical to
     * what they were before the close discipline was built on top of them.
     */

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PeriodEvent::class, 'fiscal_period_id')->orderBy('id');
    }

    /**
     * Closed-ness is read from `status` ALONE, never from closed_at.
     *
     * FiscalPeriodSeeder re-asserts status on every run, so a demo re-seed can
     * leave a period whose status says open while closed_at still holds the
     * timestamp of a close that is no longer in force. Deriving the gate from
     * status means that mismatch can mislead a display line and nothing else.
     */
    public function isClosed(): bool
    {
        return $this->status === PeriodStatus::Closed;
    }

    /** "Juni 2026" — how a period is named in every message a user reads. */
    public function label(): string
    {
        return (self::MONTHS[$this->month] ?? (string) $this->month).' '.$this->year;
    }

    /** "2026-06" — zero-padded, matching every other period key in the system. */
    public function code(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function periodStart(): string
    {
        return sprintf('%04d-%02d-01', $this->year, $this->month);
    }

    public function periodEnd(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month,
            cal_days_in_month(CAL_GREGORIAN, $this->month, $this->year));
    }

    /**
     * The period's last day is behind us.
     *
     * Strictly behind: on 30 June the month still has hours left to run, and a
     * close is a statement that nothing more will be dated into it.
     */
    public function hasEnded(): bool
    {
        return $this->periodEnd() < Carbon::today()->toDateString();
    }

    public function isCurrent(): bool
    {
        $today = Carbon::today();

        return $this->year === $today->year && $this->month === $today->month;
    }

    /** year*100 + month — the sortable key close order and reopen order share. */
    public function key(): int
    {
        return $this->year * 100 + $this->month;
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year)->orderBy('month');
    }
}
