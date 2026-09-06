<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une installation de l'application mobile.
 *
 * L'appareil, et non le compte, est l'unité de synchronisation : deux
 * téléphones d'un même patron rattrapent chacun à leur rythme et ne se
 * renvoient pas mutuellement leurs propres écritures.
 *
 * @property string $id
 * @property int $user_id
 */
final class Device extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'name',
        'platform',
        'app_version',
        'os_version',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DeviceSyncState, $this> */
    public function syncStates(): HasMany
    {
        return $this->hasMany(DeviceSyncState::class);
    }

    /** Curseur de rattrapage de cet appareil sur un atelier donné. */
    public function syncStateFor(Company|string $company): DeviceSyncState
    {
        return DeviceSyncState::query()->firstOrCreate([
            'device_id' => $this->getKey(),
            'company_id' => $company instanceof Company ? $company->getKey() : $company,
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
