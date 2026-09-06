<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workshop;

use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Personnel de l'atelier.
 */
final class EmployeeController extends TenantResourceController
{
    protected function entityKey(): string
    {
        return 'employees';
    }

    /** @return array<string, mixed> */
    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'max:20'],
            'hired_at' => ['nullable', 'date'],
            'payment_type' => ['nullable', 'in:monthly,weekly,daily,per_piece'],
            'rate_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return list<AllowedFilter> */
    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::partial('name'),
            AllowedFilter::exact('role'),
            AllowedFilter::exact('status'),
            AllowedFilter::exact('payment_type'),
        ];
    }

    /** @return list<string> */
    protected function allowedSorts(): array
    {
        return ['name', 'hired_at', 'created_at'];
    }
}
