<?php

namespace Modular\ConnectorDependencies;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache connection that gets used while
    | using this caching library. This connection is used when another is
    | not explicitly specified when executing a given caching function.
    |
    */
    'default' => \defined('Modular\ConnectorDependencies\MODULAR_ARES_CACHE_DRIVER') ? \Modular\ConnectorDependencies\MODULAR_ARES_CACHE_DRIVER : 'wordpress',
    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "apc", "array", "database", "file",
    |         "memcached", "redis", "dynamodb", "octane", "null"
    |
    */
    'stores' => ['apc' => ['driver' => 'apc'], 'array' => ['driver' => 'array', 'serialize' => \false], 'wordpress' => ['driver' => 'wordpress', 'prefix' => \defined('MODULAR_ARES_CACHE_PREFIX') ? \MODULAR_ARES_CACHE_PREFIX : 'modular_connector_cache_']],
    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, or DynamoDB cache
    | stores there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */
    'prefix' => \defined('MODULAR_ARES_CACHE_PREFIX') ? \MODULAR_ARES_CACHE_PREFIX : 'modular_connector_cache_',
];
