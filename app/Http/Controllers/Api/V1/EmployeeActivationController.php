<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Employee\CompleteActivationRequest;
use App\Http\Requests\Api\V1\Employee\VerifyActivationCodeRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\TokenIssuer;
use App\Services\Employee\EmployeeActivationService;
use Illuminate\Http\JsonResponse;

/**
 * Activation publique d'un compte employé, depuis son téléphone.
 *
 * Pas d'atelier courant ni de session : l'employé n'appartient pas encore à
 * l'application. Les jetons ne sont émis qu'à la toute fin, une fois le mot
 * de passe choisi.
 */
final class EmployeeActivationController extends ApiController
{
    public function __construct(private readonly EmployeeActivationService $activation) {}

    public function verify(VerifyActivationCodeRequest $request): JsonResponse
    {
        $result = $this->activation->verify(
            $request->string('phone')->toString(),
            $request->string('code')->toString(),
            $request->ip(),
            $request->filled('device_id') ? $request->string('device_id')->toString() : $request->header('X-Device-Id'),
        );

        return $this->success($result, 'Code vérifié. Choisissez maintenant votre mot de passe.');
    }

    public function complete(CompleteActivationRequest $request, TokenIssuer $tokens): JsonResponse
    {
        $user = $this->activation->complete(
            $request->string('ticket')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
        );

        $payload = $tokens->issue($user);
        $payload['user'] = new UserResource($user);

        return $this->success($payload, 'Compte activé. Bienvenue sur COUDI.');
    }
}
