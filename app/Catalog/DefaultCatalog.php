<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Catalogue par défaut : types de vêtements, champs de mesure et associations.
 *
 * C'est la source unique du catalogue livré à chaque nouvel atelier. Les
 * noms servent de clé d'idempotence : le seeder ne crée un élément que s'il
 * n'existe pas encore dans l'atelier, ce qui rend l'initialisation sans
 * doublon et sans effet sur les personnalisations locales.
 */
final class DefaultCatalog
{
    /** @return list<array{name: string, category: string}> */
    public static function measurementFields(): array
    {
        return [
            // ── Corps (mesures générales) ────────────────────────────────────
            ['name' => 'Tour de poitrine',       'category' => 'Corps'],
            ['name' => 'Tour de sous-poitrine',  'category' => 'Corps'],
            ['name' => 'Écart de poitrine',      'category' => 'Corps'],
            ['name' => 'Hauteur de poitrine',    'category' => 'Corps'],
            ['name' => 'Tour de taille',         'category' => 'Corps'],
            ['name' => 'Tour de bassin',         'category' => 'Corps'],
            ['name' => 'Tour de hanches',        'category' => 'Corps'],
            ['name' => 'Longueur du corps',      'category' => 'Corps'],
            ['name' => 'Longueur totale',        'category' => 'Corps'],
            ['name' => 'Hauteur totale',         'category' => 'Corps'],

            // ── Épaules & Dos ───────────────────────────────────────────────
            ['name' => 'Carrure',               'category' => 'Épaules & Dos'],
            ['name' => 'Largeur des épaules',   'category' => 'Épaules & Dos'],
            ['name' => 'Longueur épaule',       'category' => 'Épaules & Dos'],
            ['name' => 'Longueur du dos',       'category' => 'Épaules & Dos'],
            ['name' => 'Longueur devant',       'category' => 'Épaules & Dos'],
            ['name' => 'Largeur de dos',        'category' => 'Épaules & Dos'],
            ['name' => 'Largeur devant',        'category' => 'Épaules & Dos'],

            // ── Bras ────────────────────────────────────────────────────────
            ['name' => 'Longueur de manche',    'category' => 'Bras'],
            ['name' => 'Tour de bras',          'category' => 'Bras'],
            ['name' => 'Tour de poignet',       'category' => 'Bras'],
            ['name' => "Tour d'emmanchure",     'category' => 'Bras'],

            // ── Cou ─────────────────────────────────────────────────────────
            ['name' => 'Tour de cou',           'category' => 'Cou'],

            // ── Jambes (pantalons, jupes & robes) ───────────────────────────
            ['name' => 'Longueur pantalon',     'category' => 'Jambes'],
            ['name' => 'Longueur entrejambe',   'category' => 'Jambes'],
            ['name' => 'Tour de cuisse',        'category' => 'Jambes'],
            ['name' => 'Tour de genou',         'category' => 'Jambes'],
            ['name' => 'Tour de mollet',        'category' => 'Jambes'],
            ['name' => 'Tour de cheville',      'category' => 'Jambes'],
            ['name' => 'Tour de bas pantalon',  'category' => 'Jambes'],
            ['name' => 'Hauteur de fourche devant', 'category' => 'Jambes'],
            ['name' => 'Hauteur de fourche dos',    'category' => 'Jambes'],
            ['name' => 'Longueur jupe',         'category' => 'Jambes'],
            ['name' => 'Longueur robe',         'category' => 'Jambes'],
            ['name' => 'Hauteur de taille',     'category' => 'Jambes'],
            ['name' => 'Hauteur de hanche',     'category' => 'Jambes'],
            ['name' => 'Longueur de buste',     'category' => 'Jambes'],

            // ── Tête ────────────────────────────────────────────────────────
            ['name' => 'Tour de tête',          'category' => 'Tête'],
        ];
    }

