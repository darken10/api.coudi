<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Matière première du stock : pagne, fil, accessoire…
 *
 * Le stock courant n'est pas stocké sur cette ligne : il se calcule depuis
 * `stock_movements`, pour qu'aucune correction n'écrase l'historique.
 */
final class StockItem extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'reference',
        'category',
        'unit',
        'low_stock_threshold',
        'notes',
        'is_active',
    ];

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'low_stock_threshold' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
