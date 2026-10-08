# Building adapters

This guide covers the conventions used by the official adapters. Follow these patterns whether you're contributing to the official adapters package or building your own.

## Adapter structure

Each adapter follows this directory layout:

```
Linear/
├── LinearProvider.php      # Main provider implementing contracts
├── LinearClient.php        # Wraps the SDK, bootstraps credentials
├── LinearCredentials.php   # Spatie Data class for credentials
├── LinearMetadata.php      # Spatie Data class for metadata
├── LinearResource.php      # Abstract base for resource classes
├── Data/                   # Spatie Data DTOs for API responses
├── Resources/              # Concrete resource implementations
├── Enums/                  # Enums for statuses, types, etc.
└── Events/                 # Dispatchable events for sync
```

## Provider class

The provider implements `IntegrationProvider` plus whichever optional contracts make sense:

```php
class LinearProvider implements
    IntegrationProvider,
    HasIncrementalSync,
    HasHealthCheck,
    RedactsRequestData
{
    // ...
}
```

Common combinations:

| Scenario | Contracts |
|----------|-----------|
| Read-only sync | `HasIncrementalSync` + `HasHealthCheck` + `RedactsRequestData` |
| Full CRUD + sync | Add `CustomizesRetry` if the SDK throws custom exceptions |
| OAuth + sync | Add `HasOAuth2` |
| Webhooks only | `HandlesWebhooks` + `HasHealthCheck` |

## Client class

The client wraps the third-party SDK with lazy initialization:

```php
class LinearClient
{
    private ?SdkClient $sdk = null;

    public function __construct(
        private readonly Integration $integration,
    ) {}

    private function boot(): SdkClient
    {
        if ($this->sdk === null) {
            $credentials = $this->integration->credentials;
            // Validate types at runtime
            assert($credentials instanceof LinearCredentials);

            $this->sdk = new SdkClient($credentials->api_key);
        }

        return $this->sdk;
    }
}
```

Patterns to follow:
- Lazy-load the SDK on first use via a `boot()` method
- Validate credential/metadata types at bootstrap time
- Expose resource access via methods: `$client->issues()`, `$client->users()`

## Resource classes

Resources handle the actual API calls. They go through the fluent `at()->...->get()` builder (or the underlying `Integration::request()`) so everything is logged, rate-limited, and health-tracked:

```php
class LinearIssues extends LinearResource
{
    use HandlesErrors;

    public function get(string $id): LinearIssueData
    {
        return $this->integration
            ->at("issues/{$id}")
            ->as(LinearIssueData::class)
            ->get(fn () => $this->sdk->issues()->find($id));
    }

    public function since(string $cursor, Closure $callback): void
    {
        // Paginate through results, calling $callback for each item
    }
}
```

The `HandlesErrors` concern provides `executeWithErrorHandling()` for try/catch with logging.

## Input validation

Callers are internal application code, but the adapter is still a boundary worth guarding. For IDs, amounts, limits, and any value the upstream would reject with an opaque error, fail fast locally so the stack trace points at the actual caller. Keep a small base class for shared helpers rather than repeating guards in every method:

```php
abstract class StripeResource
{
    // ... constructor, sdk() accessor ...

    protected function assertId(string $id): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('Stripe resource id cannot be empty.');
        }
    }

    protected function assertPositive(int $value, string $parameter): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Stripe %s must be positive, got %d.',
                $parameter,
                $value,
            ));
        }
    }
}
```

Then each method validates its inputs before building the request:

```php
public function retrieve(string $id): Refund
{
    $this->assertId($id); // '' would otherwise build `refunds/`, hitting the list endpoint

    return $this->expectInstance(
        $this->integration->at("refunds/{$id}")->get(...),
        Refund::class,
    );
}
```

Two reasons this matters:
- Empty-string IDs silently route to the list endpoint of most REST APIs; the `assertId()` check catches the common footgun (uninit variable, `?? ''` fallback in caller code) before the URL is built.
- Non-positive limits and amounts would be rejected by the upstream anyway, but a local `InvalidArgumentException` points at the caller directly rather than surfacing as an opaque API error.

