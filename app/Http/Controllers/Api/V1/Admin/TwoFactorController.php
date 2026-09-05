<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Admin\ConfirmTwoFactorRequest;
use App\Http\Requests\Api\V1\Admin\DisableTwoFactorRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Activation et retrait du double facteur sur un compte d'administration. */
final class TwoFactorController extends ApiController
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Prépare l'activation : génère une graine et le QR code à scanner.
     *
     * La graine n'est pas encore active — elle ne le devient qu'après un
     * premier code valide, pour ne pas verrouiller un compte dont
     * l'application n'aurait jamais été configurée.
     */
    public function enroll(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthorized();
        }

        if ($user->hasTwoFactorEnabled()) {
            return $this->error('Le double facteur est déjà actif sur ce compte.', 409);
        }

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        $uri = $this->twoFactor->provisioningUri($user, $secret);

        return $this->success([
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => $this->twoFactor->qrCodeSvg($uri),
        ], 'Scannez le QR code, puis saisissez le code affiché pour confirmer.');
    }

    /** Valide un premier code et rend les codes de secours. */
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthorized();
        }

        if ($user->two_factor_secret === null) {
            return $this->error("Commencez par lancer l'activation du double facteur.", 409);
        }

        if (! $this->twoFactor->verify($user->two_factor_secret, $request->code)) {
            return $this->error('Code incorrect. Vérifiez l\'heure de votre téléphone.', 422);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $this->success(
            ['recovery_codes' => $codes],
            'Double facteur activé. Conservez ces codes de secours hors ligne.'
        );
    }

    /** Régénère les codes de secours ; les anciens cessent immédiatement de valoir. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthorized();
        }

        if (! $user->hasTwoFactorEnabled()) {
            return $this->error("Le double facteur n'est pas actif sur ce compte.", 409);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $this->success(['recovery_codes' => $codes], 'Nouveaux codes de secours générés.');
    }

    public function disable(DisableTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthorized();
        }

        if (! $user->hasTwoFactorEnabled()) {
            return $this->error("Le double facteur n'est pas actif sur ce compte.", 409);
        }

        if (! Hash::check($request->password, $user->password)
            || ! $this->twoFactor->verify((string) $user->two_factor_secret, $request->code)) {
            return $this->error('Mot de passe ou code incorrect.', 422);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $this->success(message: 'Double facteur désactivé.');
    }
}
