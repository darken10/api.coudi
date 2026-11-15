<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Met en minuscules les e-mails enregistrés avec des majuscules.
 *
 * La connexion cherche l'adresse en minuscules : « Alice@Kudi.test » ne
 * pouvait plus se connecter sur une base sensible à la casse. Une adresse dont
 * la forme minuscule existe déjà (deux comptes distincts) est laissée telle
 * quelle et signalée : fusionner des comptes est une décision humaine.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('users')->whereNotNull('email')->get(['id', 'email']);

        foreach ($rows as $row) {
            $email = is_string($row->email) ? $row->email : '';
            $lower = mb_strtolower(trim($email));

            if ($lower === $email) {
                continue;
            }

            $collision = DB::table('users')->where('email', $lower)->where('id', '!=', $row->id)->exists();

            if ($collision) {
                Log::warning('E-mail non normalisé : un autre compte porte déjà cette adresse en minuscules', ['user_id' => $row->id]);

                continue;
            }

            DB::table('users')->where('id', $row->id)->update(['email' => $lower]);
        }
    }

    public function down(): void
    {
        // Irréversible sans perte : la casse d'origine n'est pas conservée.
    }
};
