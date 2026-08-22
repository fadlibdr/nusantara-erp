<?php

namespace Modules\Core\Models;

class Setting extends BaseModel
{
    protected $table = 'core_settings';

    protected $casts = [
        'value' => 'json',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, mixed $value, string $group = 'general'): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
    }
}
