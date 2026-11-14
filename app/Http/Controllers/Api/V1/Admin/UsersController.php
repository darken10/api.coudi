<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pilotage des comptes depuis la console.
 *
 * Un compte peut être propriétaire (plusieurs ateliers), tailleur (un ou
 * plusieurs ateliers) ou en attente d'activation. La console doit pouvoir les
 * retrouver, comprendre leur état et suspendre ou réactiver un accès.
 */
final class UsersController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->trim()->value();

        if ($status !== '' && ! in_array($status, [
            User::STATUS_ACTIVE,
            User::STATUS_PENDING_ACTIVATION,
            User::STATUS_SUSPENDED,
        ], true)) {
            return $this->validationError(['status' => ['État inconnu.']]);
        }

        $users = User::query()
            ->withCount('companies')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when(
                $request->filled('admin'),
                fn ($query) => $query->where('role', $request->boolean('admin')
                    ? User::ROLE_SUPER_ADMIN
                    : User::ROLE_USER)
            )
            ->orderBy('created_at', 'desc')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return $this->success([
            'items' => $users->getCollection()->map(fn (User $user): array => $this->row($user))->values()->all(),
            'meta' => [
                'total' => $users->total(),
                'page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'last_page' => $users->lastPage(),
            ],
            'counts' => [
                'total' => User::query()->count(),
                'active' => User::query()->where('status', User::STATUS_ACTIVE)->count(),
                'pending_activation' => User::query()->where('status', User::STATUS_PENDING_ACTIVATION)->count(),
                'suspended' => User::query()->where('status', User::STATUS_SUSPENDED)->count(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $memberships = $user->companies()->get()->map(static fn ($company): array => [
            'company_id' => $company->getKey(),
            'name' => $company->name,
            'role' => $company->getRelationValue('pivot')?->getAttribute('role'),
        ])->values()->all();

        $employees = $user->employees()->get()->map(static fn ($employee): array => [
            'id' => $employee->getKey(),
            'company_id' => $employee->company_id,
            'name' => $employee->name,
            'role' => $employee->role,
        ])->values()->all();

        return $this->success($this->row($user) + [
            'whatsapp' => $user->whatsapp,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'memberships' => $memberships,
            'employees' => $employees,
            'devices_count' => $user->devices()->count(),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED])],
        ]);

        if ($data['status'] === User::STATUS_SUSPENDED && $user->isSuperAdmin()) {
            return $this->forbidden('Un compte administrateur ne peut pas être suspendu.');
        }

        $user->update(['status' => $data['status']]);

        /** @var User|null $admin */
        $admin = $request->user();
        AdminAction::record('user.status_updated', $admin, null, $user, ['status' => $data['status']]);

        return $this->success($this->row($user), 'État du compte mis à jour.');
    }

    /** @return array<string, mixed> */
    private function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'is_super_admin' => $user->isSuperAdmin(),
            'companies_count' => $user->companies_count ?? $user->companies()->count(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
