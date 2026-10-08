<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use RuntimeException;

class TestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);
    }

    public function test_passes_healthy_integration(): void
    {
        Integration::create(['provider' => 'test', 'name' => 'Healthy']);

        $this->artisan('integrations:test')
            ->assertSuccessful()
            ->expectsOutputToContain('PASS');
    }

    public function test_fails_unhealthy_integration(): void
    {
        Integration::create(['provider' => 'test', 'name' => 'Unhealthy']);

        $provider = new TestProvider;
        $provider->healthCheckResult = false;
        $this->app->instance(TestProvider::class, $provider);

        $this->artisan('integrations:test')
            ->assertFailed()
            ->expectsOutputToContain('FAIL');
    }

    public function test_counts_a_throwing_health_check_once(): void
    {
        Integration::create(['provider' => 'test', 'name' => 'Throwing']);

        $this->app->instance(TestProvider::class, new class extends TestProvider
        {
            public function healthCheck(Integration $integration): bool
            {
                throw new RuntimeException('Connection refused');
            }
        });

        $this->artisan('integrations:test')
            ->assertFailed()
            ->expectsOutputToContain('Connection refused')
            ->expectsOutputToContain('Tested: 1, Passed: 0, Failed: 1');
    }

    public function test_empty_state(): void
    {
        $this->artisan('integrations:test')
            ->assertSuccessful()
            ->expectsOutputToContain('No integrations with health check support found');
    }
}
