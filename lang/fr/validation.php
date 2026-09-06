<?php

declare(strict_types=1);

/*
 * Messages de validation en français.
 *
 * Traduit les règles effectivement utilisées par l'application. Les clés
 * absentes retombent sur APP_FALLBACK_LOCALE (anglais), ce qui évite qu'une
 * règle ajoutée plus tard réapparaisse sous forme de clé brute.
 */

return [
    'array' => 'Le champ :attribute doit être une liste.',
    'boolean' => 'Le champ :attribute doit valoir vrai ou faux.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'date' => 'Le champ :attribute doit être une date valide.',
    'digits' => 'Le champ :attribute doit comporter :digits chiffres.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'exists' => 'La valeur du champ :attribute est introuvable.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'in' => 'La valeur du champ :attribute n\'est pas autorisée.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'mimes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'required_without' => 'Le champ :attribute est obligatoire quand :values est absent.',
    'string' => 'Le champ :attribute doit être du texte.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',
    'url' => 'Le champ :attribute doit être une URL valide.',
    'uuid' => 'Le champ :attribute doit être un identifiant valide.',

    'max' => [
        'array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
        'file' => 'Le champ :attribute ne peut pas dépasser :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne peut pas dépasser :max.',
        'string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
    ],

    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le champ :attribute doit peser au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],

    /*
     * Noms lisibles : sans cela, le message afficherait « log_api_bodies »
     * plutôt qu'un libellé compréhensible.
     */
    'attributes' => [
        'email' => 'adresse e-mail',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'name' => 'nom',
        'phone' => 'téléphone',
        'address' => 'adresse',
        'website' => 'site web',
        'status' => 'état',
        'code' => 'code de vérification',
        'recovery_code' => 'code de secours',
        'challenge' => 'session de vérification',
        'company_id' => 'atelier',
        'action' => 'action',
        'file_name' => 'nom de fichier',
        'bundle' => 'archive',
        'log_rotation' => 'rotation des journaux',
        'log_retention' => 'fichiers conservés',
        'log_level' => 'niveau de détail',
        'log_api_calls' => 'journalisation des appels',
        'log_api_bodies' => 'contenu des requêtes',
    ],
];
