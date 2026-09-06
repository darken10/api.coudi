<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rattache une entité à son atelier.
 *
 * `forCompany()` est le seul point d'entrée admis dans les contrôleurs : une
 * requête qui ne passe pas par lui fuiterait les données d'un autre locataire.
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany(Builder $query, Company|string $company): Builder
    {
        return $query->where(
            $this->qualifyColumn('company_id'),
            $company instanceof Company ? $company->getKey() : $company,
        );
    }
}
