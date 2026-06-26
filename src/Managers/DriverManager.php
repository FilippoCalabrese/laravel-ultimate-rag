<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * Base driver manager (principle: Driver Manager pattern, §3).
 *
 * Resolves a named configuration block to a concrete driver instance, caches
 * it, and lets consumers register custom drivers via {@see extend()} without
 * forking (FR-EV-04, NFR-ES-01).
 *
 * @template TDriver of object
 */
abstract class DriverManager
{
    /** @var array<string, TDriver> */
    protected array $drivers = [];

    /** @var array<string, callable(array<string,mixed>, string): TDriver> */
    protected array $customCreators = [];

    public function __construct(protected readonly Container $app) {}

    /**
     * The config section under `rag-engine.` holding named blocks, e.g. `embedders`.
     */
    abstract protected function configSection(): string;

    abstract public function getDefaultDriver(): string;

    /**
     * @return TDriver
     */
    public function driver(?string $name = null): object
    {
        $name ??= $this->getDefaultDriver();

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    /**
     * @param  callable(array<string,mixed>, string): TDriver  $callback
     */
    public function extend(string $driver, callable $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    public function forgetDrivers(): static
    {
        $this->drivers = [];

        return $this;
    }

    /**
     * @return TDriver
     */
    protected function resolve(string $name): object
    {
        $config = $this->getConfig($name);

        if ($config === null) {
            throw new RagException(
                ucfirst($this->configSection())." [{$name}] is not configured."
            );
        }

        $driver = $config['driver'] ?? $name;

        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($config, $name);
        }

        $method = 'create'.Str::studly($driver).'Driver';

        if (method_exists($this, $method)) {
            /** @var TDriver */
            return $this->{$method}($config, $name);
        }

        throw new RagException(
            "Driver [{$driver}] for {$this->configSection()} is not supported."
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getConfig(string $name): ?array
    {
        /** @var array<string, mixed>|null $config */
        $config = $this->app->make('config')->get("rag-engine.{$this->configSection()}.{$name}");

        return $config;
    }
}
