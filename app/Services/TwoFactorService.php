<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Double facteur TOTP : génération, vérification et codes de secours. */
final readonly class TwoFactorService
{
    public function __construct(private Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** URI otpauth:// à encoder dans le QR code. */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret
        );
    }

    /** QR code en SVG inline — évite de dépendre d'un service tiers. */
    public function qrCodeSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(220, 0),
            new SvgImageBackEnd
        ));

        return $writer->writeString($uri);
    }

    /**
     * Vérifie un code TOTP.
     *
     * La fenêtre de 1 tolère une dérive d'horloge d'un intervalle de part et
     * d'autre — au-delà, un code intercepté resterait valide trop longtemps.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code, 1);
    }

    /** @return list<string> */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->values()
            ->all();
    }

    /**
     * Consomme un code de secours s'il correspond.
     *
     * @param  list<string>  $codes
     * @return list<string>|null  la liste amputée du code utilisé, ou null si aucun ne correspond
     */
    public function consumeRecoveryCode(array $codes, string $candidate): ?array
    {
        $remaining = array_values(array_filter(
            $codes,
            fn (string $code): bool => ! hash_equals($code, Str::lower(trim($candidate)))
        ));

        return count($remaining) === count($codes) ? null : $remaining;
    }
}
