<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Établit l'atelier de la requête et vérifie l'appartenance du compte.
 *
 * C'est la frontière d'isolation du locataire. Elle est ici, en amont, plutôt
 * que dans chaque contrôleur : un oubli ne coûterait pas une erreur visible
 * mais la fuite du carnet de clients d'un atelier vers un autre.
 */
final class ResolveCompany
{
    public const ATTRIBUTE = 'sync.company';

    public const ROLE_ATTRIBUTE = 'sync.role';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->deny('Authentification requise.', 401);
        }

        $companyId = $request->header('X-Company-Id') ?? $request->input('company_id');

        if (! is_string($companyId) || $companyId === '') {
            return $this->deny("En-tête X-Company-Id requis : aucune requête ne s'exécute hors d'un atelier.", 400);
        }

        /** @var Company|null $company */
        $company = $user->companies()->find($companyId);

        // Même réponse pour « n'existe pas » et « pas le vôtre » : distinguer
        // les deux dirait à un curieux quels ateliers existent.
        if ($company === null) {
            return $this->deny('Atelier inconnu ou hors de votre périmètre.', 403);
        }

        if ($company->status === Company::STATUS_SUSPENDED) {
            return $this->deny('Cet atelier est suspendu.', 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $company);
        $role = $company->getAttribute('pivot')?->getAttribute('role');
        $request->attributes->set(self::ROLE_ATTRIBUTE, is_string($role) ? $role : null);

        return $next($request);
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