## Idempotency keys for non-idempotent writes

The core retries GET requests 3 times and non-GET requests once. If a POST reaches the upstream but the response is lost in transit, the retry re-runs the closure and creates a second record: a duplicate refund, a duplicate charge capture, a duplicate dispatched message.

For any state-changing POST where a duplicate would be bad, accept an optional idempotency key parameter on the resource method and pass it through to the builder. Core handles the generation, validation, and persistence:

```php
public function create(
    int $amount,
    string $currency,
    // ...
    ?string $idempotencyKey = null,
): PaymentIntent {
    $this->assertPositive($amount, 'amount');

    return $this->expectInstance(
        $this->integration
            ->at('payment_intents')
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)  // null -> auto-UUID, '' -> throws
            ->post(function (RequestContext $ctx) use ($params): PaymentIntent {
                return $this->sdk()->paymentIntents->create(
                    $params,
                    ['idempotency_key' => $ctx->idempotencyKey],
                );
            }),
        PaymentIntent::class,
    );
}
```

Three things to get right:

- Read the key from `$ctx->idempotencyKey` inside the closure. Core resolves the key once per call and preserves it across inner retries, so all attempts submit the same value to the upstream.
- Callers re-issuing the same operation across job runs (e.g. a queued job that retries after a crash) should pass a stable key derived from the originating domain event. The auto-generated UUID only protects transient retries inside one adapter call, not retries across separate calls.
- If the upstream API natively dedupes by key, mark the provider with [`SupportsIdempotency`](/reference/contracts#supportsidempotency) so core doesn't warn about decorative keys. Plumbing the key through is still useful for adapters that don't implement that contract: the value persists on `integration_requests.idempotency_key` for audit, even when the upstream ignores it.

See [Idempotency](/core-concepts/idempotency) for the consumer-facing picture.

## Capturing provider request IDs

Most provider APIs return a request ID in the response headers (Stripe `Request-Id`, GitHub `X-GitHub-Request-Id`, Zendesk `X-Zendesk-Request-Id`). Capturing that into `integration_requests.provider_request_id` makes "what happened on call X" trivially debuggable when filing a support ticket against the provider.

The `RequestContext` argument exists for this. Inside the closure, after the SDK call returns, report whatever metadata you can extract:

```php
$response = $this->integration
    ->at("charges/{$id}")
    ->get(function (RequestContext $ctx) use ($id): Charge {
        $charge = $this->sdk()->charges->retrieve($id);

        // Stripe SDK exposes the last response (and its headers) via the
        // client. Other SDKs vary: GitHub's knplabs lib has getLastResponse()
        // returning a PSR ResponseInterface; Postmark and Zendesk currently
        // hide headers entirely.
        $last = $this->sdk()->getLastResponse();
        if ($last !== null) {
            $ctx->reportResponseMetadata(
                providerRequestId: $last->headers['Request-Id'] ?? null,
            );
        }

        return $charge;
    });
```

Helper on the resource base class keeps the per-call code tight:

```php
protected function reportStripeMetadata(RequestContext $ctx): void
{
    $last = $this->sdk()->getLastResponse();
    if ($last === null) return;

    $ctx->reportResponseMetadata(
        providerRequestId: $last->headers['Request-Id'] ?? null,
    );
}
```

`reportResponseMetadata()` also accepts `rateLimitRemaining`, `rateLimitResetAt`, and `retryAfterSeconds`. When provided, those feed the [adaptive rate limiter](/core-concepts/rate-limiting#adaptive-rate-limits), so the next request is suppressed until the upstream's reset window passes. GitHub's `X-RateLimit-Remaining` / `X-RateLimit-Reset` are good candidates here; Stripe only emits limit headers on 429s.

If the SDK doesn't expose headers at all, the column stays `null` for that adapter. Fine, just document the limitation in the adapter's page.

## Return types

Two paths for typing resource responses, depending on what the SDK gives you back:

| SDK behaviour                                 | Return                              | Example                |
|-----------------------------------------------|-------------------------------------|------------------------|
| Already returns typed classes (phpdoc or native) | The SDK type directly            | Stripe (`\Stripe\Refund`) |
| Returns arrays or loosely-typed objects       | Local Spatie Data class             | GitHub (`GitHubIssueData`), Zendesk (`ZendeskTicketData`) |

Wrapping an already-typed SDK response in a local Data class duplicates work without adding anything. Wrapping a raw-array response gives you strong typing, a place to normalise odd shapes, and a stable surface that survives SDK swaps.

### Returning SDK types directly

`Integration::request()` returns `mixed` because the closure can return anything the pipeline needs to log. Narrow back to the expected type with a runtime check rather than PHPDoc annotations. A generic helper on the resource base class handles this once:

```php
/**
 * @template T of object
 * @param  class-string<T>  $class
 * @return T
 */
protected function expectInstance(mixed $value, string $class): object
{
    if (! $value instanceof $class) {
        throw new UnexpectedValueException(sprintf(
            'Expected instance of %s, got %s.',
            $class,
            get_debug_type($value),
        ));
    }

    return $value;
}
```

For list endpoints, most SDKs expose a typed Collection class (e.g. `\Stripe\Collection<\Stripe\Refund>`). Narrow to that rather than iterating into a plain `list<T>`, so callers keep access to pagination metadata.

Serialization for request logging still works: objects that implement `JsonSerializable` (like `\Stripe\StripeObject`) are JSON-encoded by the pipeline before being stored.

### Wrapping in local Data classes

When the SDK returns raw arrays, use Spatie Laravel Data:

```php
class LinearIssueData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $state,
        public readonly ?LinearUserData $assignee,
        public readonly array $original, // store original API response
    ) {}

    public static function prepareForPipeline(array $properties): array
    {
        // Transform raw API response into constructor-friendly shape
        return [
            'id' => $properties['id'],
            'title' => $properties['title'],
            'state' => $properties['state']['name'] ?? $properties['state'],
            'assignee' => $properties['assignee'] ?? null,
            'original' => $properties,
        ];
    }
}
```

Patterns to follow:
- Store the original API response in an `original` property for debugging.
- Use `prepareForPipeline()` to transform raw API responses.
- Extract nested data (attachments from HTML, fallback values, etc.) in the pipeline.
- In `rules()`, replace the rule for each non-nullable property that the provider can send as an empty string or an empty array.

### Empty strings and empty arrays

laravel-data adds a `required` rule to every non-nullable property, and `required` rejects `""` and `[]`. A consumer that sets laravel-data's `validation_strategy` to `Always` validates every response. If one value is empty, validation of the whole response fails with a `SchemaDriftException`. For a list endpoint or an incremental sync, the whole page fails because of one record.

If the provider can send an empty value, replace the inferred rule in `rules()`. With `present`, an empty value passes validation, but a missing key still fails:

```php
public static function rules(): array
{
    return [
        'subject' => ['present', 'string'],
    ];
}
```

[`IntegrationTestCase`](/testing/testing#integrationtestcase) sets `validation_strategy` to `Always`, so a test whose fixture has the empty value fails, and you find the problem before a consumer does.

## Events

Each synced item gets its own event. It must extend `Integrations\Sync\SyncItemEvent`, the base class the framework uses to identify per-item events:

```php
use Integrations\Sync\SyncItemEvent;

class LinearIssueSynced extends SyncItemEvent
{
    public function __construct(
        public readonly Integration $integration,
        public readonly LinearIssueData $issue,
    ) {}
}
```

That's the only event an adapter ships. The core fires [`SyncCompleted`](/reference/events#sync) once a run reconciles and [`SyncItemFailed`](/reference/events#sync) when an item exhausts its retries, so adapters no longer define their own per-failure or per-completion events.

## Sync pattern

A provider's `sync()` / `syncIncremental()` enumerates the items to sync and hands each one to `$session->dispatch()`. It doesn't process items, touch the cursor, or return a result. The framework wraps each item in a queued job, batches them, runs the listeners, and advances the cursor once every job has succeeded.

```php
use Integrations\Concerns\ReducesCheckpointsByMax;
use Integrations\Sync\SyncSession;

class LinearProvider implements IntegrationProvider, HasIncrementalSync
{
    use ReducesCheckpointsByMax;

    public function syncIncremental(Integration $integration, SyncSession $session): void
    {
        $client = new LinearClient($integration);
        $startTime = $session->cursor() ?? now()->subDay()->toIso8601String();

        // Subtract an overlap buffer to catch items updated between syncs.
        $bufferedStart = Carbon::parse($startTime)->subHour()->toIso8601String();

        $client->issues()->since($bufferedStart, function ($issue) use ($integration, $session): void {
            $session->dispatch(
                new LinearIssueSynced($integration, $issue),
                checkpointValue: $issue->updated_at,
                externalId: (string) $issue->id,
            );
        });
    }

    // sync() (full re-sync), defaultSyncInterval(), defaultRateLimit()...
}
```

Things to get right:

- Subtract an overlap buffer from the cursor (1 hour in the official adapters). The framework's monotonic cursor advance means re-presenting items inside that window is safe; it won't regress progress.
- Pass each item's checkpoint value (its `updated_at`, an id) so the framework can reduce the run into the next cursor. `use ReducesCheckpointsByMax;` gives you the common "max wins" reduction; override `reduceCheckpoints()` for non-comparable cursors.
- Don't track success/failure or advance the cursor yourself; the framework does both from the `integration_sync_items` rows.
- Consumers should use [`upsertByExternalId()`](/features/id-mapping#upsert-by-external-id) in their listeners since overlap is expected, and their listeners must not implement `ShouldQueue` (see [Scheduled syncs](/features/scheduled-syncs#listeners-must-not-be-queued)).

There's no longer any need to checkpoint the cursor mid-iteration: per-item tracking is the checkpoint. If the enumerating job is SIGKILLed or times out, the next run starts over from the unchanged cursor; once the batch is dispatched, the cursor advances per completed item.

## Auto-registration

Adapter packages can auto-register their providers so users don't need to manually edit `config/integrations.php`. Ship a Laravel service provider that calls `IntegrationManager::registerDefaults()`:

```php
<?php

namespace Integrations\Adapters;

use Illuminate\Support\ServiceProvider;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\IntegrationManager;

class IntegrationAdaptersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        IntegrationManager::registerDefaults([
            'github' => GitHubProvider::class,
            'zendesk' => ZendeskProvider::class,
        ]);
    }
}
```

Then register it for Laravel's package auto-discovery in your `composer.json`:

```json
"extra": {
    "laravel": {
        "providers": [
            "Integrations\\Adapters\\IntegrationAdaptersServiceProvider"
        ]
    }
}
```

With this in place, `composer require` is all the user needs. Users can still override any key in their published config if they want a custom provider class.

## Contributing to the official package

To add a new adapter to `pocketarc/laravel-integrations-adapters`:

1. Create the adapter directory under `src/Linear/` (using your service's name).
2. Follow the patterns above.
3. Add a `README.md` inside your adapter directory.
4. Add tests under `tests/Unit/Linear/`.
5. Add your provider to `IntegrationAdaptersServiceProvider::register()` so it's auto-registered.
6. Open a PR.

## Releasing a community adapter

If you prefer to maintain your own package:

1. Create a new Composer package.
2. Require `pocketarc/laravel-integrations` as a dependency.
3. Follow the same patterns for consistency.
4. Ship a service provider that calls `IntegrationManager::registerDefaults()` (see [Auto-registration](#auto-registration) above).
5. Register it for auto-discovery in your `composer.json`.
6. Submit your adapter for listing on these docs by opening an issue on the [laravel-integrations](https://github.com/solmyrhq/laravel-integrations) repository.
