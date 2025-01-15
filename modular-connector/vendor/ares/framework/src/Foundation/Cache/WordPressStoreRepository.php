<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Cache;

use Modular\ConnectorDependencies\Illuminate\Cache\Events\KeyForgotten;
use Modular\ConnectorDependencies\Illuminate\Cache\Repository;
class WordPressStoreRepository extends Repository
{
    /**
     * Create a new WordPress cache repository.
     *
     * @param WordPressStore $store
     */
    public function __construct(WordPressStore $store)
    {
        parent::__construct($store);
    }
    /**
     * Remove an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget($key)
    {
        return \Modular\ConnectorDependencies\tap($this->store->forget($this->itemKey($key)), function ($result) use ($key) {
            if ($result && class_exists(KeyForgotten::class)) {
                $this->event(new KeyForgotten($key));
            }
        });
    }
}
