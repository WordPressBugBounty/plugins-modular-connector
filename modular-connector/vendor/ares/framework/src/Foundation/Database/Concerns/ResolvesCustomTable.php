<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns;

trait ResolvesCustomTable
{
    /**
     * Strip the connection prefix from a fully-qualified custom table name.
     *
     * WordPress constants like CUSTOM_USER_TABLE contain the full table name
     * (e.g., 'prefix_custom_users'). Since Eloquent re-adds the prefix automatically,
     * we strip it here to avoid duplication.
     *
     * @param string $customTable Full table name from WordPress constant
     * @return string Table name without the connection prefix
     */
    protected function resolveCustomTable(string $customTable): string
    {
        $prefix = $this->getConnection()->getTablePrefix();
        if ($prefix !== '' && substr($customTable, 0, strlen($prefix)) === $prefix) {
            return substr($customTable, strlen($prefix));
        }
        return $customTable;
    }
}
