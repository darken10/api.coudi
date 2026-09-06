<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Collection de modèles du catalogue.
 */
final class ModelGallery extends Model
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'description',
        'cover_uri',
        'sort_order',
        'is_active',
    ];

    /** @return HasMany<DesignModel, $this> */
    public function designModels(): HasMany
    {
        return $this->hasMany(DesignModel::class, 'gallery_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
