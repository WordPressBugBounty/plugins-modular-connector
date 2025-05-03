<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Cache;

use Modular\ConnectorDependencies\Illuminate\Cache\Lock;
class WordPressLock extends Lock
{
    /**
     * The cache store implementation.
     *
     * @var WordPressStore
     */
    protected $cache;
    /**
     * Create a new lock instance.
     *
     * @param WordPressStore $cache
     * @param string $name
     * @param int $seconds
     * @param string|null $owner
     */
    public function __construct(WordPressStore $cache, $name, $seconds, $owner = null)
    {
        $owner ??= $this->generateOwner();
        parent::__construct($name, $seconds, $owner);
        $this->cache = $cache;
    }
    /**
     * @return bool
     */
    public function acquire()
    {
        $currentOwner = get_transient($this->name);
        // If there is no lock or if the lock is ours, then we acquire it.
        if (!$currentOwner || $this->isOwnedByCurrentProcess()) {
            return set_transient($this->name, $this->owner, $this->seconds);
        }
        return \false;
    }
    /**
     * Release the lock.
     *
     * @return bool
     */
    public function release()
    {
        // We can only release the lock if we are the current owners.
        if ($this->isOwnedByCurrentProcess()) {
            return delete_transient($this->name);
        }
        return \false;
    }
    /**
     * Releases this lock in disregard of ownership.
     *
     * @return void
     */
    public function forceRelease()
    {
        delete_transient($this->name);
    }
    /**
     * @return mixed|string
     */
    protected function getCurrentOwner()
    {
        return get_transient($this->name);
    }
    /**
     * Get the current owner identifier.
     *
     * @return string
     * @throws \Random\RandomException
     */
    protected function generateOwner()
    {
        return bin2hex(random_bytes(16));
    }
}
