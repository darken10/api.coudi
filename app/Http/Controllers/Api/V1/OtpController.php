<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\RequestOtpRequest;
use App\Http\Requests\Api\V1\ResetPasswordWithOtpRequest;
use App\Http\Requests\Api\V1\VerifyOtpRequest;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;

/**
 * Réinitialisation du mot de passe par code à usage unique.
 *
 * Les réponses sont volontairement identiques que le compte existe ou non :
 * un message distinct transformerait l'endpoint en oracle d'énumération.
 */
final class OtpController extends ApiController
{
    public function __construct(private readonly OtpService $otp) {}

    public function request(RequestOtpRequest $request): JsonResponse
    {
        $this->otp->request(
            (string) $request->input('identifier'),
            (string) $request->input('channel'),
            $request->ip(),
        );

        return $this->success([
            'expires_in' => (int) config('koudi.otp.ttl_minutes', 10) * 60,
        ], 'Si ce compte existe, un code vient d’être envoyé.');
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $token = $this->otp->verify(
            (string) $request->input('identifier'),
            (string) $request->input('channel'),
            (string) $request->input('code'),
        );

        if ($token === null) {
            return $this->validationError([
                'code' => ['Code invalide, expiré ou trop de tentatives.'],
            ]);
        }

        return $this->success([
            'reset_token' => $token,
            'expires_in' => (int) config('koudi.otp.reset_token_ttl_minutes', 15) * 60,
        ], 'Code vérifié.');
    }

    public function reset(ResetPasswordWithOtpRequest $request): JsonResponse
    {
        $user = $this->otp->resetPassword(
            (string) $request->input('token'),
            (string) $request->input('password'),
        );

        if ($user === null) {
            return $this->validationError([
                'token' => ['Jeton de réinitialisation invalide ou expiré.'],
            ]);
        }

        return $this->success(message: 'Mot de passe réinitialisé. Connectez-vous.');
    }
}
