<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Abonnement d'un atelier — l'unité de facturation.
 *
 * `status` pilote l'accès : un atelier `past_due` peut être suspendu par la
 * commande dédiée. Les plans acceptés sont définis dans la config.
 */
final class Subscription extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELED = 'canceled';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_PAST_DUE, self::STATUS_CANCELED];

    /** @var list<string> */
    public const PLANS = ['free', 'starter', 'pro'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'plan',
        'status',
        'trial_ends_at',
        'current_period_ends_at',
        'cancel_at_period_end',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cancel_at_period_end' => 'boolean',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
        ];
    }
}
