<?php return array (
  'providers' => 
  array (
    0 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Auth\\AuthServiceProvider',
    1 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\BusServiceProvider',
    2 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Cache\\CacheServiceProvider',
    3 => 'Modular\\ConnectorDependencies\\Illuminate\\Filesystem\\FilesystemServiceProvider',
    4 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\FoundationServiceProvider',
    5 => 'Modular\\ConnectorDependencies\\Illuminate\\View\\ViewServiceProvider',
    6 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Queue\\QueueServiceProvider',
    7 => 'Modular\\Connector\\Providers\\ModularConnectorServiceProvider',
    8 => 'Modular\\Connector\\Providers\\EventServiceProvider',
    9 => 'Modular\\Connector\\Providers\\RouteServiceProvider',
  ),
  'eager' => 
  array (
    0 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Auth\\AuthServiceProvider',
    1 => 'Modular\\ConnectorDependencies\\Illuminate\\Filesystem\\FilesystemServiceProvider',
    2 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\FoundationServiceProvider',
    3 => 'Modular\\ConnectorDependencies\\Illuminate\\View\\ViewServiceProvider',
    4 => 'Modular\\Connector\\Providers\\ModularConnectorServiceProvider',
    5 => 'Modular\\Connector\\Providers\\EventServiceProvider',
    6 => 'Modular\\Connector\\Providers\\RouteServiceProvider',
  ),
  'deferred' => 
  array (
    'Modular\\ConnectorDependencies\\Illuminate\\Bus\\Dispatcher' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\BusServiceProvider',
    'Modular\\ConnectorDependencies\\Illuminate\\Contracts\\Bus\\Dispatcher' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\BusServiceProvider',
    'Modular\\ConnectorDependencies\\Illuminate\\Contracts\\Bus\\QueueingDispatcher' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\BusServiceProvider',
    'Modular\\ConnectorDependencies\\Illuminate\\Bus\\BatchRepository' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\BusServiceProvider',
    'Modular\\ConnectorDependencies\\Illuminate\\Bus\\DatabaseBatchRepository' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\BusServiceProvider',
    'cache' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Cache\\CacheServiceProvider',
    'cache.store' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Cache\\CacheServiceProvider',
    'cache.psr6' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Cache\\CacheServiceProvider',
    'memcached.connector' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Cache\\CacheServiceProvider',
    'Modular\\ConnectorDependencies\\Illuminate\\Cache\\RateLimiter' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Cache\\CacheServiceProvider',
    'queue' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Queue\\QueueServiceProvider',
    'queue.connection' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Queue\\QueueServiceProvider',
    'queue.failer' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Queue\\QueueServiceProvider',
    'queue.listener' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Queue\\QueueServiceProvider',
    'queue.worker' => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Queue\\QueueServiceProvider',
  ),
  'when' => 
  array (
    'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\BusServiceProvider' => 
    array (
    ),
    'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Cache\\CacheServiceProvider' => 
    array (
    ),
    'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Queue\\QueueServiceProvider' => 
    array (
    ),
  ),
);