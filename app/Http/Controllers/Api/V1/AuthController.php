<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RefreshTokenRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResendVerificationRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

final class AuthController extends ApiController
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $user->sendEmailVerificationNotification();

        return $this->created(
            $this->buildTokenPayload($user),
            'User registered successfully. Please check your email to verify your account.'
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->unauthorized('Invalid credentials');
        }

        return $this->success($this->buildTokenPayload($user), 'Login successful');
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $refreshToken = RefreshToken::findByPlainToken($request->refresh_token);

        if (! $refreshToken || ! $refreshToken->isValid()) {
            return $this->unauthorized('Invalid or expired refresh token');
        }

        $user = $refreshToken->user;

        // Rotate: revoke the old token, issue a fresh pair
        $refreshToken->revoke();

        $expiresAt    = $this->accessTokenExpiresAt();
        $accessToken  = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
        [$newRefresh] = RefreshToken::issue($user->id);

        return $this->success([
            'access_token'  => $accessToken,
            'refresh_token' => $newRefresh,
            'token_type'    => 'Bearer',
            'expires_at'    => $expiresAt->toIso8601String(),
        ], 'Token refreshed');
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()->delete();

        // Revoke all active refresh tokens on logout
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return $this->success(message: 'Logged out successfully');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()));
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success(message: 'Email already verified');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->success(message: 'Email verified successfully');
    }

    public function resendVerificationEmail(ResendVerificationRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->email)->first();

        if (! $user) {
            return $this->notFound('User not found');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->error('Email already verified', 400);
        }

        $user->sendEmailVerificationNotification();

        return $this->success(message: 'Verification email sent successfully');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return $this->success(message: 'Password reset link sent to your email');
        }

        return $this->error('Unable to send reset link', 500);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(message: 'Password reset successfully');
        }

        return $this->error(
            match ($status) {
                Password::INVALID_TOKEN => 'Invalid or expired reset token',
                Password::INVALID_USER  => 'User not found',
                default                 => 'Unable to reset password',
            },
            400
        );
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildTokenPayload(User $user): array
    {
        $expiresAt   = $this->accessTokenExpiresAt();
        $accessToken = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
        [$refresh]   = RefreshToken::issue($user->id);

        return [
            'user'          => new UserResource($user),
            'access_token'  => $accessToken,
            'refresh_token' => $refresh,
            'token_type'    => 'Bearer',
            'expires_at'    => $expiresAt->toIso8601String(),
        ];
    }

    private function accessTokenExpiresAt(): Carbon
    {
        $days = config('tokens.access_expiry_days');

        // Null = never expires; fallback to 100 years for createToken (which requires a date)
        return $days !== null
            ? now()->addDays((int) $days)
            : now()->addYears(100);
    }
}
