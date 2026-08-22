<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;

class AssetCategory extends BaseModel
{
    use SoftDeletes;

    protected $table = 'ast_categories';

    protected function casts(): array
    {
        return [
            'useful_life_months_default' => 'integer',
        ];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }
}
