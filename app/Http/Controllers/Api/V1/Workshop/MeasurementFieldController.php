<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workshop;

use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Champs de mesure propres à l'atelier.
 */
final class MeasurementFieldController extends TenantResourceController
{
    protected function entityKey(): string
    {
        return 'measurement_fields';
    }

    /** @return array<string, mixed> */
    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return list<AllowedFilter> */
    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::partial('name'),
            AllowedFilter::exact('category'),
        ];
    }

    /** @return list<string> */
    protected function allowedSorts(): array
    {
        return ['sort_order', 'name', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'sort_order';
    }
}
