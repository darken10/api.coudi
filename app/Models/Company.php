<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * L'atelier — l'unité de facturation autant que la frontière d'isolation.
 *
 * Toute donnée métier lui appartient, et `sync_revision` est l'horloge logique
 * dont chaque écriture répliquée prélève un numéro.
 *
 * @property-read DiagnosticSetting|null $diagnosticSetting
 * @property-read int|null $clients_count
 */
final class Company extends Model implements Replicable
{
    /** @use HasFactory<\Database\Factories\CompanyFactory> */
    use HasFactory;

    use Syncable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const ROLE_OWNER = 'owner';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_TAILOR = 'tailor';

    /** @var list<string> */
    public const ROLES = [self::ROLE_OWNER, self::ROLE_MANAGER, self::ROLE_TAILOR];

    /** Rôles autorisés à écrire — un tailleur consulte, il n'administre pas. */
    public const WRITE_ROLES = [self::ROLE_OWNER, self::ROLE_MANAGER];

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'owner_name',
        'status',
        'address',
        'phone',
        'email',
        'website',
        'logo_uri',
        'archived_at',
    ];

    /** À la création, le compteur n'existe pas encore : rien à prélever. */
    public function shouldAllocateRevision(): bool
    {
        return $this->exists;
    }

    /** L'atelier est son propre locataire. */
    public function syncCompanyId(): string
    {
        /** @var string $id */
        $id = $this->getKey();

        return $id;
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasOne<CompanySetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    /** @return HasOne<DiagnosticSetting, $this> */
    public function diagnosticSetting(): HasOne
    {
        return $this->hasOne(DiagnosticSetting::class);
    }

    protected static function booted(): void
    {
        /*
         * Le compteur de l'atelier vit sur la ligne elle-même : à la création
         * il n'existe pas encore, donc rien à prélever. On pose la révision 1
         * et on aligne le compteur d'un même mouvement.
         */
        self::creating(function (self $company): void {
            $company->setAttribute('revision', 1);
            $company->setAttribute('sync_revision', 1);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'sync_revision' => 'integer',
            'revision' => 'integer',
        ];
    }
}
