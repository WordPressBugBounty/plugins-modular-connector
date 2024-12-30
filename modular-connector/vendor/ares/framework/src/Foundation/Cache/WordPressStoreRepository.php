<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Cache;

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
}
