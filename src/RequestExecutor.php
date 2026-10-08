<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Integrations\Concerns\ResolvesRetries;
use Integrations\Contracts\LimitsRequestLogging;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Enums\FailureClass;
use Integrations\Events\RequestCompleted;
use Integrations\Events\RequestFailed;
use Integrations\Exceptions\SchemaDriftException;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Support\BinaryGuard;
use Integrations\Support\CallbackInspector;
use Integrations\Support\Config;
use Integrations\Support\EndpointPattern;
use Integrations\Support\FailureClassifier;
use Integrations\Support\JsonBody;
use Integrations\Support\ModelKey;
use Integrations\Support\Redactor;
use Integrations\Support\ResponseHelper;
use RuntimeException;
use Spatie\LaravelData\Data;
use Throwable;

final class RequestExecutor
{
    use ResolvesRetries;

    private ?int $lastCreatedRequestId = null;

    private readonly RequestCache $cache;

    private readonly RateLimiter $rateLimiter;

    private readonly CircuitBreaker $circuitBreaker;

    private readonly IdempotencyKeyManager $idempotencyKeys;

    private ?RequestContext $context = null;

    public function __construct(
        private readonly Integration $integration,
    ) {
        $this->cache = new RequestCache($integration);
        $this->rateLimiter = new RateLimiter($integration);
        $this->circuitBreaker = new CircuitBreaker($integration);
        $this->idempotencyKeys = new IdempotencyKeyManager($integration);
    }

    /**
     * Execute a request against the integration, with caching, retries, and logging.
     *
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)  $callback
     */
    public function execute(
        string $endpoint,
        string $method,
        ?string $responseClass,
        Closure $callback,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        ?int $retryOfId,
        int $maxAttempts,
        ?string $idempotencyKey = null,
    ): mixed {
        $redactedRequestData = $this->redactRequestData($encodedRequestData);

        // Keying a redacted request on its unredacted body would put a
        // brute-forceable xxh128 hash of the secret in request_data_hash.
        if ($redactedRequestData !== $encodedRequestData) {
            $cacheFor = null;
            $serveStale = false;
        }

        $encodedRequestData = $redactedRequestData;

        if ($cacheFor !== null) {
            $cached = $this->cache->serve($endpoint, $method, $encodedRequestData, $responseClass);
            if ($cached !== null) {
                return $cached;
            }
        }

        $this->context = new RequestContext($idempotencyKey);

        try {
            return $this->idempotencyKeys->around(
                $idempotencyKey,
                fn () => $this->requestWithRetries(
                    $endpoint, $method, $responseClass, $callback, $relatedTo,
                    $encodedRequestData, $cacheFor, $serveStale, $maxAttempts, $retryOfId,
                ),
            );
        } finally {
            $this->context = null;
        }
    }

    /**
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)  $callback
     */
    private function requestWithRetries(
        string $endpoint,
        string $method,
        ?string $responseClass,
        Closure $callback,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        int $maxAttempts,
        ?int $retryOfId = null,
    ): mixed {
        $firstRequestId = $retryOfId;

        $this->lastCreatedRequestId = null;

        $callbackAcceptsContext = $this->callbackAcceptsContext($callback);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $isLastAttempt = $attempt >= $maxAttempts;
            $allowStale = $serveStale && $isLastAttempt;

            try {
                // Re-enforce both gates on every attempt so the breaker
                // tripping or the rate limit exhausting mid-loop short-circuits
                // the next retry instead of slipping through.
                $this->circuitBreaker->enforce();
                $this->rateLimiter->enforce();

                return $this->executeRequest(
                    $endpoint, $method, $responseClass, $callback, $callbackAcceptsContext, $relatedTo,
                    $encodedRequestData, $cacheFor, $allowStale,
                    retryOfId: $firstRequestId,
                );
            } catch (Throwable $e) {
                $firstRequestId ??= $this->lastCreatedRequestId;

                $shouldRetry = $this->shouldRetry($e, $attempt, $maxAttempts);

                if (! $shouldRetry) {
                    return $this->serveStaleOrRethrow($e, $serveStale && ! $allowStale, $endpoint, $method, $encodedRequestData, $responseClass);
                }

                $delayMs = $this->resolveRetryDelay($e, $attempt);

                usleep($delayMs * 1000);
                $this->context?->resetResponseMetadata();
            }
        }

