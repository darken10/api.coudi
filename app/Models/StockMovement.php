<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée ou sortie de matière. La quantité est toujours positive ; le sens
 * est porté par `type`. Le stock courant d'une matière est la somme algébrique
 * de ses mouvements.
 */
final class StockMovement extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    /** @var list<string> */
    public const TYPES = [self::TYPE_IN, self::TYPE_OUT];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'stock_item_id',
        'type',
        'quantity',
        'note',
        'moved_at',
    ];

    /** @return BelongsTo<StockItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'moved_at' => 'datetime',
        ];
    }
}
