<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse tout ce qui n'est pas un super admin authentifié.
 *
 * La console expose les bases clients de tous les ateliers : le contrôle se
 * fait ici, une seule fois, plutôt que dans chaque contrôleur où un oubli
 * passerait inaperçu.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Authentification requise.',
            ], 401);
        }

        if (! $user->isSuperAdmin()) {
            return new JsonResponse([
                'success' => false,
                'message' => "Cet espace est réservé à l'administration.",
            ], 403);
        }

        return $next($request);
    }
}
