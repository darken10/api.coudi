<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'une décision prise depuis la console d'administration.
 *
 * Nourrit le journal d'audit global : qui a suspendu qui, quel atelier a été
 * modifié, quel plan a été changé.
 */
final class AdminAction extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'admin_id',
        'action',
        'company_id',
        'target_user_id',
        'meta',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'meta' => 'array',
    ];

    public static function record(string $action, ?User $admin = null, ?Company $company = null, ?User $target = null, array $meta = []): void
    {
        self::query()->create([
            'admin_id' => $admin?->id,
            'action' => $action,
            'company_id' => $company?->getKey(),
            'target_user_id' => $target?->id,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
