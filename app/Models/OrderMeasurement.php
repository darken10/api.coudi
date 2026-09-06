<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mesure figée sur une commande — un relevé du client au moment de la
 * prise de commande, qui ne bouge plus si le client change de morphologie.
 */
final class OrderMeasurement extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'order_id',
        'field_id',
        'value',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<MeasurementField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(MeasurementField::class, 'field_id');
    }
}
