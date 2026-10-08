<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Enums\FailureClass;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;

class TestCommand extends Command
{
    protected $signature = 'integrations:test';

    protected $description = 'Run health checks on all integrations that support it.';

    public function handle(IntegrationManager $manager): int
    {
        $integrations = Integration::active()->get();
        $tested = 0;
        $passed = 0;
        $failed = 0;

        foreach ($integrations as $integration) {
            if (! $manager->has($integration->provider)) {
                continue;
            }

            $healthy = $this->checkHealth($manager, $integration);

            if ($healthy === null) {
                continue;
            }

            $tested++;
            $healthy ? $passed++ : $failed++;
        }

        if ($tested === 0) {
            $this->info('No integrations with health check support found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Tested: {$tested}, Passed: {$passed}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkHealth(IntegrationManager $manager, Integration $integration): ?bool
    {
        try {
            $provider = $manager->provider($integration->provider);

            if (! $provider instanceof HasHealthCheck) {
                return null;
            }

            if ($provider->healthCheck($integration)) {
                $this->info("  [PASS] {$integration->name} ({$integration->provider})");
                $integration->recordSuccess();

                return true;
            }

            $this->error("  [FAIL] {$integration->name} ({$integration->provider})");
            $integration->recordFailure(FailureClass::Upstream);

            return false;
        } catch (\Throwable $e) {
            $this->error("  [FAIL] {$integration->name} ({$integration->provider}): {$e->getMessage()}");
            $integration->recordFailure(FailureClass::Upstream);

            return false;
        }
    }
}
