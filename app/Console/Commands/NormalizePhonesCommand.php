<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reprise des numéros enregistrés avant la normalisation E.164.
 *
 * « 70 12 34 56 » devient « +22670123456 ». Un numéro invalide est laissé
 * tel quel et signalé ; un numéro qui entrerait en collision avec un autre
 * compte (users.phone est unique) aussi — rien n'est fusionné ni supprimé.
 */
final class NormalizePhonesCommand extends Command
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'users' => ['phone', 'whatsapp'],
        'employees' => ['phone', 'whatsapp'],
        'employee_invitations' => ['phone'],
    ];

    protected $signature = 'koudi:normalize-phones {--dry-run : Affiche les changements sans rien écrire}';

    protected $description = 'Convertit les numéros de téléphone existants au format international (+226 par défaut).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $skipped = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->whereNotNull($column)->orderBy('id')->select(['id', $column])
                    ->each(function (object $row) use ($table, $column, $dry, &$changed, &$skipped): void {
                        /** @var string $raw */
                        $raw = $row->{$column};
                        /** @var int|string $id */
                        $id = $row->id;
                        $normalized = PhoneNumber::normalize($raw);

                        if ($normalized === null) {
                            $skipped[] = [$table, (string) $id, $column, $raw, 'invalide'];

                            return;
                        }

                        if ($normalized === $raw) {
                            return;
                        }

                        if ($table === 'users' && $column === 'phone'
                            && DB::table('users')->where('phone', $normalized)->where('id', '!=', $row->id)->exists()) {
                            $skipped[] = [$table, (string) $id, $column, $raw, 'déjà utilisé par un autre compte'];

                            return;
                        }

                        $changed++;

                        if (! $dry) {
                            DB::table($table)->where('id', $row->id)->update([$column => $normalized]);
                        }
                    });
            }
        }

        $this->info(sprintf('%d numéro(s) %s.', $changed, $dry ? 'à convertir' : 'convertis'));

        if ($skipped !== []) {
            $this->warn(sprintf('%d numéro(s) laissés en l’état, à corriger à la main :', count($skipped)));
            $this->table(['Table', 'Id', 'Colonne', 'Valeur', 'Motif'], $skipped);
        }

        return self::SUCCESS;
    }
}
