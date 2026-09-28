<?php

/*
| Messages de validation en français, limités aux règles que l'API emploie.
| Une règle absente d'ici retombe sur l'anglais (APP_FALLBACK_LOCALE) : en
| ajouter une ici en même temps qu'on l'utilise.
*/

return [

    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'max' => [
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],

    'attributes' => [
        'ability' => 'accès',
    ],

];
