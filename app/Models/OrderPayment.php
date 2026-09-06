<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encaissement sur une commande.
 *
 * Entité en ajout seul, et c'est vital : deux appareils encaissant chacun un
 * acompte hors ligne doivent produire deux paiements. Un arbitrage au dernier
 * écrivain ferait disparaître de l'argent.
 */
final class OrderPayment extends Model
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'order_id',
        'amount',
        'note',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }
}
