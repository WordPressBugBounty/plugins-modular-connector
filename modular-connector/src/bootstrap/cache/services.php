<?php return array (
  'providers' => 
  array (
    0 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Auth\\AuthServiceProvider',
    1 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\BusServiceProvider',
    2 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Cache\\CacheServiceProvider',
    3 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Database\\DatabaseServiceProvider',
    4 => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    5 => 'Modular\\ConnectorDependencies\\Illuminate\\Filesystem\\FilesystemServiceProvider',
    6 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\FoundationServiceProvider',
    7 => 'Modular\\ConnectorDependencies\\Illuminate\\View\\ViewServiceProvider',
    8 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Queue\\QueueServiceProvider',
    9 => 'Modular\\Connector\\Providers\\ModularConnectorServiceProvider',
    10 => 'Modular\\Connector\\Providers\\EventServiceProvider',
    11 => 'Modular\\Connector\\Providers\\RouteServiceProvider',
  ),
  'eager' => 
  array (
    0 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Auth\\AuthServiceProvider',
    1 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Database\\DatabaseServiceProvider',
    2 => 'Modular\\ConnectorDependencies\\Illuminate\\Filesystem\\FilesystemServiceProvider',
    3 => 'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Providers\\FoundationServiceProvider',
    4 => 'Modular\\ConnectorDependencies\\Illuminate\\View\\ViewServiceProvider',
    5 => 'Modular\\Connector\\Providers\\ModularConnectorServiceProvider',
    6 => 'Modular\\Connector\\Providers\\EventServiceProvider',
    7 => 'Modular\\Connector\\Providers\\RouteServiceProvider',
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
    'migrator' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'migration.repository' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'migration.creator' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'command.migrate' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'command.migrate.fresh' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'command.migrate.install' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'command.migrate.refresh' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'command.migrate.reset' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'command.migrate.rollback' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'command.migrate.status' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
    'command.migrate.make' => 'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider',
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
    'Modular\\ConnectorDependencies\\Illuminate\\Database\\MigrationServiceProvider' => 
    array (
    ),
    'Modular\\ConnectorDependencies\\Ares\\Framework\\Foundation\\Queue\\QueueServiceProvider' => 
    array (
    ),
  ),
);