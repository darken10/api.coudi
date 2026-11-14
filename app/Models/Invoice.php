<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facture d'un abonnement.
 */
final class Invoice extends Model
{
    use HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_PAID, self::STATUS_VOID];

    /** @var list<string> */
    protected $fillable = [
        'subscription_id',
        'invoice_no',
        'amount',
        'currency',
        'status',
        'due_at',
        'paid_at',
    ];

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
