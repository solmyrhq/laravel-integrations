<?php

declare(strict_types=1);

namespace Integrations\Testing;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Integrations\IntegrationsServiceProvider;
use Orchestra\Testbench\TestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\Creation\ValidationStrategy;

abstract class IntegrationTestCase extends TestCase
{
    use CreatesIntegration;

    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return array_merge(
            [
                LaravelDataServiceProvider::class,
                IntegrationsServiceProvider::class,
            ],
            $this->getAdapterProviders($app),
        );
    }

    /**
     * Return additional service providers needed by the adapter under test.
     *
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getAdapterProviders($app): array
    {
        return [];
    }

    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        // Brings in the framework's jobs / job_batches / failed_jobs tables.
        // The sync flow dispatches a Bus batch, which needs job_batches.
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    /**
     * @param  Application  $app
     */
    #[\Override]
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Bus::batch and the failed-jobs store otherwise resolve their own
        // connection, which under Testbench points at a non-existent file.
        $app['config']->set('queue.batching.database', 'testing');
        $app['config']->set('queue.failed.database', 'testing');

        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');

        // Consumers may set `Always`, so adapter tests must catch Data that fails validation there.
        $app['config']->set('data.validation_strategy', ValidationStrategy::Always->value);
    }

    #[\Override]
    protected function tearDown(): void
    {
        IntegrationRequestFake::deactivate();
        parent::tearDown();
    }
}
