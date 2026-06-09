<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RefreshToken extends Model
{
    protected $fillable = ['user_id', 'token', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    /**
     * Create a new refresh token for a user.
     *
     * @return array{0: string, 1: self}  [plaintext token, model]
     */
    public static function issue(int $userId): array
    {
        $plain = Str::random(60);

        $model = self::create([
            'user_id'    => $userId,
            'token'      => hash('sha256', $plain),
            'expires_at' => now()->addDays((int) config('tokens.refresh_expiry_days', 365)),
        ]);

        return [$plain, $model];
    }

    public static function findByPlainToken(string $plain): ?self
    {
        return self::where('token', hash('sha256', $plain))->first();
    }
}
