<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Invitation à activer un compte employé.
 *
 * Le jeton en clair n'est montré qu'une fois, à la création : la base ne
 * conserve que son hash SHA-256. L'invitation est à usage unique
 * (`accepted_at`), révocable et expirante.
 *
 * @property string $id
 * @property string $token_hash
 * @property Carbon $expires_at
 */
final class EmployeeInvitation extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'user_id',
        'token_hash',
        'email',
        'phone',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'created_by',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByPlainToken(string $plain): ?self
    {
        return self::query()->where('token_hash', self::hashToken($plain))->first();
    }

    /** @return array{0: string, 1: self} [jeton en clair, modèle] */
    public static function issue(array $attributes, int $ttlHours): array
    {
        $plain = Str::random(64);

        $model = self::query()->create($attributes + [
            'token_hash' => self::hashToken($plain),
            'expires_at' => now()->addHours($ttlHours),
        ]);

        return [$plain, $model];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
