<?php

declare(strict_types=1);

namespace Kreait\Laravel\Firebase;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\Psr16Adapter;

class FirebaseProjectManager
{
    /** @var Application */
    protected $app;

    /** @var FirebaseProject[] */
    protected array $projects = [];

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    public function project(?string $name = null): FirebaseProject
    {
        $name = $name ?? $this->getDefaultProject();

        if (! isset($this->projects[$name])) {
            $this->projects[$name] = $this->configure($name);
        }

        return $this->projects[$name];
    }

    protected function configuration(string $name): array
    {
        $config = $this->app->config->get('firebase.projects.'.$name);

        if (! $config) {
            throw new InvalidArgumentException("Firebase project [{$name}] not configured.");
        }

        return $config;
    }

    protected function resolveJsonCredentials(string $credentials): string
    {
        $isJsonString = \str_starts_with($credentials, '{');
        $isAbsoluteLinuxPath = \str_starts_with($credentials, '/');
        $isAbsoluteWindowsPath = \str_contains($credentials, ':\\');

        $isRelativePath = ! $isJsonString && ! $isAbsoluteLinuxPath && ! $isAbsoluteWindowsPath;

        return $isRelativePath ? $this->app->basePath($credentials) : $credentials;
    }

    protected function configure(string $name): FirebaseProject
    {
        $factory = new Factory;

        $config = $this->configuration($name);

        if ($tenantId = $config['auth']['tenant_id'] ?? null) {
            $factory = $factory->withTenantId($tenantId);
        }

        if ($credentials = $config['credentials']['file'] ?? ($config['credentials'] ?? null)) {
            if (is_string($credentials)) {
                $credentials = $this->resolveJsonCredentials($credentials);
            }

            $factory = $factory->withServiceAccount($credentials);
        }

        if ($databaseUrl = $config['database']['url'] ?? null) {
            $factory = $factory->withDatabaseUri($databaseUrl);
        }

        if ($authVariableOverride = $config['database']['auth_variable_override'] ?? null) {
            $factory = $factory->withDatabaseAuthVariableOverride($authVariableOverride);
        }

        if ($firestoreDatabase = $config['firestore']['database'] ?? null) {
            $factory = $factory->withFirestoreDatabase($firestoreDatabase);
        }

        if ($defaultStorageBucket = $config['storage']['default_bucket'] ?? null) {
            $factory = $factory->withDefaultStorageBucket($defaultStorageBucket);
        }

        if ($cacheStore = $config['cache_store'] ?? null) {
            $cache = $this->app->make('cache')->store($cacheStore);

            if ($cache instanceof CacheInterface) {
                $cache = new Psr16Adapter($cache);
            } else {
                throw new InvalidArgumentException('The cache store must be an instance of a PSR-6 or PSR-16 cache');
            }

            $factory = $factory
                ->withVerifierCache($cache)
                ->withAuthTokenCache($cache);
        }

        if ($logChannel = $config['logging']['http_log_channel'] ?? null) {
            $factory = $factory->withHttpLogger(
                $this->app->make('log')->channel($logChannel)
            );
        }

        if ($logChannel = $config['logging']['http_debug_log_channel'] ?? null) {
            $factory = $factory->withHttpDebugLogger(
                $this->app->make('log')->channel($logChannel)
            );
        }

        $options = HttpClientOptions::default();

        if ($proxy = $config['http_client_options']['proxy'] ?? null) {
            $options = $options->withProxy($proxy);
        }

        if ($timeout = $config['http_client_options']['timeout'] ?? null) {
            $options = $options->withTimeOut((float) $timeout);
        }

        if ($middlewares = $config['http_client_options']['guzzle_middlewares'] ?? null) {
            $options = $options->withGuzzleMiddlewares($middlewares);
        }

        $factory = $factory->withHttpClientOptions($options);

        return new FirebaseProject($factory, $config);
    }

    public function getDefaultProject(): string
    {
        return $this->app->config->get('firebase.default');
    }

    public function setDefaultProject(string $name): void
    {
        $this->app->config->set('firebase.default', $name);
    }

    public function __call($method, $parameters)
    {
        // Pass call to default project
        return $this->project()->{$method}(...$parameters);
    }
}
