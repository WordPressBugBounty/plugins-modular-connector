<?php

namespace Modular\ConnectorDependencies;

if (!\defined('ABSPATH')) {
    exit;
}
/**
 * Get or generate a unique JWT signing key for this installation.
 *
 * SECURITY: This replaces the use of SECURE_AUTH_KEY which can be leaked
 * through various vectors (LFI, backups, phpinfo, etc.).
 *
 * The key is stored in the database and is unique per installation.
 *
 * @return string
 */
function modular_get_jwt_secret_key(): string
{
    $optionName = '_modular_jwt_secret_key';
    $key = \get_option($optionName);
    if (empty($key)) {
        // Generate a cryptographically secure random key
        if (\function_exists('wp_generate_password')) {
            $key = \wp_generate_password(64, \true, \true);
        } else {
            $key = \bin2hex(\random_bytes(32));
        }
        // Store it in the database
        \update_option($optionName, $key, \false);
        // false = don't autoload
    }
    return $key;
}
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
    | SECURITY: This key is now unique per installation and stored in the
    | database. It is NOT derived from SECURE_AUTH_KEY to prevent attacks
    | if wp-config.php is leaked.
    |
    | The key is automatically generated on first use.
    |
    */
    'default_key' => \function_exists('get_option') ? modular_get_jwt_secret_key() : null,
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
