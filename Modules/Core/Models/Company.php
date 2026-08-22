<?php

namespace Modules\Core\Models;

class Company extends BaseModel
{
    protected $table = 'core_company';

    protected $casts = [
        'is_pkp' => 'boolean',
    ];

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
