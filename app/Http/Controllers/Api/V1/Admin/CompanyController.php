<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Admin\UpdateCompanyRequest;
use App\Http\Requests\Api\V1\Diagnostic\UpdateDiagnosticSettingsRequest;
use App\Models\AdminAction;
use App\Models\Company;
use App\Models\DiagnosticSetting;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gestion des ateliers depuis la console d'administration. */
final class CompanyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();

        $companies = Company::query()
            ->with('diagnosticSetting')
            ->withCount('clients')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->value())
            )
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return $this->success([
            'items' => array_map(
                fn (Company $company): array => $this->row($company),
                $companies->items()
            ),
            'meta' => [
                'total' => $companies->total(),
                'page' => $companies->currentPage(),
                'per_page' => $companies->perPage(),
                'last_page' => $companies->lastPage(),
            ],
        ]);
    }

    public function show(Company $company): JsonResponse
    {
        $company->loadCount('clients')->load('diagnosticSetting');

        return $this->success($this->row($company) + [
            'members' => $this->members($company),
            'pending_invitations' => $this->pendingInvitations($company),
            'employee_restricted_orders' => (bool) ($company->settings?->employee_restricted_orders ?? false),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $company->update($request->validated());

        /** @var User|null $admin */
        $admin = $request->user();
        AdminAction::record('company.updated', $admin, $company, null, $request->validated());

        $company->refresh()->loadCount('clients')->load('diagnosticSetting');

        return $this->success($this->row($company), 'Atelier mis à jour.');
    }

    /**
     * Bascule de la restriction de visibilité des commandes par employé.
     *
     * Ce réglage vit dans `company_settings` (répliqué) : le modifier ici le
     * fera descendre sur les téléphones au prochain rattrapage.
     */
    public function updateRestriction(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'employee_restricted_orders' => ['required', 'boolean'],
        ]);

        $company->settings()->updateOrCreate(
            ['company_id' => $company->getKey()],
            ['employee_restricted_orders' => $data['employee_restricted_orders']],
        );

        /** @var User|null $admin */
        $admin = $request->user();
        AdminAction::record('company.restriction_updated', $admin, $company, null, $data);

        return $this->success(
            ['employee_restricted_orders' => (bool) $data['employee_restricted_orders']],
            'Restriction de visibilité mise à jour.'
        );
    }

    /** Réglages de journalisation propres à cet atelier. */
    public function updateDiagnosticSettings(
        UpdateDiagnosticSettingsRequest $request,
        Company $company
    ): JsonResponse {
        $setting = DiagnosticSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'log_rotation' => $request->log_rotation,
                'log_retention' => $request->log_retention,
                'log_level' => $request->log_level,
                'log_api_calls' => $request->log_api_calls,
                'log_api_bodies' => $request->log_api_bodies,
            ]
        );

        return $this->success($this->settings($setting), 'Réglages de journalisation enregistrés.');
    }

    /** Rend à cet atelier le réglage global. */
    public function resetDiagnosticSettings(Company $company): JsonResponse
    {
        DiagnosticSetting::query()->where('company_id', $company->id)->delete();

        return $this->success(
            $this->settings(DiagnosticSetting::resolveFor(null)),
            'Réglages remis sur la valeur globale.'
        );
    }

    /** @return array<string, mixed> */
    private function row(Company $company): array
    {
        $setting = $company->diagnosticSetting;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'address' => $company->address,
            'phone' => $company->phone,
            'email' => $company->email,
            'website' => $company->website,
            'status' => $company->status,
            'clients_count' => $company->clients_count ?? 0,
            'created_at' => $company->created_at?->toIso8601String(),
            // `overridden` dit à la console si l'atelier suit le réglage global.
            'diagnostic_settings' => $this->settings($setting ?? DiagnosticSetting::resolveFor(null)),
            'diagnostic_settings_overridden' => $setting !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function settings(DiagnosticSetting $setting): array
    {
        return [
            'log_rotation' => $setting->log_rotation,
            'log_retention' => $setting->log_retention,
            'log_level' => $setting->log_level,
            'log_api_calls' => (bool) $setting->log_api_calls,
            'log_api_bodies' => (bool) $setting->log_api_bodies,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function members(Company $company): array
    {
        return $company->members()->get()->map(static function (User $user): array {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->getRelationValue('pivot')?->getAttribute('role'),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function pendingInvitations(Company $company): array
    {
        return EmployeeInvitation::query()
            ->with('employee:id,name')
            ->where('company_id', $company->getKey())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->get()
            ->map(static function (EmployeeInvitation $invitation): array {
                return [
                    'id' => $invitation->getKey(),
                    'employee_name' => $invitation->employee?->name,
                    'email' => $invitation->email,
                    'phone' => $invitation->phone,
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
