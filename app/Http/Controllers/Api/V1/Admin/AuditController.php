<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdminAction;
use App\Models\LoginAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal d'audit global : connexions à la console et décisions d'administration.
 */
final class AuditController extends ApiController
{
    public function logins(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->trim()->value();

        $audits = LoginAudit::query()
            ->with('user:id,name,email')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('email', 'like', "%{$search}%");
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return $this->success([
            'items' => $audits->getCollection()
                ->map(fn (LoginAudit $audit): array => [
                    'id' => $audit->id,
                    'email' => $audit->email,
                    'user' => $audit->user?->name,
                    'status' => $audit->status,
                    'ip' => $audit->ip,
                    'user_agent' => $audit->user_agent,
                    'created_at' => $audit->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'meta' => [
                'total' => $audits->total(),
                'page' => $audits->currentPage(),
                'per_page' => $audits->perPage(),
                'last_page' => $audits->lastPage(),
            ],
        ]);
    }

    public function actions(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();

        $actions = AdminAction::query()
            ->with(['admin:id,name,email', 'company:id,name', 'targetUser:id,name,email'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('action', 'like', "%{$search}%")
                    ->orWhereHas('admin', fn ($inner) => $inner->where('name', 'like', "%{$search}%"));
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return $this->success([
            'items' => $actions->getCollection()
                ->map(fn (AdminAction $action): array => [
                    'id' => $action->id,
                    'action' => $action->action,
                    'admin' => $action->admin?->name ?? $action->admin?->email,
                    'company_name' => $action->company?->name,
                    'target_user' => $action->targetUser?->name,
                    'meta' => $action->meta,
                    'created_at' => $action->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'meta' => [
                'total' => $actions->total(),
                'page' => $actions->currentPage(),
                'per_page' => $actions->perPage(),
                'last_page' => $actions->lastPage(),
            ],
        ]);
    }
}
