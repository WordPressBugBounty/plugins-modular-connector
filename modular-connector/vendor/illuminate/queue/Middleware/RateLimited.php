<?php

namespace Modular\ConnectorDependencies\Illuminate\Queue\Middleware;

use Modular\ConnectorDependencies\Illuminate\Cache\RateLimiter;
use Modular\ConnectorDependencies\Illuminate\Cache\RateLimiting\Unlimited;
use Modular\ConnectorDependencies\Illuminate\Container\Container;
use Modular\ConnectorDependencies\Illuminate\Support\Arr;
class RateLimited
{
    /**
     * The rate limiter instance.
     *
     * @var \Illuminate\Cache\RateLimiter
     */
    protected $limiter;
    /**
     * The name of the rate limiter.
     *
     * @var string
     */
    protected $limiterName;
    /**
     * Indicates if the job should be released if the limit is exceeded.
     *
     * @var bool
     */
    public $shouldRelease = \true;
    /**
     * Create a new middleware instance.
     *
     * @param  string  $limiterName
     * @return void
     */
    public function __construct($limiterName)
    {
        $this->limiter = Container::getInstance()->make(RateLimiter::class);
        $this->limiterName = $limiterName;
    }
    /**
     * Process the job.
     *
     * @param  mixed  $job
     * @param  callable  $next
     * @return mixed
     */
    public function handle($job, $next)
    {
        if (is_null($limiter = $this->limiter->limiter($this->limiterName))) {
            return $next($job);
        }
        $limiterResponse = call_user_func($limiter, $job);
        if ($limiterResponse instanceof Unlimited) {
            return $next($job);
        }
        return $this->handleJob($job, $next, \Modular\ConnectorDependencies\collect(Arr::wrap($limiterResponse))->map(function ($limit) {
            return (object) ['key' => md5($this->limiterName . $limit->key), 'maxAttempts' => $limit->maxAttempts, 'decayMinutes' => $limit->decayMinutes];
        })->all());
    }
    /**
     * Handle a rate limited job.
     *
     * @param  mixed  $job
     * @param  callable  $next
     * @param  array  $limits
     * @return mixed
     */
    protected function handleJob($job, $next, array $limits)
    {
        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                return $this->shouldRelease ? $job->release($this->getTimeUntilNextRetry($limit->key)) : \false;
            }
            $this->limiter->hit($limit->key, $limit->decayMinutes * 60);
        }
        return $next($job);
    }
    /**
     * Do not release the job back to the queue if the limit is exceeded.
     *
     * @return $this
     */
    public function dontRelease()
    {
        $this->shouldRelease = \false;
        return $this;
    }
    /**
     * Get the number of seconds that should elapse before the job is retried.
     *
     * @param  string  $key
     * @return int
     */
    protected function getTimeUntilNextRetry($key)
    {
        return $this->limiter->availableIn($key) + 3;
    }
    /**
     * Prepare the object for serialization.
     *
     * @return array
     */
    public function __sleep()
    {
        return ['limiterName', 'shouldRelease'];
    }
    /**
     * Prepare the object after unserialization.
     *
     * @return void
     */
    public function __wakeup()
    {
        $this->limiter = Container::getInstance()->make(RateLimiter::class);
    }
}
