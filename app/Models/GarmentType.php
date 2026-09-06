<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type de vêtement du catalogue : c'est lui qui porte les champs de
 * mesure à relever et le tarif de base.
 */
final class GarmentType extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'complexity',
        'base_price',
        'is_active',
    ];

    /** @return HasMany<GarmentTypeField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(GarmentTypeField::class, 'garment_type_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'base_price' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
