<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifie l'installation à l'origine de la requête, et l'enregistre au
 * premier contact.
 *
 * L'unité de synchronisation est l'appareil, pas le compte : deux téléphones
 * d'un même patron avancent chacun à leur rythme et ne doivent pas partager un
 * curseur de rattrapage.
 */
final class ResolveDevice
{
    public const ATTRIBUTE = 'sync.device';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->deny('Authentification requise.', 401);
        }

        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');

        if (! is_string($deviceId) || ! Str::isUuid($deviceId)) {
            return $this->deny('En-tête X-Device-Id requis (UUID de l\'installation).', 400);
        }

        /** @var Device|null $device */
        $device = Device::query()->find($deviceId);

        if ($device === null) {
            $device = Device::query()->create([
                'id' => $deviceId,
                'user_id' => $user->id,
                'name' => $request->header('X-Device-Name'),
                'platform' => $request->header('X-Device-Platform'),
                'app_version' => $request->header('X-App-Version'),
                'os_version' => $request->header('X-Os-Version'),
            ]);
        }

        // Un identifiant d'appareil deviné ne doit pas donner prise sur le
        // curseur de son propriétaire.
        if ($device->user_id !== $user->id) {
            return $this->deny('Cet appareil est enregistré sur un autre compte.', 403);
        }

        if (! $device->isActive()) {
            return $this->deny('Cet appareil a été révoqué. Reconnectez-vous.', 403);
        }

        $device->forceFill(['last_seen_at' => now()])->save();

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
