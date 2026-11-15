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
use App\Models\Company;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Services\Auth\TokenIssuer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

final class AuthController extends ApiController
{
    public function __construct(private readonly TokenIssuer $tokens) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $user->sendEmailVerificationNotification();

        return $this->created(
            $this->tokenPayload($user),
            'User registered successfully. Please check your email to verify your account.'
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = trim((string) ($request->login ?? $request->email ?? $request->phone));

        $user = $this->findByIdentifier($identifier);

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->unauthorized('Invalid credentials');
        }

        if (! $user->isActiveAccount()) {
            return $this->forbidden(
                $user->status === User::STATUS_PENDING_ACTIVATION
                    ? 'Account not activated yet. Use your invitation link.'
                    : 'Account suspended.'
            );
        }

        return $this->success($this->tokenPayload($user), 'Login successful');
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

        $payload = $this->tokens->issue($user);

        return $this->success([
            'access_token' => $payload['access_token'],
            'refresh_token' => $payload['refresh_token'],
            'token_type' => 'Bearer',
            'expires_at' => $payload['expires_at'],
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
        /** @var User $user */
        $user = $request->user();

        $data = (new UserResource($user))->resolve();

        $data['memberships'] = $user->companies()->get()->map(static fn (Company $company): array => [
            'company_id' => $company->getKey(),
            'name' => $company->name,
            'role' => $company->getRelationValue('pivot')?->getAttribute('role'),
        ])->values()->all();

        $companyId = $request->header('X-Company-Id');

        if (is_string($companyId) && $companyId !== '') {
            $company = $user->companies()->find($companyId);

            if ($company !== null) {
                $employee = $user->employeeIn($company);

                $data['current_company'] = [
                    'id' => $company->getKey(),
                    'name' => $company->name,
                    'role' => $company->getRelationValue('pivot')?->getAttribute('role'),
                    'employee_restricted_orders' => (bool) $company->settings?->employee_restricted_orders,
                ];

                $data['employee'] = $employee === null ? null : [
                    'id' => $employee->getKey(),
                    'company_id' => $employee->company_id,
                    'name' => $employee->name,
                    'role' => $employee->role,
                    'phone' => $employee->phone,
                    'whatsapp' => $employee->whatsapp,
                    'email' => $employee->email,
                    'photo_uri' => $employee->photo_uri,
                ];
            }
        }

        return $this->success($data);
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
        $user = User::query()->where('email', mb_strtolower(trim((string) $request->email)))->first();

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
                Password::INVALID_USER => 'User not found',
                default => 'Unable to reset password',
            },
            400
        );
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(User $user): array
    {
        $payload = $this->tokens->issue($user);

        $payload['user'] = new UserResource($user);

        return $payload;
    }

    /**
     * Retrouve un compte par email, téléphone ou WhatsApp. Le téléphone est
     * normalisé comme lors de l'invitation, pour que « +223 70 00 » et
     * « +2237000 » désignent le même compte.
     */
    private function findByIdentifier(string $identifier): ?User
    {
        if ($identifier === '') {
            return null;
        }

        if (str_contains($identifier, '@')) {
            return User::query()->where('email', mb_strtolower($identifier))->first();
        }

        // Format international d'abord ; l'ancien format reste accepté tant
        // que la reprise des numéros n'a pas été faite (koudi:normalize-phones).
        $candidates = array_values(array_unique(array_filter([
            PhoneNumber::normalize($identifier),
            PhoneNumber::legacy($identifier),
        ])));

        if ($candidates === []) {
            return null;
        }

        return User::query()->whereIn('phone', $candidates)->first()
            ?? User::query()->whereIn('whatsapp', $candidates)->first();
    }
}
