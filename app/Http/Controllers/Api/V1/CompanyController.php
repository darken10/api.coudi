<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ateliers du compte connecté.
 *
 * L'enregistrement d'un atelier ne passe volontairement pas par la
 * synchronisation : c'est un acte rare, qui crée un locataire, engage le quota
 * de l'abonnement et fonde des droits. La réplication, elle, est fréquente et
 * anonyme. Les mélanger obligerait le moteur de synchronisation à savoir
 * facturer.
 *
 * C'est aussi la première étape après connexion : l'application demande la
 * liste, apparie chaque atelier local à son homologue serveur, puis lance le
 * premier chargement.
 */
final class CompanyController extends ApiController
{
    /** GET /api/v1/companies */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $companies = $user->companies()
            ->withCount(['clients', 'employees', 'orders'])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company): array => $this->payload($company));

        return $this->success($companies);
    }

    /** GET /api/v1/companies/{company} */
    public function show(Request $request, string $companyId): JsonResponse
    {
        $company = $this->memberCompany($request, $companyId);

        return $company === null
            ? $this->notFound('Atelier inconnu ou hors de votre périmètre.')
            : $this->success($this->payload($company));
    }

    /**
     * Enregistre un atelier créé hors ligne sur l'appareil.
     *
     * L'identifiant vient du mobile : c'est celui de la ligne locale, et c'est
     * ce qui permet à l'appareil de se rattacher à l'atelier serveur sans
     * réécrire ses milliers de clés étrangères.
     *
     * POST /api/v1/companies
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'id' => ['nullable', 'uuid', 'unique:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'logo_uri' => ['nullable', 'string', 'max:2048'],
        ]);

        $company = DB::transaction(function () use ($data, $user): Company {
            $company = new Company;
            $company->fill($data);

            if (is_string($data['id'] ?? null)) {
                $company->forceFill(['id' => $data['id']]);
            }

            $company->save();

            $company->members()->attach($user->id, [
                'role' => Company::ROLE_OWNER,
                'joined_at' => now(),
            ]);

            // Les réglages d'atelier existent dès la création : sans ligne, le
            // premier chargement livrerait une application sans devise.
            CompanySetting::query()->create(['company_id' => $company->getKey()]);

            return $company;
        });

        // Rechargé par la relation : `fresh()` rendrait l'atelier sans son
        // pivot, donc sans le rôle que la réponse doit annoncer.
        $saved = $this->memberCompany($request, $company->syncCompanyId()) ?? $company;

        return $this->created($this->payload($saved), 'Atelier enregistré.');
    }

    /** PUT /api/v1/companies/{company} */
    public function update(Request $request, string $companyId): JsonResponse
    {
        $company = $this->memberCompany($request, $companyId);

        if ($company === null) {
            return $this->notFound('Atelier inconnu ou hors de votre périmètre.');
        }

        if (! in_array($this->pivotRole($company), Company::WRITE_ROLES, true)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas la modification.');
        }

        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'logo_uri' => ['nullable', 'string', 'max:2048'],
        ]);

        DB::transaction(fn () => $company->fill($data)->save());

        return $this->success($this->payload($this->memberCompany($request, $companyId) ?? $company), 'Atelier mis à jour.');
    }

    /** Rôle porté par le pivot, absent quand l'atelier n'a pas été chargé par la relation. */
    private function pivotRole(Company $company): ?string
    {
        $pivot = $company->getAttribute('pivot');
        $role = $pivot?->getAttribute('role');

        return is_string($role) ? $role : null;
    }

    private function memberCompany(Request $request, string $companyId): ?Company
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company|null $company */
        $company = $user->companies()->find($companyId);

        return $company;
    }

    /** @return array<string, mixed> */
    private function payload(Company $company): array
    {
        return [
            'id' => $company->getKey(),
            'name' => $company->name,
            'description' => $company->description,
            'owner_name' => $company->owner_name,
            'address' => $company->address,
            'phone' => $company->phone,
            'email' => $company->email,
            'website' => $company->website,
            'logo_uri' => $company->logo_uri,
            'status' => $company->status,
            'role' => $this->pivotRole($company),
            'revision' => (int) $company->revision,
            'server_revision' => (int) $company->sync_revision,
            'counts' => array_filter([
                'clients' => $company->clients_count,
                'employees' => $company->employees_count,
                'orders' => $company->orders_count,
            ], static fn (mixed $v): bool => $v !== null),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }
}
