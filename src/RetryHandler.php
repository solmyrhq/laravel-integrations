<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Integrations\Exceptions\RetriesExhaustedException;
use Integrations\Exceptions\RetryableException;
use Integrations\Support\Config;
use Integrations\Support\ResponseHelper;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RetryHandler
{
    /**
     * Execute a callback with retry logic for transient errors.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  list<int>  $retryableStatusCodes
     * @param  (Closure(int, Throwable): void)|null  $onRetry
     * @return T
     *
     * @throws Throwable
     */
    public static function execute(
        Closure $callback,
        int $maxAttempts = 3,
        array $retryableStatusCodes = [429, 500, 502, 503, 504],
        int $rateLimitDelayMs = 30_000,
        int $serverErrorBaseDelayMs = 2_000,
        int $defaultBaseDelayMs = 1_000,
        ?Closure $onRetry = null,
    ): mixed {
        self::assertValidArguments($maxAttempts, $rateLimitDelayMs, $serverErrorBaseDelayMs, $defaultBaseDelayMs);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $callback();
            } catch (Throwable $e) {
                $statusCode = ResponseHelper::extractStatusCode($e);

                self::throwUnlessRetrying($e, $statusCode, $retryableStatusCodes, $attempt, $maxAttempts);

                if ($onRetry !== null) {
                    ($onRetry)($attempt, $e);
                }

                $delayMs = self::resolveDelay(
                    $e, $statusCode, $attempt,
                    $rateLimitDelayMs, $serverErrorBaseDelayMs, $defaultBaseDelayMs,
                );
                usleep($delayMs * 1000);
            }
        }

        throw new RuntimeException('Retry logic exhausted without result.');
    }

    /**
     * Check if an exception is retryable with default status codes.
     */
    public static function isRetryable(Throwable $e): bool
    {
        $statusCode = ResponseHelper::extractStatusCode($e);

        return self::isRetryableInternal($e, $statusCode, [429, 500, 502, 503, 504]);
    }

    /**
     * Calculate the retry delay in milliseconds for a given exception and attempt.
     */
    public static function calculateDelayMs(Throwable $e, int $attempt): int
    {
        $attempt = max(1, $attempt);

        $retryableDelayMs = self::extractRetryableExceptionDelayMs($e);
        if ($retryableDelayMs !== null) {
            return min($retryableDelayMs, Config::retryAfterMaxMs());
        }

        $retryAfterMs = self::extractRetryAfterMs($e);
        if ($retryAfterMs !== null) {
            return min($retryAfterMs, Config::retryAfterMaxMs());
        }

        $statusCode = ResponseHelper::extractStatusCode($e);

        return self::calculateDelay($statusCode, $attempt, 30_000, 2_000, 1_000);
    }

    private static function assertValidArguments(
        int $maxAttempts,
        int $rateLimitDelayMs,
        int $serverErrorBaseDelayMs,
        int $defaultBaseDelayMs,
    ): void {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('$maxAttempts must be at least 1.');
        }

        if ($rateLimitDelayMs < 0 || $serverErrorBaseDelayMs < 0 || $defaultBaseDelayMs < 0) {
            throw new InvalidArgumentException('Delay values must be non-negative.');
        }
    }

    /**
     * @param  list<int>  $retryableStatusCodes
     *
     * @throws Throwable
     */
    private static function throwUnlessRetrying(
        Throwable $e,
        ?int $statusCode,
        array $retryableStatusCodes,
        int $attempt,
        int $maxAttempts,
    ): void {
        if (! self::isRetryableInternal($e, $statusCode, $retryableStatusCodes)) {
            throw $e;
        }

        if ($attempt >= $maxAttempts) {
            throw new RetriesExhaustedException($attempt - 1, $e);
        }
    }

    /**
     * @param  list<int>  $retryableStatusCodes
     */
    private static function isRetryableInternal(Throwable $e, ?int $statusCode, array $retryableStatusCodes): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RetryableException) {
                return true;
            }

            if ($current instanceof ConnectionException) {
                return true;
            }
        }

        if ($statusCode !== null && in_array($statusCode, $retryableStatusCodes, true)) {
            return true;
        }

        return false;
    }

    private static function resolveDelay(
        Throwable $e,
        ?int $statusCode,
        int $attempt,
        int $rateLimitDelayMs,
        int $serverErrorBaseDelayMs,
        int $defaultBaseDelayMs,
    ): int {
        $retryableDelayMs = self::extractRetryableExceptionDelayMs($e);
        if ($retryableDelayMs !== null) {
            return min($retryableDelayMs, Config::retryAfterMaxMs());
        }

        $retryAfterMs = self::extractRetryAfterMs($e);
        if ($retryAfterMs !== null) {
            return min($retryAfterMs, Config::retryAfterMaxMs());
        }

        return self::calculateDelay($statusCode, $attempt, $rateLimitDelayMs, $serverErrorBaseDelayMs, $defaultBaseDelayMs);
    }

    private static function calculateDelay(
        ?int $statusCode,
        int $attempt,
        int $rateLimitDelayMs,
        int $serverErrorBaseDelayMs,
        int $defaultBaseDelayMs,
    ): int {
        if ($statusCode === 429) {
            return $rateLimitDelayMs;
        }

        if ($statusCode !== null && $statusCode >= 500) {
            return $attempt * $serverErrorBaseDelayMs;
        }

        return $attempt * $defaultBaseDelayMs;
    }

    private static function extractRetryableExceptionDelayMs(Throwable $e): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RetryableException && $current->retryAfterSeconds !== null) {
                return $current->retryAfterSeconds * 1000;
            }
        }

        return null;
    }

    /**
     * Extract a Retry-After delay from the exception chain, if available.
     *
     * Supports both numeric seconds and HTTP-date formats per RFC 9110 §10.2.3.
     */
    private static function extractRetryAfterMs(Throwable $e): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $response = ResponseHelper::responseFrom($current);
            if ($response === null) {
                continue;
            }

            $header = $response->getHeaderLine('Retry-After');

            return $header === '' ? null : self::parseRetryAfterMs($header);
        }

        return null;
    }

    private static function parseRetryAfterMs(string $header): ?int
    {
        if (is_numeric($header)) {
            return (int) ((float) $header * 1000);
        }

        try {
            $retryAt = Carbon::parse($header);
            $delaySeconds = now()->diffInSeconds($retryAt, absolute: false);

            return $delaySeconds > 0 ? (int) ($delaySeconds * 1000) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
