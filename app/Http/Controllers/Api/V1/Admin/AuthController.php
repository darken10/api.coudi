<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Admin\AdminLoginRequest;
use App\Http\Requests\Api\V1\Admin\TwoFactorChallengeRequest;
use App\Models\LoginAudit;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Authentification de la console d'administration.
 *
 * Distincte de l'auth mobile : durée de session plus courte, accès réservé aux
 * super admins, et second facteur exigé dès qu'il est activé.
 */
final class AuthController extends ApiController
{
    /** Tentatives avant verrouillage temporaire, par couple e-mail/IP. */
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    /** Durée de validité du jeton intermédiaire entre mot de passe et code. */
    private const CHALLENGE_TTL = 300;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $email = mb_strtolower($request->email);
        $key = 'admin-login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            LoginAudit::record($request, $email, LoginAudit::LOCKED);

            return $this->error(
                sprintf(
                    'Trop de tentatives. Réessayez dans %d minutes.',
                    (int) ceil(RateLimiter::availableIn($key) / 60)
                ),
                429
            );
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            LoginAudit::record(
                $request,
                $email,
                $user ? LoginAudit::BAD_PASSWORD : LoginAudit::UNKNOWN_USER,
                $user?->id
            );

            // Message identique dans les deux cas : ne pas révéler quels
            // e-mails existent.
            return $this->unauthorized('E-mail ou mot de passe incorrect.');
        }

        if (! $user->isSuperAdmin()) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            LoginAudit::record($request, $email, LoginAudit::FORBIDDEN, $user->id);

            return $this->forbidden("Cet espace est réservé à l'administration.");
        }

        if ($user->hasTwoFactorEnabled()) {
            $challenge = Str::random(48);
            Cache::put('admin-2fa:'.$challenge, $user->id, self::CHALLENGE_TTL);

            return $this->success([
                'two_factor_required' => true,
                'challenge' => $challenge,
                'expires_in' => self::CHALLENGE_TTL,
            ], 'Saisissez le code de votre application d\'authentification.');
        }

        RateLimiter::clear($key);
        LoginAudit::record($request, $email, LoginAudit::SUCCESS, $user->id);

        return $this->success($this->tokenPayload($user), 'Connexion réussie.');
    }

    /** Seconde étape : code TOTP ou code de secours. */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        $cacheKey = 'admin-2fa:'.$request->challenge;
        $userId = Cache::get($cacheKey);

        if (! is_int($userId)) {
            return $this->unauthorized('Session de vérification expirée. Reconnectez-vous.');
        }

        $user = User::query()->find($userId);

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            Cache::forget($cacheKey);

            return $this->unauthorized('Session de vérification invalide.');
        }

        $key = 'admin-2fa-try:'.$userId;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            Cache::forget($cacheKey);
            LoginAudit::record($request, $user->email, LoginAudit::LOCKED, $user->id);

            return $this->error('Trop de codes erronés. Reconnectez-vous.', 429);
        }

        $verified = $request->code !== null
            && $this->twoFactor->verify((string) $user->two_factor_secret, $request->code);

        if (! $verified && $request->recovery_code !== null) {
            $remaining = $this->twoFactor->consumeRecoveryCode(
                array_values($user->two_factor_recovery_codes ?? []),
                $request->recovery_code
            );

            if ($remaining !== null) {
                $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();
                $verified = true;
            }
        }

        if (! $verified) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            LoginAudit::record($request, $user->email, LoginAudit::BAD_TWO_FACTOR, $user->id);

            return $this->unauthorized('Code de vérification incorrect.');
        }

        // Consommé : un même défi ne peut pas servir deux fois.
        Cache::forget($cacheKey);
        RateLimiter::clear($key);
        RateLimiter::clear('admin-login:'.mb_strtolower($user->email).'|'.$request->ip());
        LoginAudit::record($request, $user->email, LoginAudit::SUCCESS, $user->id);

        return $this->success($this->tokenPayload($user), 'Connexion réussie.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthorized();
        }

        return $this->success($this->userPayload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $user->currentAccessToken()->delete();
        }

        return $this->success(message: 'Déconnecté.');
    }

    /** Sessions ouvertes sur la console, pour pouvoir en révoquer une. */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthorized();
        }

        $currentId = $user->currentAccessToken()->getKey();

        return $this->success(
            $user->tokens()
                ->where('name', 'admin-console')
                ->latest('last_used_at')
                ->get()
                ->map(fn ($token): array => [
                    'id' => $token->id,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'current' => $token->id === $currentId,
                ])
                ->all()
        );
    }

    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthorized();
        }

        $user->tokens()->whereKey($tokenId)->delete();

        return $this->success(message: 'Session révoquée.');
    }

    /** Journal des connexions du compte courant. */
    public function loginHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthorized();
        }

        return $this->success(
            LoginAudit::query()
                ->where('email', $user->email)
                ->latest()
                ->limit(50)
                ->get(['id', 'status', 'ip', 'user_agent', 'created_at'])
                ->all()
        );
    }

    /** @return array<string, mixed> */
    private function tokenPayload(User $user): array
    {
        $expiresAt = Carbon::now()->addHours(Config::integer('tokens.admin_expiry_hours', 12));

        return [
            'two_factor_required' => false,
            'user' => $this->userPayload($user),
            'access_token' => $user->createToken('admin-console', ['admin'], $expiresAt)->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ];
    }
}
