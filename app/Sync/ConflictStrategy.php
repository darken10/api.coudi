<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * Comment arbitrer quand deux appareils ont touché la même donnée.
 *
 * Le choix n'est pas technique mais métier : appliquer partout le même
 * arbitrage ferait perdre de l'argent sur les encaissements et créerait des
 * doublons sur les mesures.
 */
enum ConflictStrategy: string
{
    /**
     * Dernier écrivain gagnant, ligne entière.
     *
     * Pour les fiches descriptives — client, employé, modèle. Deux appareils
     * qui corrigent le même numéro de téléphone : le plus récent l'emporte,
     * et perdre l'autre correction est sans gravité.
     */
    case LastWriteWins = 'lww';

    /**
     * Ajout seul : jamais de mise à jour, jamais de conflit.
     *
     * Pour les encaissements, les journaux de travail, les notes. Deux
     * appareils qui enregistrent chacun un acompte hors ligne doivent produire
     * deux paiements ; un arbitrage au dernier écrivain en effacerait un.
     */
    case AppendOnly = 'append_only';

    /**
     * Rapprochement sur clé naturelle avant l'identifiant.
     *
     * Pour les lignes contraintes par un uplet unique — une mesure est
     * identifiée par (client, type de vêtement, champ), pas par son UUID. Deux
     * appareils relevant la même mesure hors ligne produisent deux UUID pour
     * une seule ligne possible : le serveur garde la sienne et renvoie son
     * identifiant, que l'appareil adopte.
     *
     * Ces entités sont toutes des feuilles — rien ne les référence — donc
     * réécrire l'identifiant côté mobile ne coûte qu'un UPDATE d'une ligne.
     */
    case NaturalKeyUpsert = 'natural_key';
}
