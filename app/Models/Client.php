<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client de l'atelier.
 */
final class Client extends Model implements Replicable
{
    /** @use HasFactory<\Database\Factories\ClientFactory> */
    use BelongsToCompany;

    use HasFactory;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'notes',
        'photo_uri',
        'birth_date',
        'event_date',
        'event_label',
        'is_vip',
        'is_active',
    ];

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<ClientMeasurement, $this> */
    public function measurements(): HasMany
    {
        return $this->hasMany(ClientMeasurement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_vip' => 'boolean',
            'is_active' => 'boolean',
            'birth_date' => 'date',
            'event_date' => 'date',
        ];
    }
}