        throw new RuntimeException('Retry logic exhausted without result.');
    }

    /**
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)  $callback
     */
    private function executeRequest(
        string $endpoint,
        string $method,
        ?string $responseClass,
        Closure $callback,
        bool $callbackAcceptsContext,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        ?int $retryOfId = null,
    ): mixed {
        $startTime = microtime(true);
        $responseSuccess = false;
        $responseData = null;
        $error = null;
        $failureClass = null;

        try {
            $raw = $this->invokeCallback($callback, $callbackAcceptsContext);

            if ($this->context !== null) {
                $this->rateLimiter->recordUsage($this->context);
            }

            [$responseCode, $responseData, $parsed] = ResponseHelper::normalize($raw);
            $result = $this->convertResponse($parsed, $responseClass, $endpoint, $cacheFor);
            $responseSuccess = true;
        } catch (Throwable $e) {
            $failureClass = FailureClassifier::classify($e, $this->integration->provider());
            $this->circuitBreaker->recordFailure($failureClass);

            [$responseCode, $error, $result] = $this->handleRequestError(
                $e, $failureClass, $startTime, $endpoint, $method, $responseClass, $encodedRequestData,
                $retryOfId, $relatedTo, $responseData, $cacheFor, $serveStale,
            );
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1_000);

        $request = $this->persistRequest(
            $endpoint, $method, $encodedRequestData, $retryOfId,
            $relatedTo, $responseCode, $responseData, $responseSuccess,
            $error, $failureClass, $durationMs, $cacheFor,
        );
        $this->lastCreatedRequestId = is_int($request->getKey()) ? $request->getKey() : null;

        if ($responseSuccess) {
            $this->circuitBreaker->recordSuccess();
            RequestCompleted::dispatch($this->integration, $request);
            $this->integration->recordSuccess();
        } else {
            RequestFailed::dispatch($this->integration, $request);
            // $failureClass is always set on the failure path: the catch above
            // assigns it before handleRequestError(), which only returns (vs.
            // rethrows) once it has.
            $this->integration->recordFailure($failureClass);
        }

        return $result;
    }

    /**
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)  $callback
     */
    private function callbackAcceptsContext(Closure $callback): bool
    {
        return CallbackInspector::acceptsContext($callback);
    }

    /**
     * Invoke the user closure, passing the active RequestContext if the
     * closure declared a parameter. The thread-local hook is also set so
     * closures wrapped behind layers (and zero-arg ones) can reach
     * Integration::currentContext() if they need to.
     *
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)  $callback
     */
    private function invokeCallback(Closure $callback, bool $callbackAcceptsContext): mixed
    {
        $context = $this->context;

        if ($context === null) {
            // Defensive: requestWithRetries is only reachable through
            // execute(), which always assigns $this->context first. If we
            // got here without one, something has gone wrong upstream.
            return $callback();
        }

        // Save and restore so a closure that nests another integration call
        // doesn't clobber the outer context when the inner call unwinds.
        $previousContext = Integration::currentContext();
        Integration::setCurrentContext($context);

        try {
            return $callbackAcceptsContext ? $callback($context) : $callback();
        } finally {
            Integration::setCurrentContext($previousContext);
        }
    }

    /**
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     * @return array{?int, array<string, mixed>, mixed}
     *
     * @throws Throwable
     */
    private function handleRequestError(
        Throwable $e,
        FailureClass $failureClass,
        float $startTime,
        string $endpoint,
        string $method,
        ?string $responseClass,
        ?string $encodedRequestData,
        ?int $retryOfId,
        ?Model $relatedTo,
        ?string $responseData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
    ): array {
        $error = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'trace' => mb_strcut($e->getTraceAsString(), 0, 2000),
        ];

        $responseCode = ResponseHelper::extractStatusCode($e);

        $result = $serveStale
            ? $this->cache->serveStale($endpoint, $method, $encodedRequestData, $responseClass)
            : null;

        if ($result === null) {
            $this->integration->recordFailure($failureClass);
            $durationMs = (int) ((microtime(true) - $startTime) * 1_000);

            $request = $this->persistRequest(
                $endpoint, $method, $encodedRequestData, $retryOfId,
                $relatedTo, $responseCode, $responseData, false,
                $error, $failureClass, $durationMs, $cacheFor,
            );
            $this->lastCreatedRequestId = is_int($request->getKey()) ? $request->getKey() : null;

            RequestFailed::dispatch($this->integration, $request);

            throw $e;
        }

        return [$responseCode, $error, $result];
    }

    /**
     * @param  array<string, mixed>|null  $error
     */
    private function persistRequest(
        string $endpoint,
        string $method,
        ?string $requestData,
        ?int $retryOfId,
        ?Model $relatedTo,
        ?int $responseCode,
        ?string $responseData,
        bool $responseSuccess,
        ?array $error,
        ?FailureClass $failureClass,
        int $durationMs,
        ?CarbonInterface $cacheFor,
    ): IntegrationRequest {
        $provider = $this->integration->provider();

        if ($provider instanceof RedactsRequestData && $responseData !== null) {
            $redactedResponseData = Redactor::redact($responseData, $provider->sensitiveResponseFields());

            if ($redactedResponseData !== $responseData) {
                $cacheFor = null;
            }

            $responseData = $redactedResponseData;
        }

        [$responseData, $cacheFor] = BinaryGuard::sanitizeResponseBody($responseData, $cacheFor);

        if (JsonBody::isUnencodable($responseData)) {
            $cacheFor = null;
        }

        $responseData = $this->limitStoredResponseBody(
            $responseData,
            $endpoint,
            $method,
            $cacheFor,
        );

        $request = $this->integration->requests()->create([
            'endpoint' => $endpoint,
            'method' => $method,
            'request_data' => RequestCache::storedRequestData($requestData),
            'request_data_hash' => RequestCache::requestDataHash($requestData),
            'idempotency_key' => $this->context?->idempotencyKey,
            'provider_request_id' => $this->context?->providerRequestId(),
            'retry_of' => $retryOfId,
            'related_type' => $relatedTo !== null ? $relatedTo->getMorphClass() : null,
            'related_id' => $relatedTo !== null ? ModelKey::toString($relatedTo->getKey()) : null,
            'response_code' => $responseCode,
            'response_data' => $responseData,
            'response_success' => $responseSuccess,
            'error' => $error,
            'failure_class' => $failureClass?->value,
            'duration_ms' => $durationMs,
            'expires_at' => $cacheFor,
        ]);

        $this->integration->trackSyncRequestId($request->id);

        return $request;
    }

    /**
     * Drop or truncate a stored response body per the provider's opt-out and
     * the configured size cap. Both only govern what is kept for debugging:
     * a body the package reads back is returned untouched, because a cached
     * response is the cache's payload and an idempotent write's response
     * backs `IdempotencyConflict` recovery. Storing a stub in either place
     * would turn a logging setting into a correctness bug.
     */
    private function limitStoredResponseBody(
        ?string $responseData,
        string $endpoint,
        string $method,
        ?CarbonInterface $cacheFor,
    ): ?string {
        if ($responseData === null || $responseData === '') {
            return $responseData;
        }

        if ($cacheFor !== null || $this->context?->idempotencyKey !== null) {
            return $responseData;
        }

        $provider = $this->integration->provider();

        if ($provider instanceof LimitsRequestLogging
            && EndpointPattern::matchesAny($provider->unloggedResponseEndpoints(), $endpoint, $method)) {
            return sprintf('[response body not stored: %s bytes; provider opted out]', number_format(mb_strlen($responseData, '8bit')));
        }

        $maxBytes = Config::loggingMaxResponseBytes();

        if ($maxBytes !== null && mb_strlen($responseData, '8bit') > $maxBytes) {
            // Reserve the marker's own length so the stored value stays within
            // the cap rather than the cap plus a marker. The 1KB config floor
            // leaves ample room for it.
            $marker = sprintf(
                '... [truncated from %s bytes; over logging.max_response_bytes]',
                number_format(mb_strlen($responseData, '8bit')),
            );

            return mb_strcut($responseData, 0, $maxBytes - mb_strlen($marker, '8bit')).$marker;
        }

        return $responseData;
    }

    /**
     * Attempt to serve a stale cached response; rethrow the original exception if unavailable.
     *
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     *
     * @throws Throwable
     */
    private function serveStaleOrRethrow(
        Throwable $e,
        bool $tryStale,
        string $endpoint,
        string $method,
        ?string $encodedRequestData,
        ?string $responseClass,
    ): mixed {
        $stale = $tryStale
            ? $this->cache->serveStale($endpoint, $method, $encodedRequestData, $responseClass)
            : null;

        if ($stale !== null) {
            return $stale;
        }

        throw $e;
    }

    /**
     * @param  class-string<Data>|null  $responseClass
     */
    private function convertResponse(mixed $parsed, ?string $responseClass, string $endpoint, ?CarbonInterface $cacheFor): mixed
    {
        if ($responseClass !== null && (is_array($parsed) || is_object($parsed))) {
            try {
                return $responseClass::from($parsed);
            } catch (Throwable $e) {
                throw new SchemaDriftException(
                    integration: $this->integration,
                    responseClass: $responseClass,
                    parsedData: $parsed,
                    source: 'live',
                    previous: $e,
                );
            }
        }

        if ($cacheFor !== null && $responseClass === null && is_object($parsed)) {
            Log::warning("Caching response for '{$endpoint}' without a responseClass: cached responses will be returned as arrays, not ".get_class($parsed).'. Use ->as(...) on the fluent builder for type-consistent caching.');
        }

        return $parsed;
    }

    private function redactRequestData(?string $requestData): ?string
    {
        if ($requestData === null) {
            return null;
        }

        $provider = $this->integration->provider();

        if ($provider instanceof RedactsRequestData) {
            return Redactor::redact($requestData, $provider->sensitiveRequestFields());
        }

        return $requestData;
    }
}
