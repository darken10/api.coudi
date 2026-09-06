<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rattache une entité à son atelier.
 *
 * Le cloisonnement, lui, ne passe pas par un scope de modèle : il est imposé en
 * amont par le middleware `company` et par `SyncEntity::companyColumn()`. Un
 * scope facultatif inviterait à l'oublier, et un oubli ici ne produit pas une
 * erreur visible mais la fuite du carnet d'un atelier vers un autre.
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
}
