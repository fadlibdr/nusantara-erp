<?php

namespace Modules\Core\Support;

use Modules\Core\Services\SettingService;

/**
 * Read-side entry point for ERP parameters.
 *
 * Prefer this over config('erp.…') everywhere a value can legitimately change
 * over the life of the installation (tax rates, BPJS, thresholds, numbering).
 * It falls back to config/erp.php, so the shipped defaults still apply until an
 * administrator overrides something on the settings screen.
 *
 * Migrations are the exception: a column default is baked into the schema, so
 * those keep reading config() directly.
 */
class Erp
{
    public static function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingService::class)->get($key, $default);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        return (float) static::setting($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) static::setting($key, $default);
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string) static::setting($key, $default);
    }

    /**
     * Strings are what an override arrives as, so "0" and "false" have to mean
     * false — filter_var does that, and returns the default rather than
     * silently truthy nonsense for anything it cannot read.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = static::setting($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
