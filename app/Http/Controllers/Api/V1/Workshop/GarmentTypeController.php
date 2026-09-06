<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workshop;

use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Catalogue des types de vêtement.
 */
final class GarmentTypeController extends TenantResourceController
{
    protected function entityKey(): string
    {
        return 'garment_types';
    }

    /** @return array<string, mixed> */
    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'complexity' => ['nullable', 'string', 'max:20'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return list<AllowedFilter> */
    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::partial('name'),
            AllowedFilter::exact('complexity'),
            AllowedFilter::exact('is_active'),
        ];
    }

    /** @return list<string> */
    protected function allowedSorts(): array
    {
        return ['name', 'base_price', 'created_at'];
    }
}
