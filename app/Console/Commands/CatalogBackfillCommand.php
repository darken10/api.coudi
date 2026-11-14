<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Catalog\CatalogSeeder;
use Illuminate\Console\Command;

/**
 * Ajoute les éléments de catalogue manquants aux ateliers existants.
 *
 * Ne supprime ni n'écrase jamais rien : seuls les types de vêtements, champs
 * de mesure et associations absents sont créés. Les personnalisations locales
 * (renommages, champs ajoutés, associations retirées) sont conservées.
 */
final class CatalogBackfillCommand extends Command
{
    protected $signature = 'catalog:backfill {company? : Identifiant d’un atelier précis}';

    protected $description = 'Complète le catalogue des ateliers existants avec les données par défaut manquantes.';

    public function handle(CatalogSeeder $seeder): int
    {
        $companies = $this->argument('company') !== null
            ? Company::query()->whereKey($this->argument('company'))->get()
            : Company::query()->orderBy('created_at')->get();

        if ($companies->isEmpty()) {
            $this->warn('Aucun atelier à traiter.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $counts = $seeder->seed($company);

            $this->info(sprintf(
                '[%s] vêtements : +%d, champs : +%d, associations : +%d',
                $company->name,
                $counts['garments'],
                $counts['fields'],
                $counts['associations'],
            ));
        }

        return self::SUCCESS;
    }
}
