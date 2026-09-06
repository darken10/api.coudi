<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mesure relevable (tour de poitrine, longueur manche…), propre à l'atelier.
 */
final class MeasurementField extends Model
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'category',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
