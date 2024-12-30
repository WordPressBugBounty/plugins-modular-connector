<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Console;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Application;
use Modular\ConnectorDependencies\Illuminate\Console\Parser;
abstract class Command
{
    /**
     * @var string
     */
    protected string $name;
    /**
     * @var array
     */
    protected array $arguments = [];
    /**
     * @var \Ares\Framework\Foundation\Application|\Illuminate\Contracts\Foundation\Application|mixed
     */
    protected $laravel;
    /**
     * @var \Illuminate\Log\LogManager
     */
    protected $log;
    /**
     * Returns all the given options merged with the default values.
     *
     * @return array<string|bool|int|float|array|null>
     */
    protected array $options = [];
    public function __construct(array $parameters = [])
    {
        [$name, $arguments, $options] = Parser::parse($this->signature);
        $this->name = $name;
        $this->arguments = $arguments;
        $this->laravel = \Modular\ConnectorDependencies\app();
        $this->log = $this->laravel->make('log');
        /**
         * @var \Symfony\Component\Console\Input\InputOption $option
         */
        foreach ($options as $option) {
            $name = $option->getName();
            $value = $parameters[$name] ?? $option->getDefault();
            $this->options[$option->getName()] = $value;
        }
    }
    /**
     * Returns the command name.
     *
     * @return string|null
     */
    public function getName()
    {
        return $this->name;
    }
    /**
     * @param string|null $name
     * @return mixed|null
     */
    public function option(?string $name = null)
    {
        return $this->options[$name] ?? null;
    }
    /**
     * @return string
     */
    public function __toString()
    {
        $options = [];
        foreach ($this->options as $name => $value) {
            $options[] = sprintf('--%s=%s', $name, $value);
        }
        return Application::formatCommandString($this->getName() . ' ' . implode(' ', $options));
    }
}
