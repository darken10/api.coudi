<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Suspend les ateliers dont l'abonnement est en retard de paiement.
 *
 * À exécuter régulièrement (planificateur). Ne touche jamais à un atelier
 * dont l'abonnement est actif ou annulé.
 */
final class BillingSuspendOverdue extends Command
{
    protected $signature = 'billing:suspend-overdue';

    protected $description = 'Suspendre les ateliers dont l’abonnement est en retard de paiement.';

    public function handle(): int
    {
        $companyIds = Subscription::query()
            ->where('status', Subscription::STATUS_PAST_DUE)
            ->pluck('company_id');

        $suspended = Company::query()
            ->whereIn('id', $companyIds)
            ->where('status', Company::STATUS_ACTIVE)
            ->update(['status' => Company::STATUS_SUSPENDED]);

        $this->info(sprintf('%d atelier(s) suspendu(s).', $suspended));

        return self::SUCCESS;
    }
}
