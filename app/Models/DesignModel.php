<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle du catalogue, réutilisable d'une commande à l'autre.
 */
final class DesignModel extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'gallery_id',
        'garment_type_id',
        'name',
        'reference',
        'description',
        'base_price',
        'cover_uri',
        'tags',
        'is_active',
        'usage_count',
    ];

    /** @return BelongsTo<ModelGallery, $this> */
    public function gallery(): BelongsTo
    {
        return $this->belongsTo(ModelGallery::class, 'gallery_id');
    }

    /** @return BelongsTo<GarmentType, $this> */
    public function garmentType(): BelongsTo
    {
        return $this->belongsTo(GarmentType::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'base_price' => 'float',
            'is_active' => 'boolean',
            'usage_count' => 'integer',
        ];
    }
}
