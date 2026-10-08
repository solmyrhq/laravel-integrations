<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Integrations\Enums\CircuitOverride;
use Integrations\Enums\FailureClass;
use Integrations\Events\CircuitClosed;
use Integrations\Events\CircuitOpened;
use Integrations\Exceptions\CircuitOpenException;
use Integrations\Models\Integration;
use Integrations\Support\CircuitBreakerRateStrategy;
use Integrations\Support\Config;

/**
 * Per-integration circuit breaker. Sits next to the RateLimiter in the
 * request pipeline: enforce() runs before the user's callback, and the
 * executor calls recordSuccess() / recordFailure() afterwards.
 *
 * State machine:
 *   closed    -> fully open for traffic. Failures are accounted by the active
 *               strategy; when it trips, transition to "open".
 *   open      -> all requests short-circuit with CircuitOpenException until
 *               the cooldown elapses, then transition to "half_open".
 *   half_open -> one probe request is allowed through. Success -> "closed".
 *               Failure -> "open" again, fresh cooldown.
 *
 * Tripping strategy is configurable (see Config::circuitBreakerStrategy()):
 *   - "rate"  (default): open when the failure rate over a rolling window
 *             crosses the threshold once a minimum number of requests is seen.
 *             Accounting lives in the rate strategy's window buckets.
 *   - "count": open after N consecutive upstream failures. Accounting is the
 *             "failures" counter in the state array.
 *
 * Only FailureClass::Upstream counts toward tripping; classification happens
 * once in the FailureClassifier and is passed in.
 *
 * Operators can override the breaker at runtime (without a redeploy) via the
 * integration's circuit_override column; that takes precedence over the state
 * machine — see enforce()/recordFailure()/recordSuccess().
 *
 * State is stored in a single cache key per integration via read-modify-write.
 * A tiny race where two concurrent failures both flip closed -> open is
 * harmless (they write the same end state). The open -> half_open transition
 * uses a separate "probe slot" cache key claimed via Cache::add(), atomic on
 * Laravel's redis/memcached/database drivers, so only one request becomes the
 * probe even when many workers see the cooldown expire at once.
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half_open';

    private ?CircuitBreakerRateStrategy $rateStrategy = null;

    public function __construct(
        private readonly Integration $integration,
    ) {}

    public function enforce(): void
    {
        if ($this->overrideBypassesBreaker()) {
            return;
        }

        if (! Config::circuitBreakerEnabled()) {
            return;
        }

        $state = $this->loadState();

        if ($state['state'] === self::STATE_CLOSED) {
            return;
        }

        $cooldown = Config::circuitBreakerCooldownSeconds();
        $openedAt = $state['opened_at'];

        if ($state['state'] === self::STATE_HALF_OPEN) {
            $this->claimHalfOpenProbe($openedAt, $cooldown);

            return;
        }

        // STATE_OPEN: still inside the cooldown window?
        if ($openedAt !== null && ((int) now()->timestamp - $openedAt) < $cooldown) {
            throw new CircuitOpenException(
                $this->integration,
                CarbonImmutable::createFromTimestamp($openedAt),
                $cooldown,
            );
        }

        // Cooldown elapsed (or openedAt was lost): try to atomically claim
        // the probe slot. Only one concurrent worker wins; the rest back
        // off until the probe outcome lands.
        if (! Cache::add($this->probeKey(), 1, $cooldown * 2)) {
            throw new CircuitOpenException(
                $this->integration,
                CarbonImmutable::createFromTimestamp($openedAt ?? (int) now()->timestamp),
                $cooldown,
            );
        }

        $this->writeState(self::STATE_HALF_OPEN, 0, $openedAt ?? (int) now()->timestamp);
    }

    /**
     * @throws CircuitOpenException
     */
    private function overrideBypassesBreaker(): bool
    {
        $override = $this->override();

        if ($override === CircuitOverride::ForcedOpen) {
            // Held open by an operator. Never enters half-open or claims a
            // probe slot, so it stays open until the override is cleared.
            throw new CircuitOpenException(
                $this->integration,
                CarbonImmutable::now(),
                Config::circuitBreakerCooldownSeconds(),
            );
        }

        return $override === CircuitOverride::Disabled || $override === CircuitOverride::ForcedClosed;
    }

    /**
     * @throws CircuitOpenException
     */
    private function claimHalfOpenProbe(?int $openedAt, int $cooldown): void
    {
        // Another request is mid-probe: only the slot holder gets
        // through. If the slot expired (probe crashed mid-flight), we
        // can claim it as the new probe; otherwise back off.
        if (Cache::add($this->probeKey(), 1, $cooldown * 2)) {
            return;
        }

        throw new CircuitOpenException(
            $this->integration,
            CarbonImmutable::createFromTimestamp($openedAt ?? (int) now()->timestamp),
            $cooldown,
        );
    }

    public function recordSuccess(): void
    {
        $override = $this->override();
        if ($override === CircuitOverride::Disabled || $override === CircuitOverride::ForcedOpen) {
            // A forced-open breaker must not be closed by a probe success.
            return;
        }

        if (! Config::circuitBreakerEnabled()) {
            return;
        }

        $state = $this->loadState();

        if ($state['state'] === self::STATE_CLOSED && $state['failures'] === 0) {
            // Hot path: still closed and clean. Only the rate denominator moves.
            $this->recordOutcome(false);

            return;
        }

        $wasOpen = $state['state'] === self::STATE_OPEN || $state['state'] === self::STATE_HALF_OPEN;

        $this->writeState(self::STATE_CLOSED, 0, null);

        if ($this->usesRateStrategy()) {
            // Closing is a fresh start: clear the window so stale failures
            // don't immediately re-trip.
            $this->rateStrategy()->reset($this->integration->id);
        }

        // Release the probe slot so future open -> half_open transitions can
        // claim it. No-op when no probe slot was held.
        Cache::forget($this->probeKey());

        if ($wasOpen) {
            CircuitClosed::dispatch($this->integration, 'half_open_probe_succeeded');
        }
    }

    public function recordFailure(FailureClass $class): void
    {
        if ($this->override() !== null) {
            // Forced/disabled: never accumulate, nothing to mutate.
            return;
        }

        if (! Config::circuitBreakerEnabled()) {
            return;
        }

        if (! $class->countsAsFailure()) {
            // Not an upstream fault. It was still a request, so for the rate
            // strategy it belongs in the denominator (otherwise a burst of
            // client errors would inflate the upstream-failure rate).
            $this->recordOutcome(false);

            return;
        }

        $this->recordOutcome(true);
        $this->applyFailureToState();
    }

    /**
     * Read-only snapshot of the live state machine, for CLI/observability.
     *
     * @return array{state: string, failures: int, opened_at: ?int, failure_rate: ?float}
     */
    public function inspect(): array
    {
        $state = $this->loadState();

        // The live window failure rate the breaker is acting on.
        // Null under the count strategy (no rate buckets) or before any traffic.
        $failureRate = $this->usesRateStrategy()
            ? $this->rateStrategy()->currentFailureRate($this->integration->id)
            : null;

        return [...$state, 'failure_rate' => $failureRate];
    }

    /**
     * Drop all breaker state so the integration returns to a clean CLOSED.
     * Used when an operator clears a force-override.
     */
    public function reset(): void
    {
        Cache::forget($this->key());
        Cache::forget($this->probeKey());
        $this->rateStrategy()->reset($this->integration->id);
    }

    /**
     * Advance the cache state machine after a counted (upstream) failure that
     * the override/enabled/classification gates have already cleared.
     */
    private function applyFailureToState(): void
    {
        $state = $this->loadState();

        if ($state['state'] === self::STATE_HALF_OPEN) {
            // Probe failed with real upstream evidence: reopen with a fresh
            // cooldown and release the probe slot.
            $this->writeState(self::STATE_OPEN, $state['failures'], (int) now()->timestamp);
            Cache::forget($this->probeKey());
            CircuitOpened::dispatch($this->integration, 'half_open_probe_failed');

            return;
        }

        if ($this->shouldTrip($state)) {
            $wasClosed = $state['state'] === self::STATE_CLOSED;
            $this->writeState(self::STATE_OPEN, $state['failures'] + 1, (int) now()->timestamp);

            if ($wasClosed) {
                CircuitOpened::dispatch($this->integration, 'threshold_reached');
            }

            return;
        }

        // Not tripping. The count strategy accumulates a consecutive-failure
        // counter; the rate strategy keeps its evidence in the window buckets
        // and leaves the state key untouched.
        if (! $this->usesRateStrategy()) {
            $this->writeState(self::STATE_CLOSED, $state['failures'] + 1, null);
        }
    }

    /**
     * Feed one request outcome to the rate strategy's window buckets (no-op
     * under the count strategy, which keeps its tally in the state key).
     */
    private function recordOutcome(bool $failed): void
    {
        if ($this->usesRateStrategy()) {
            $this->rateStrategy()->recordOutcome($this->integration->id, $failed);
        }
    }

    /**
     * @param  array{state: string, failures: int, opened_at: ?int}  $state
     */
    private function shouldTrip(array $state): bool
    {
        if (! $this->usesRateStrategy()) {
            return ($state['failures'] + 1) >= Config::circuitBreakerThreshold();
        }

        // The current failure was already recorded into the window buckets by
        // recordFailure(), so isTripped() sees it.
        return $this->rateStrategy()->isTripped($this->integration->id);
    }

    private function usesRateStrategy(): bool
    {
        return Config::circuitBreakerStrategy() !== 'count';
    }

    private function rateStrategy(): CircuitBreakerRateStrategy
    {
        return $this->rateStrategy ??= new CircuitBreakerRateStrategy;
    }

    private function override(): ?CircuitOverride
    {
        return $this->integration->effectiveCircuitOverride();
    }

    /**
     * @return array{state: string, failures: int, opened_at: ?int}
     */
    private function loadState(): array
    {
        $raw = Cache::get($this->key());
        if (! is_array($raw)) {
            return ['state' => self::STATE_CLOSED, 'failures' => 0, 'opened_at' => null];
        }

        $state = is_string($raw['state'] ?? null) ? $raw['state'] : self::STATE_CLOSED;
        $failures = is_int($raw['failures'] ?? null) ? $raw['failures'] : 0;
        $openedAt = is_int($raw['opened_at'] ?? null) ? $raw['opened_at'] : null;

        return ['state' => $state, 'failures' => $failures, 'opened_at' => $openedAt];
    }

    private function writeState(string $state, int $failures, ?int $openedAt): void
    {
        // TTL = 2x cooldown so the entry naturally expires once it's no
        // longer relevant; on the closed state we keep it shorter to stop
        // stale failure counts from lingering after a quiet period.
        $ttl = $state === self::STATE_CLOSED
            ? Config::circuitBreakerCooldownSeconds()
            : Config::circuitBreakerCooldownSeconds() * 2;

        Cache::put($this->key(), [
            'state' => $state,
            'failures' => $failures,
            'opened_at' => $openedAt,
        ], $ttl);
    }

    private function key(): string
    {
        return Config::cachePrefix().':breaker:'.$this->integration->id;
    }

    private function probeKey(): string
    {
        return $this->key().':probe';
    }
}
