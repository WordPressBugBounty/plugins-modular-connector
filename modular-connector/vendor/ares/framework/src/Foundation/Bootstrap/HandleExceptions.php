<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Bootstrap;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Contracts\Foundation\Application;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bootstrap\HandleExceptions as FoundationHandleExceptions;
use Modular\ConnectorDependencies\Illuminate\Log\LogManager;
class HandleExceptions extends FoundationHandleExceptions
{
    /**
     * Bootstrap the given application.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        self::$reservedMemory = \str_repeat('x', 10240);
        $this->app = $app;
        if (!(HttpUtils::isDirectRequest() || HttpUtils::isCron())) {
            return;
        }
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    /**
     * Reports a error as a warning.
     *
     * @param string $message
     * @param string $file
     * @param int $line
     * @return void
     */
    public function handleAsWarning($message, $file, $line)
    {
        if (!class_exists(LogManager::class) || !$this->app->hasBeenBootstrapped() || $this->app->runningUnitTests()) {
            return;
        }
        try {
            $logger = $this->app->make(LogManager::class);
        } catch (\Exception $e) {
            return;
        }
        $logger->channel()->warning(sprintf('%s in %s on line %s', $message, $file, $line));
    }
    /**
     * Report PHP deprecations, or convert PHP errors to ErrorException instances.
     *
     * @param int $level
     * @param string $message
     * @param string $file
     * @param int $line
     * @param array $context
     * @return void
     *
     * @throws \ErrorException
     */
    public function handleError($level, $message, $file = '', $line = 0, $context = [])
    {
        if (!$this->isDeprecation($level) && !$this->isFatal($level)) {
            return $this->handleAsWarning($message, $file, $line);
        }
        return parent::handleError($level, $message, $file, $line, $context);
    }
}
