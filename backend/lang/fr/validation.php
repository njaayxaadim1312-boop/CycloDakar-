<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Messages de validation en français
|--------------------------------------------------------------------------
|
| L'interface est en français, y compris quand elle refuse une saisie. Sans
| ce fichier, l'API renverrait des clés brutes du type « validation.min.string »
| au membre — un message que personne ne peut comprendre ni corriger.
|
| Le repli reste l'anglais (APP_FALLBACK_LOCALE=en) : une règle exotique non
| traduite affiche alors un message anglais correct plutôt qu'une clé.
|
*/

return [

    'accepted' => 'Le champ :attribute doit être accepté.',
    'accepted_if' => 'Le champ :attribute doit être accepté quand :other vaut :value.',
    'active_url' => "Le champ :attribute n'est pas une URL valide.",
    'after' => 'Le champ :attribute doit être une date postérieure au :date.',
    'after_or_equal' => 'Le champ :attribute doit être une date postérieure ou égale au :date.',
    'alpha' => 'Le champ :attribute ne peut contenir que des lettres.',
    'alpha_dash' => 'Le champ :attribute ne peut contenir que des lettres, des chiffres, des tirets et des tirets bas.',
    'alpha_num' => 'Le champ :attribute ne peut contenir que des lettres et des chiffres.',
    'any_of' => "Le champ :attribute n'est pas valide.",
    'array' => 'Le champ :attribute doit être une liste.',
    'array_keys' => 'Le champ :attribute doit contenir les entrées : :values.',
    'ascii' => 'Le champ :attribute ne peut contenir que des caractères et symboles alphanumériques simples.',
    'base64' => 'Le champ :attribute doit être encodé en base64.',
    'before' => 'Le champ :attribute doit être une date antérieure au :date.',
    'before_or_equal' => 'Le champ :attribute doit être une date antérieure ou égale au :date.',

    'between' => [
        'array' => 'Le champ :attribute doit contenir entre :min et :max éléments.',
        'file' => 'Le fichier :attribute doit peser entre :min et :max kilo-octets.',
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit contenir entre :min et :max caractères.',
    ],

    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'can' => 'Le champ :attribute contient une valeur non autorisée.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'contains' => 'Il manque une valeur obligatoire dans le champ :attribute.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => "Le champ :attribute n'est pas une date valide.",
    'date_equals' => 'Le champ :attribute doit être une date égale au :date.',
    'date_format' => 'Le champ :attribute ne correspond pas au format :format.',
    'decimal' => 'Le champ :attribute doit avoir :decimal décimales.',
    'declined' => 'Le champ :attribute doit être refusé.',
    'declined_if' => 'Le champ :attribute doit être refusé quand :other vaut :value.',
    'different' => 'Les champs :attribute et :other doivent être différents.',
    'digits' => 'Le champ :attribute doit contenir :digits chiffres.',
    'digits_between' => 'Le champ :attribute doit contenir entre :min et :max chiffres.',
    'dimensions' => "Les dimensions de l'image :attribute ne sont pas valides.",
    'distinct' => 'Le champ :attribute contient une valeur en double.',
    'doesnt_contain' => 'Le champ :attribute ne doit contenir aucune des valeurs suivantes : :values.',
    'doesnt_end_with' => 'Le champ :attribute ne doit pas se terminer par : :values.',
    'doesnt_start_with' => 'Le champ :attribute ne doit pas commencer par : :values.',
    'email' => "Le champ :attribute doit être une adresse email valide.",
    'ends_with' => 'Le champ :attribute doit se terminer par : :values.',
    'enum' => "La valeur du champ :attribute n'est pas valide.",
    'exists' => "La valeur du champ :attribute n'existe pas.",
    'extensions' => 'Le fichier :attribute doit avoir une des extensions suivantes : :values.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'filled' => 'Le champ :attribute doit être renseigné.',

    'gt' => [
        'array' => 'Le champ :attribute doit contenir plus de :value éléments.',
        'file' => 'Le fichier :attribute doit peser plus de :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être supérieur à :value.',
        'string' => 'Le champ :attribute doit contenir plus de :value caractères.',
    ],

    'gte' => [
        'array' => 'Le champ :attribute doit contenir au moins :value éléments.',
        'file' => 'Le fichier :attribute doit peser au moins :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être supérieur ou égal à :value.',
        'string' => 'Le champ :attribute doit contenir au moins :value caractères.',
    ],

    'hex_color' => 'Le champ :attribute doit être une couleur hexadécimale valide.',
    'image' => 'Le champ :attribute doit être une image.',
    'in' => "La valeur du champ :attribute n'est pas valide.",
    'in_array' => "La valeur du champ :attribute n'existe pas dans :other.",
    'in_array_keys' => 'Le champ :attribute doit contenir au moins une des clés suivantes : :values.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'ip' => 'Le champ :attribute doit être une adresse IP valide.',
    'ipv4' => 'Le champ :attribute doit être une adresse IPv4 valide.',
    'ipv6' => 'Le champ :attribute doit être une adresse IPv6 valide.',
    'json' => 'Le champ :attribute doit être un document JSON valide.',
    'list' => 'Le champ :attribute doit être une liste.',
    'lowercase' => 'Le champ :attribute doit être en minuscules.',

    'lt' => [
        'array' => 'Le champ :attribute doit contenir moins de :value éléments.',
        'file' => 'Le fichier :attribute doit peser moins de :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être inférieur à :value.',
        'string' => 'Le champ :attribute doit contenir moins de :value caractères.',
    ],

    'lte' => [
        'array' => 'Le champ :attribute doit contenir au plus :value éléments.',
        'file' => 'Le fichier :attribute doit peser au plus :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être inférieur ou égal à :value.',
        'string' => 'Le champ :attribute doit contenir au plus :value caractères.',
    ],

    'mac_address' => 'Le champ :attribute doit être une adresse MAC valide.',

    'max' => [
        'array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
        'file' => 'Le fichier :attribute ne peut pas peser plus de :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne peut pas dépasser :max.',
        'string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
    ],

    'max_digits' => 'Le champ :attribute ne peut pas contenir plus de :max chiffres.',
    'mimes' => 'Le fichier :attribute doit être de type : :values.',
    'mimetypes' => 'Le fichier :attribute doit être de type : :values.',

    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le fichier :attribute doit peser au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],

    'min_digits' => 'Le champ :attribute doit contenir au moins :min chiffres.',
    'missing' => 'Le champ :attribute doit être absent.',
    'missing_if' => 'Le champ :attribute doit être absent quand :other vaut :value.',
    'missing_unless' => 'Le champ :attribute doit être absent sauf si :other vaut :value.',
    'missing_with' => 'Le champ :attribute doit être absent quand :values est présent.',
    'missing_with_all' => 'Le champ :attribute doit être absent quand :values sont présents.',
    'multiple_of' => 'Le champ :attribute doit être un multiple de :value.',
    'not_in' => "La valeur du champ :attribute n'est pas valide.",
    'not_regex' => "Le format du champ :attribute n'est pas valide.",
    'numeric' => 'Le champ :attribute doit être un nombre.',

    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un caractère spécial.',
        'uncompromised' => "Ce :attribute figure dans une fuite de données connue. Choisissez-en un autre.",
    ],

    'present' => 'Le champ :attribute doit être présent.',
    'present_if' => 'Le champ :attribute doit être présent quand :other vaut :value.',
    'present_unless' => 'Le champ :attribute doit être présent sauf si :other vaut :value.',
    'present_with' => 'Le champ :attribute doit être présent quand :values est présent.',
    'present_with_all' => 'Le champ :attribute doit être présent quand :values sont présents.',
    'prohibited' => "Le champ :attribute n'est pas autorisé.",
    'prohibited_if' => "Le champ :attribute n'est pas autorisé quand :other vaut :value.",
    'prohibited_if_accepted' => "Le champ :attribute n'est pas autorisé quand :other est accepté.",
    'prohibited_if_declined' => "Le champ :attribute n'est pas autorisé quand :other est refusé.",
    'prohibited_unless' => "Le champ :attribute n'est pas autorisé sauf si :other fait partie de :values.",
    'prohibits' => "Le champ :attribute interdit la présence de :other.",
    'regex' => "Le format du champ :attribute n'est pas valide.",
    'required' => 'Le champ :attribute est obligatoire.',
    'required_array_keys' => 'Le champ :attribute doit contenir les entrées : :values.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'required_if_accepted' => 'Le champ :attribute est obligatoire quand :other est accepté.',
    'required_if_declined' => 'Le champ :attribute est obligatoire quand :other est refusé.',
    'required_unless' => 'Le champ :attribute est obligatoire sauf si :other fait partie de :values.',
    'required_with' => 'Le champ :attribute est obligatoire quand :values est présent.',
    'required_with_all' => 'Le champ :attribute est obligatoire quand :values sont présents.',
    'required_without' => 'Le champ :attribute est obligatoire quand :values est absent.',
    'required_without_all' => 'Le champ :attribute est obligatoire quand aucun de :values n\'est présent.',
    'same' => 'Les champs :attribute et :other doivent être identiques.',

    'size' => [
        'array' => 'Le champ :attribute doit contenir :size éléments.',
        'file' => 'Le fichier :attribute doit peser :size kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string' => 'Le champ :attribute doit contenir :size caractères.',
    ],

    'starts_with' => 'Le champ :attribute doit commencer par : :values.',
    'string' => 'Le champ :attribute doit être du texte.',
    'timezone' => 'Le champ :attribute doit être un fuseau horaire valide.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'uploaded' => "Le fichier :attribute n'a pas pu être envoyé.",
    'uppercase' => 'Le champ :attribute doit être en majuscules.',
    'url' => 'Le champ :attribute doit être une URL valide.',
    'ulid' => 'Le champ :attribute doit être un ULID valide.',
    'uuid' => 'Le champ :attribute doit être un UUID valide.',

    /*
    |--------------------------------------------------------------------------
    | Messages personnalisés
    |--------------------------------------------------------------------------
    |
    | Les messages propres à un champ précis vivent dans les Form Requests
    | concernées, au plus près de la règle. Cette section reste disponible pour
    | les cas transversaux.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'message-personnalisé',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Noms des champs
    |--------------------------------------------------------------------------
    |
    | Remplace « Le champ first_name est obligatoire » par « Le champ prénom
    | est obligatoire ». Les noms définis ici valent pour toute l'application ;
    | un cas particulier se surcharge dans la Form Request concernée.
    |
    */

    'attributes' => [
        'name' => 'nom',
        'first_name' => 'prénom',
        'last_name' => 'nom',
        'email' => 'adresse email',
        'phone' => 'numéro de téléphone',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'current_password' => 'mot de passe actuel',
        'login' => 'identifiant',
        'role' => 'rôle',
        'status' => 'statut',
        'matricule' => 'matricule',
        'photo' => 'photo',
        'birth_date' => 'date de naissance',
        'gender' => 'genre',
        'joined_at' => "date d'adhésion",
        'notes' => 'notes',
        'reason' => 'motif',
        'search' => 'recherche',
        'per_page' => 'nombre par page',
        'amount' => 'montant',
        'method' => 'moyen de paiement',
        'description' => 'description',
        'title' => 'titre',
        'starts_at' => 'date de début',
        'ends_at' => 'date de fin',
        'sport' => 'sport',
        'distance' => 'distance',
        'duration' => 'durée',
    ],

];
