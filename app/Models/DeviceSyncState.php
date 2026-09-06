<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Où en est un appareil sur un atelier : dernière révision reçue, date du
 * premier chargement complet, dernier envoi.
 *
 * @property string $device_id
 * @property string $company_id
 * @property int $last_pulled_revision
 */
final class DeviceSyncState extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'device_id',
        'company_id',
        'last_pulled_revision',
        'bootstrapped_at',
        'last_pushed_at',
    ];

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_pulled_revision' => 'integer',
            'bootstrapped_at' => 'datetime',
            'last_pushed_at' => 'datetime',
        ];
    }
}
