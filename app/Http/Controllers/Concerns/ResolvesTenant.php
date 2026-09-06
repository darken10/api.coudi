<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Middleware\ResolveCompany;
use App\Http\Middleware\ResolveDevice;
use App\Models\Company;
use App\Models\Device;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Accès à l'atelier et à l'appareil posés par les middlewares.
 *
 * L'exception n'est pas défensive pour la forme : elle transforme un oubli de
 * middleware sur une route en panne immédiate et bruyante, plutôt qu'en requête
 * silencieusement non cloisonnée.
 */
trait ResolvesTenant
{
    protected function company(Request $request): Company
    {
        $company = $request->attributes->get(ResolveCompany::ATTRIBUTE);

        return $company instanceof Company
            ? $company
            : throw new RuntimeException('Route sans middleware `company`.');
    }

    protected function device(Request $request): Device
    {
        $device = $request->attributes->get(ResolveDevice::ATTRIBUTE);

        return $device instanceof Device
            ? $device
            : throw new RuntimeException('Route sans middleware `device`.');
    }

    protected function role(Request $request): ?string
    {
        $role = $request->attributes->get(ResolveCompany::ROLE_ATTRIBUTE);

        return is_string($role) ? $role : null;
    }

    protected function canWrite(Request $request): bool
    {
        return in_array($this->role($request), Company::WRITE_ROLES, true);
    }
}