    /**
     * @return list<array{name: string, category: string, complexity: string, fields: list<string>}>
     */
    public static function garmentTypes(): array
    {
        $shirt = [
            'Tour de cou', 'Tour de poitrine', 'Carrure', 'Largeur des épaules',
            'Longueur du corps', 'Longueur de manche', 'Tour de bras', 'Tour de poignet',
        ];
        $pants = [
            'Tour de taille', 'Tour de bassin', 'Longueur pantalon', 'Longueur entrejambe',
            'Tour de cuisse', 'Tour de genou', 'Tour de bas pantalon', 'Hauteur de fourche devant',
        ];
        $boubou = [
            'Tour de cou', 'Tour de poitrine', 'Carrure', 'Longueur du corps',
            'Longueur de manche', 'Tour de bras', 'Tour de poignet',
        ];
        $dress = [
            'Tour de poitrine', 'Tour de taille', 'Tour de bassin', 'Carrure',
            'Longueur totale', 'Longueur de buste', 'Longueur de manche', 'Tour de bras',
        ];
        $skirt = [
            'Tour de taille', 'Tour de bassin', 'Hauteur de taille', 'Hauteur de hanche', 'Longueur jupe',
        ];
        $top = [
            'Tour de poitrine', 'Tour de taille', 'Longueur du corps', 'Longueur de manche', 'Tour de bras',
        ];
        $ensemble = [
            'Tour de poitrine', 'Tour de taille', 'Tour de bassin', 'Longueur totale', 'Longueur de manche',
        ];
        $child = [
            'Tour de poitrine', 'Tour de taille', 'Longueur totale', 'Longueur de manche', 'Tour de tête',
        ];

        return [
            // ── Homme ────────────────────────────────────────────────────────
            ['name' => 'Chemise simple',           'category' => 'Homme', 'complexity' => 'simple',  'fields' => $shirt],
            ['name' => 'Chemise manches longues',  'category' => 'Homme', 'complexity' => 'simple',  'fields' => $shirt],
            ['name' => 'Chemise manches courtes',  'category' => 'Homme', 'complexity' => 'simple',  'fields' => $shirt],
            ['name' => 'Chemise traditionnelle',   'category' => 'Homme', 'complexity' => 'medium',  'fields' => $shirt],
            ['name' => 'Boubou simple',            'category' => 'Homme', 'complexity' => 'medium',  'fields' => $boubou],
            ['name' => 'Grand boubou',             'category' => 'Homme', 'complexity' => 'complex', 'fields' => $boubou],
            ['name' => 'Boubou deux pièces',       'category' => 'Homme', 'complexity' => 'complex', 'fields' => $boubou],
            ['name' => 'Boubou trois pièces',      'category' => 'Homme', 'complexity' => 'luxury',  'fields' => $boubou],
            ['name' => 'Ensemble traditionnel',    'category' => 'Homme', 'complexity' => 'complex', 'fields' => $ensemble],
            ['name' => 'Ensemble pantalon et chemise', 'category' => 'Homme', 'complexity' => 'medium', 'fields' => array_merge($pants, $shirt)],
            ['name' => 'Costume complet',          'category' => 'Homme', 'complexity' => 'complex', 'fields' => array_merge($pants, $shirt)],
            ['name' => 'Veste',                    'category' => 'Homme', 'complexity' => 'complex', 'fields' => $shirt],
            ['name' => 'Pantalon classique',       'category' => 'Homme', 'complexity' => 'simple',  'fields' => $pants],
            ['name' => 'Pantalon simple',          'category' => 'Homme', 'complexity' => 'simple',  'fields' => $pants],
            ['name' => 'Pantalon traditionnel',    'category' => 'Homme', 'complexity' => 'medium',  'fields' => $pants],
            ['name' => 'Culotte',                  'category' => 'Homme', 'complexity' => 'simple',  'fields' => $pants],
            ['name' => 'Short',                    'category' => 'Homme', 'complexity' => 'simple',  'fields' => $pants],
            ['name' => 'Tunique',                  'category' => 'Homme', 'complexity' => 'medium',  'fields' => $shirt],
            ['name' => 'Kaftan',                   'category' => 'Homme', 'complexity' => 'medium',  'fields' => $boubou],
            ['name' => 'Tenue traditionnelle',     'category' => 'Homme', 'complexity' => 'complex', 'fields' => $ensemble],
            ['name' => 'Ensemble bazin',           'category' => 'Homme', 'complexity' => 'luxury',  'fields' => $boubou],
            ['name' => 'Ensemble Faso Dan Fani',   'category' => 'Homme', 'complexity' => 'medium',  'fields' => $ensemble],

            // ── Femme ───────────────────────────────────────────────────────
            ['name' => 'Robe simple',              'category' => 'Femme', 'complexity' => 'simple',  'fields' => $dress],
            ['name' => 'Robe longue',              'category' => 'Femme', 'complexity' => 'simple',  'fields' => $dress],
            ['name' => 'Robe courte',              'category' => 'Femme', 'complexity' => 'simple',  'fields' => $dress],
            ['name' => 'Robe de soirée',           'category' => 'Femme', 'complexity' => 'complex', 'fields' => $dress],
            ['name' => 'Robe de cérémonie',        'category' => 'Femme', 'complexity' => 'luxury',  'fields' => $dress],
            ['name' => 'Robe traditionnelle',      'category' => 'Femme', 'complexity' => 'medium',  'fields' => $dress],
            ['name' => 'Robe portefeuille',        'category' => 'Femme', 'complexity' => 'medium',  'fields' => $dress],
            ['name' => 'Robe droite',              'category' => 'Femme', 'complexity' => 'simple',  'fields' => $dress],
            ['name' => 'Jupe simple',              'category' => 'Femme', 'complexity' => 'simple',  'fields' => $skirt],
            ['name' => 'Jupe longue',              'category' => 'Femme', 'complexity' => 'simple',  'fields' => $skirt],
            ['name' => 'Jupe courte',              'category' => 'Femme', 'complexity' => 'simple',  'fields' => $skirt],
            ['name' => 'Jupe évasée',              'category' => 'Femme', 'complexity' => 'simple',  'fields' => $skirt],
            ['name' => 'Ensemble jupe et haut',    'category' => 'Femme', 'complexity' => 'medium',  'fields' => array_merge($skirt, $top)],
            ['name' => 'Ensemble pantalon et haut','category' => 'Femme', 'complexity' => 'medium',  'fields' => array_merge($pants, $top)],
            ['name' => 'Pantalon femme',           'category' => 'Femme', 'complexity' => 'simple',  'fields' => $pants],
            ['name' => 'Combinaison',              'category' => 'Femme', 'complexity' => 'medium',  'fields' => $ensemble],
            ['name' => 'Tunique femme',            'category' => 'Femme', 'complexity' => 'medium',  'fields' => $shirt],
            ['name' => 'Boubou femme',             'category' => 'Femme', 'complexity' => 'medium',  'fields' => $boubou],
            ['name' => 'Grand boubou femme',       'category' => 'Femme', 'complexity' => 'complex', 'fields' => $boubou],
            ['name' => 'Kaftan femme',             'category' => 'Femme', 'complexity' => 'medium',  'fields' => $boubou],
            ['name' => 'Tenue traditionnelle femme', 'category' => 'Femme', 'complexity' => 'complex', 'fields' => $ensemble],
            ['name' => 'Tenue de mariage',         'category' => 'Femme', 'complexity' => 'luxury',  'fields' => $dress],
            ['name' => 'Tenue de cérémonie',       'category' => 'Femme', 'complexity' => 'luxury',  'fields' => $dress],
            ['name' => 'Corsage',                  'category' => 'Femme', 'complexity' => 'simple',  'fields' => $top],
            ['name' => 'Haut simple',              'category' => 'Femme', 'complexity' => 'simple',  'fields' => $top],

            // ── Enfant ──────────────────────────────────────────────────────
            ['name' => 'Chemise enfant',           'category' => 'Enfant', 'complexity' => 'simple', 'fields' => $child],
            ['name' => 'Robe enfant',              'category' => 'Enfant', 'complexity' => 'simple', 'fields' => $child],
            ['name' => 'Boubou enfant',            'category' => 'Enfant', 'complexity' => 'simple', 'fields' => $child],
            ['name' => 'Ensemble enfant',          'category' => 'Enfant', 'complexity' => 'simple', 'fields' => $child],
            ['name' => 'Pantalon enfant',          'category' => 'Enfant', 'complexity' => 'simple', 'fields' => $pants],
            ['name' => 'Short enfant',             'category' => 'Enfant', 'complexity' => 'simple', 'fields' => $pants],
            ['name' => 'Jupe enfant',              'category' => 'Enfant', 'complexity' => 'simple', 'fields' => $skirt],
            ['name' => 'Uniforme scolaire',        'category' => 'Enfant', 'complexity' => 'simple', 'fields' => $ensemble],
            ['name' => 'Tenue traditionnelle enfant', 'category' => 'Enfant', 'complexity' => 'medium', 'fields' => $ensemble],

            // ── Autres catégories ───────────────────────────────────────────
            ['name' => 'Uniforme professionnel',   'category' => 'Autre', 'complexity' => 'medium',  'fields' => $ensemble],
            ['name' => 'Tenue religieuse',         'category' => 'Autre', 'complexity' => 'medium',  'fields' => $boubou],
            ['name' => 'Tenue de groupe',          'category' => 'Autre', 'complexity' => 'medium',  'fields' => $ensemble],
            ['name' => 'Création personnalisée',   'category' => 'Autre', 'complexity' => 'simple',  'fields' => []],
        ];
    }
}
