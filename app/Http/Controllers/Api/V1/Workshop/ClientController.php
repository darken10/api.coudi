<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workshop;

use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Carnet de clients de l'atelier.
 */
final class ClientController extends TenantResourceController
{
    protected function entityKey(): string
    {
        return 'clients';
    }

    /** @return array<string, mixed> */
    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'photo_uri' => ['nullable', 'string', 'max:2048'],
            'birth_date' => ['nullable', 'date'],
            'event_date' => ['nullable', 'date'],
            'event_label' => ['nullable', 'string', 'max:255'],
            'is_vip' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return list<AllowedFilter> */
    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::partial('name'),
            AllowedFilter::partial('phone'),
            AllowedFilter::partial('email'),
            AllowedFilter::exact('is_vip'),
            AllowedFilter::exact('is_active'),
        ];
    }

    /** @return list<string> */
    protected function allowedSorts(): array
    {
        return ['name', 'created_at', 'updated_at'];
    }
}
