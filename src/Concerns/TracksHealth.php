<?php

declare(strict_types=1);

namespace Integrations\Concerns;

use Illuminate\Support\Facades\DB;
use Integrations\Enums\FailureClass;
use Integrations\Enums\HealthStatus;
use Integrations\Events\IntegrationDisabled;
use Integrations\Events\IntegrationHealthChanged;
use Integrations\Models\Integration;
use Integrations\Support\Config;

/**
 * Per-integration health tracking: a consecutive-failure counter that drives
 * the health_status state machine (Healthy -> Degraded -> Failing -> Disabled)
 * and, at the disabled threshold, flips is_active off. Only upstream faults
 * count (see FailureClass::countsAsFailure()), so client errors and throttles
 * never degrade an integration.
 *
 * @mixin Integration
 */
trait TracksHealth
{
    public function recordSuccess(): void
    {
        $previousStatus = $this->health_status;

        if ($previousStatus === HealthStatus::Disabled) {
            return;
        }

        $this->update([
            'consecutive_failures' => 0,
            'health_status' => HealthStatus::Healthy,
        ]);

        if ($previousStatus !== HealthStatus::Healthy) {
            IntegrationHealthChanged::dispatch($this, $previousStatus, HealthStatus::Healthy);
        }
    }

    public function recordFailure(FailureClass $class): void
    {
        // Only an upstream fault degrades health. Client errors (4xx),
        // throttles (429), and unrecognised failures must not mark the
        // integration unhealthy or eventually auto-disable it.
        if (! $class->countsAsFailure()) {
            return;
        }

        $transition = DB::transaction(fn (): ?array => $this->incrementFailuresLocked());

        if ($transition === null) {
            return;
        }

        [$previousStatus, $newStatus] = $transition;

        if ($newStatus === HealthStatus::Disabled && $previousStatus !== HealthStatus::Disabled) {
            IntegrationDisabled::dispatch($this);
        }

        if ($newStatus !== $previousStatus) {
            IntegrationHealthChanged::dispatch($this, $previousStatus, $newStatus);
        }
    }

    /**
     * @return array{HealthStatus, HealthStatus}|null
     */
    private function incrementFailuresLocked(): ?array
    {
        $locked = Integration::lockForUpdate()->find($this->id);

        if ($locked === null) {
            return null;
        }

        $previousStatus = $locked->health_status;
        $failures = $locked->consecutive_failures + 1;
        $newStatus = self::healthStatusAfterFailures($failures, $previousStatus);

        $updates = [
            'consecutive_failures' => $failures,
            'last_error_at' => now(),
            'health_status' => $newStatus,
        ];

        if ($newStatus === HealthStatus::Disabled) {
            $updates['is_active'] = false;
        }

        $locked->update($updates);

        $this->fill($locked->only([
            'consecutive_failures',
            'last_error_at',
            'health_status',
            'is_active',
        ]));
        $this->syncOriginal();

        return [$previousStatus, $newStatus];
    }

    private static function healthStatusAfterFailures(int $failures, HealthStatus $previousStatus): HealthStatus
    {
        $disabledAfter = Config::disabledAfter();

        return match (true) {
            $disabledAfter !== null && $failures >= $disabledAfter => HealthStatus::Disabled,
            $failures >= Config::failingAfter() => HealthStatus::Failing,
            $failures >= Config::degradedAfter() => HealthStatus::Degraded,
            default => $previousStatus,
        };
    }
}
