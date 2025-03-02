<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hashing Algorithm
    |--------------------------------------------------------------------------
    |
    | This sets the default hashing algorithm used to generate and verify
    | JWTs.
    |
    | Supported: HS256, HS384, HS512, RS256, RS384, RS512
    |
    */

    'algorithm' => 'HS512',

    /*
    |--------------------------------------------------------------------------
    | Default JWT Key
    |--------------------------------------------------------------------------
    |
    | This is the default key used to sign the generated JWT tokens.
    | If not set or specified on the generate function,
    | an insecure one will be generated.
    |
    */

    'default_key' => defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : null,

    /*
    |--------------------------------------------------------------------------
    | Default JWT Expiration
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default expiration time in minutes for the generated
    | JWTs. If not specified, it will default to 6 hours.
    |
    */

    'default_expiration' => 6 * 60,

];
