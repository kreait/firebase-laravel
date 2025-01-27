<?php

declare(strict_types=1);

namespace Kreait\Laravel\Firebase\Tests;

use GuzzleHttp\RetryMiddleware;
use Kreait\Firebase;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Factory;
use Kreait\Laravel\Firebase\FirebaseProjectManager;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionObject;

/**
 * @internal
 */
final class FirebaseProjectManagerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('firebase.projects.app.credentials', __DIR__.'/_fixtures/service_account.json');
    }

    #[Test]
    public function a_project_configuration_has_to_exist(): void
    {
        $manager = $this->app->make(FirebaseProjectManager::class);

        $projectName = 'non-existent-project-name';

        $this->expectException(InvalidArgumentException::class);

        $this->getAccessibleMethod($manager, 'configuration')->invoke($manager, $projectName);
    }

    #[Test]
    public function a_default_project_can_be_set(): void
    {
        $manager = $this->app->make(FirebaseProjectManager::class);

        $projectName = 'another-app';

        $manager->setDefaultProject($projectName);

        $this->assertSame($projectName, $manager->getDefaultProject());
        $this->assertSame($projectName, $this->app->config->get('firebase.default'), 'default project should be set in config');
    }

    #[Test]
    public function calls_are_passed_to_default_project(): void
    {
        $manager = $this->app->make(FirebaseProjectManager::class);

        $projectName = $this->app->config->get('firebase.default');

        $this->assertSame($manager->project($projectName)->auth(), $manager->auth());
    }

    #[Test]
    public function credentials_can_be_configured_using_a_json_file(): void
    {
        // Reference credentials
        $credentialsPath = \realpath(__DIR__.'/_fixtures/service_account.json');
        $credentials = \json_decode(\file_get_contents($credentialsPath), true);

        // Set configuration and retrieve project
        $projectName = 'app';
        $this->app->config->set('firebase.projects.'.$projectName.'.credentials', \realpath(__DIR__.'/_fixtures/service_account.json'));
        $factory = $this->factoryForProject($projectName);

        // Retrieve service account
        $serviceAccount = $this->getAccessibleProperty($factory, 'serviceAccount')->getValue($factory);

        // Validate value
        $this->assertSame($credentials, $serviceAccount);
    }

    #[Test]
    public function json_file_credentials_can_be_used_using_the_deprecated_configuration_entry(): void
    {
        // Reference credentials
        $credentialsPath = \realpath(__DIR__.'/_fixtures/service_account.json');
        $credentials = \json_decode(\file_get_contents($credentialsPath), true);

        // Set configuration and retrieve project
        $projectName = 'app';
        $this->app->config->set('firebase.projects.'.$projectName.'.credentials.file', \realpath(__DIR__.'/_fixtures/service_account.json'));
        $factory = $this->factoryForProject($projectName);

        // Retrieve service account
        $serviceAccount = $this->getAccessibleProperty($factory, 'serviceAccount')->getValue($factory);

        // Validate value
        $this->assertSame($credentials, $serviceAccount);
    }

    #[Test]
    public function credentials_can_be_configured_using_an_array(): void
    {
        // Set configuration and retrieve project
        $projectName = 'app';
        $this->app->config->set('firebase.projects.'.$projectName.'.credentials', $credentials = [
            'type' => 'service_account',
            'project_id' => 'project',
            'private_key_id' => 'private_key_id',
            'private_key' => '-----BEGIN PRIVATE KEY-----\nsome gibberish\n-----END PRIVATE KEY-----\n',
            'client_email' => 'client@email.tld',
            'client_id' => '1234567890',
            'auth_uri' => 'https://some.google.tld/o/oauth2/auth',
            'token_uri' => 'https://some.google.tld/o/oauth2/token',
            'auth_provider_x509_cert_url' => 'https://some.google.tld/oauth2/v1/certs',
            'client_x509_cert_url' => 'https://some.google.tld/robot/v1/metadata/x509/user%40project.iam.gserviceaccount.com',
        ]);
        $factory = $this->factoryForProject($projectName);

        // Retrieve service account
        $serviceAccount = $this->getAccessibleProperty($factory, 'serviceAccount')->getValue($factory);

        // Validate value
        $this->assertSame($credentials, $serviceAccount);
    }

    #[Test]
    public function projects_can_have_different_credentials(): void
    {
        // Reference credentials
        $credentialsPath = \realpath(__DIR__.'/_fixtures/service_account.json');
        $credentials = \json_decode(\file_get_contents($credentialsPath), true);

        $secondCredentialsPath = \realpath(__DIR__.'/_fixtures/another_service_account.json');
        $secondCredentials = \json_decode(\file_get_contents($secondCredentialsPath), true);

        // Project names to use
        $projectName = 'app';
        $secondProjectName = 'another-app';

        // Set service accounts explicitly
        $this->app->config->set('firebase.projects.'.$projectName.'.credentials', \realpath(__DIR__.'/_fixtures/service_account.json'));
        $this->app->config->set('firebase.projects.'.$secondProjectName.'.credentials', \realpath(__DIR__.'/_fixtures/another_service_account.json'));

        // Retrieve factories and service accounts
        $factory = $this->factoryForProject($projectName);
        $secondFactory = $this->factoryForProject($secondProjectName);

        $serviceAccount = $this->getAccessibleProperty($factory, 'serviceAccount')->getValue($factory);
        $secondServiceAccount = $this->getAccessibleProperty($factory, 'serviceAccount')->getValue($secondFactory);

        // Validate values
        $this->assertSame($credentials, $serviceAccount);
        $this->assertSame($secondCredentials, $secondServiceAccount);
    }

    #[Test]
    public function the_realtime_database_url_can_be_configured(): void
    {
        $projectName = $this->app->config->get('firebase.default');
        $this->app->config->set('firebase.projects.'.$projectName.'.database.url', $url = 'https://domain.tld');
        $this->app->config->set('firebase.projects.'.$projectName.'.database.auth_variable_override', ['uid' => 'some-uid']);

        $database = $this->app->make(Firebase\Contract\Database::class);

        $property = $this->getAccessibleProperty($database, 'uri');

        $this->assertSame($url, (string) $property->getValue($database));
    }

    #[Test]
    public function the_dynamic_links_default_domain_can_be_configured(): void
    {
        $projectName = $this->app->config->get('firebase.default');
        $this->app->config->set('firebase.projects.'.$projectName.'.dynamic_links.default_domain', $domain = 'https://domain.tld');

        $dynamicLinks = $this->app->make(Firebase\Contract\DynamicLinks::class);

        $property = $this->getAccessibleProperty($dynamicLinks, 'defaultDynamicLinksDomain');

        $configuredDomain = $property->getValue($dynamicLinks);

        $this->assertSame($domain, $configuredDomain);
    }

    #[Test]
    public function the_storage_default_bucket_can_be_configured(): void
    {
        $projectName = $this->app->config->get('firebase.default');
        $this->app->config->set('firebase.projects.'.$projectName.'.storage.default_bucket', $name = 'my-bucket');

        $storage = $this->app->make(Firebase\Contract\Storage::class);

        $property = $this->getAccessibleProperty($storage, 'defaultBucket');

        $this->assertSame($name, $property->getValue($storage));
    }

    #[Test]
    public function logging_can_be_configured(): void
    {
        $projectName = $this->app->config->get('firebase.default');
        $this->app->config->set('firebase.projects.'.$projectName.'.logging.http_log_channel', 'stack');

        $factory = $this->factoryForProject($projectName);

        $property = $this->getAccessibleProperty($factory, 'httpLogMiddleware');

        $this->assertNotNull($property->getValue($factory));
    }

    #[Test]
    public function debug_logging_can_be_configured(): void
    {
        $projectName = $this->app->config->get('firebase.default');
        $this->app->config->set('firebase.projects.'.$projectName.'.logging.http_debug_log_channel', 'stack');

        $factory = $this->factoryForProject($projectName);

        $property = $this->getAccessibleProperty($factory, 'httpDebugLogMiddleware');

        $this->assertNotNull($property->getValue($factory));
    }

    #[Test]
    public function http_client_options_can_be_configured(): void
    {
        $projectName = $this->app->config->get('firebase.default');
        $this->app->config->set('firebase.projects.'.$projectName.'.http_client_options.proxy', 'proxy.domain.tld');
        $this->app->config->set('firebase.projects.'.$projectName.'.http_client_options.timeout', 1.23);
        $this->app->config->set('firebase.projects.'.$projectName.'.http_client_options.guzzle_middlewares', [RetryMiddleware::class]);

        $factory = $this->factoryForProject($projectName);

        /** @var Firebase\Http\HttpClientOptions $httpClientOptions */
        $httpClientOptions = $this->getAccessibleProperty($factory, 'httpClientOptions')->getValue($factory);

        $this->assertSame('proxy.domain.tld', $httpClientOptions->proxy());
        $this->assertSame(1.23, $httpClientOptions->timeout());
        $this->assertSame([RetryMiddleware::class], $httpClientOptions->guzzleMiddlewares());
    }

    #[Test]
    public function it_uses_the_laravel_cache_as_verifier_cache(): void
    {
        $projectName = $this->app->config->get('firebase.default');
        $factory = $this->factoryForProject($projectName);

        $property = $this->getAccessibleProperty($factory, 'verifierCache');

        $this->assertInstanceOf(CacheItemPoolInterface::class, $property->getValue($factory));
    }

    #[Test]
    public function it_overrides_the_default_firestore_database(): void
    {
        config(['firebase.projects.app.firestore.database' => 'override-database']);
        $projectName = $this->app->config->get('firebase.default');
        $factory = $this->factoryForProject($projectName);

        $property = $this->getAccessibleProperty($factory, 'firestoreClientConfig');

        $this->assertEquals('override-database', $property->getValue($factory)['database']);
    }

    #[Test]
    public function it_uses_the_laravel_cache_as_auth_token_cache(): void
    {
        $projectName = $this->app->config->get('firebase.default');
        $factory = $this->factoryForProject($projectName);

        $property = $this->getAccessibleProperty($factory, 'authTokenCache');

        $this->assertInstanceOf(CacheItemPoolInterface::class, $property->getValue($factory));
    }

    private function factoryForProject(?string $project = null): Factory
    {
        $project = $this->app->make(FirebaseProjectManager::class)->project($project);

        return $this->getAccessibleProperty($project, 'factory')->getValue($project);
    }

    private function getAccessibleProperty(object $object, string $propertyName): \ReflectionProperty
    {
        $property = (new ReflectionObject($object))->getProperty($propertyName);
        $property->setAccessible(true);

        return $property;
    }

    private function getAccessibleMethod(object $object, string $methodName): \ReflectionMethod
    {
        $property = (new ReflectionObject($object))->getMethod($methodName);
        $property->setAccessible(true);

        return $property;
    }
}
