<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $role
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
])]
#[Hidden([
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
])]
final class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    public const ROLE_USER = 'user';

    /** Seul rôle admis par la console d'administration. */
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /** Le double facteur n'est actif qu'une fois le premier code validé. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Ateliers auxquels ce compte a accès.
     *
     * Un patron gère plusieurs ateliers ; un atelier est partagé entre le
     * patron et ses tailleurs. Le rôle vit sur le pivot, pas sur le compte :
     * on peut être propriétaire ici et simple tailleur ailleurs.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** Rôle tenu dans un atelier, ou `null` si le compte n'y a pas accès. */
    public function roleIn(Company|string $company): ?string
    {
        $companyId = $company instanceof Company ? $company->syncCompanyId() : $company;

        /** @var Company|null $membership */
        $membership = $this->companies()->find($companyId);

        $pivot = $membership?->getRelationValue('pivot');
        $role = $pivot instanceof Pivot ? $pivot->getAttribute('role') : null;

        return is_string($role) ? $role : null;
    }

    public function canWriteIn(Company|string $company): bool
    {
        return in_array($this->roleIn($company), Company::WRITE_ROLES, true);
    }

    /** @return HasMany<LoginAudit, $this> */
    public function loginAudits(): HasMany
    {
        return $this->hasMany(LoginAudit::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Chiffrés au repos : une fuite de la base ne livre pas les graines TOTP.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
