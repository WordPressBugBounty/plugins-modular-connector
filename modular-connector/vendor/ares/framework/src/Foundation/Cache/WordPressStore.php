<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Cache;

use Modular\ConnectorDependencies\Illuminate\Contracts\Cache\LockProvider;
use Modular\ConnectorDependencies\Illuminate\Contracts\Cache\Store;
class WordPressStore implements Store, LockProvider
{
    /**
     * A string that should be prepended to keys.
     *
     * @var string
     */
    protected $prefix;
    /**
     * @param $prefix
     */
    public function __construct($prefix = '')
    {
        $this->prefix = $prefix;
    }
    /**
     * Get a lock instance.
     *
     * @param string $name
     * @param int $seconds
     * @param null|string $owner
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new WordPressLock($this, $this->getKey($name), $seconds, $owner);
    }
    /**
     * Restore a lock instance using the owner identifier.
     *
     * @param string $name
     * @param string $owner
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }
    /**
     * Retrieve an item from the cache by key.
     *
     * @param string $key
     * @return mixed
     */
    public function getKey($key)
    {
        return $this->prefix . $key;
    }
    /**
     * Retrieve an item from the cache by key.
     *
     * @param string|array $key
     * @return mixed
     */
    public function get($key)
    {
        $key = $this->getKey($key);
        $value = get_option($key);
        return $value !== \false ? $value : null;
    }
    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     *
     * @param array $keys
     * @return array
     */
    public function many(array $keys)
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }
    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param string $key
     * @param mixed $value
     * @param int $seconds
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        $key = $this->getKey($key);
        return update_option($key, $value);
    }
    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param array $values
     * @param int $seconds
     * @return bool
     */
    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }
        return \true;
    }
    /**
     * Increment the value of an item in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        $value = (int) $value;
        $current = $this->get($key);
        $current = is_numeric($current) ? (int) $current : 0;
        $newValue = $current + $value;
        return $this->put($key, $newValue, 0);
    }
    /**
     * Decrement the value of an item in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        $value = (int) $value;
        $current = $this->get($key);
        $current = is_numeric($current) ? (int) $current : 0;
        $newValue = $current - $value;
        return $this->put($key, $newValue, 0);
    }
    /**
     * Store an item in the cache indefinitely.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }
    /**
     * Remove an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget($key)
    {
        $key = $this->getKey($key);
        return delete_option($key);
    }
    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush()
    {
        global $wpdb;
        // Get the appropriate table and option name column
        $table = $wpdb->options;
        $nameColumn = 'option_name';
        // Prepare the LIKE pattern
        $likePattern = '%' . $wpdb->esc_like($this->prefix) . '%';
        // Prepare the SQL query to delete the transients
        $sql = $wpdb->prepare("DELETE FROM {$table} WHERE {$nameColumn} LIKE %s", $likePattern);
        // Execute the query
        $result = $wpdb->query($sql);
        // Return true if the query was successful
        return $result !== \false;
    }
    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }
}
