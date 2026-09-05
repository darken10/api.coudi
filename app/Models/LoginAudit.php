<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/** Trace d'une tentative de connexion à la console d'administration. */
final class LoginAudit extends Model
{
    public const SUCCESS = 'success';

    public const BAD_PASSWORD = 'bad_password';

    public const BAD_TWO_FACTOR = 'bad_2fa';

    public const LOCKED = 'locked';

    public const UNKNOWN_USER = 'unknown_user';

    /** Identifiants valides, mais compte sans droit d'accès à la console. */
    public const FORBIDDEN = 'forbidden';

    protected $fillable = ['user_id', 'email', 'status', 'ip', 'user_agent'];

    public static function record(Request $request, string $email, string $status, ?int $userId = null): void
    {
        self::query()->create([
            'user_id' => $userId,
            'email' => $email,
            'status' => $status,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
