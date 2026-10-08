# Testing

A testing fake follows the `Http::fake()` pattern, with no real API calls and no database writes.

## Activating the fake

```php
use Integrations\Models\IntegrationRequest;

IntegrationRequest::fake([
    '/api/v2/tickets.json' => ['tickets' => [['id' => 1, 'subject' => 'Test']]],
    'customers.create' => fn () => ['id' => 'cus_123', 'email' => 'test@example.com'],
]);
```

When the fake is active, both `request()` and `requestAs()` skip rate limiting, caching, health tracking, and database persistence entirely. They record requests in memory and return your fake responses (or `null` for unmatched endpoints).

### Wildcard endpoints

Use `*` to match dynamic segments in endpoint strings:

```php
IntegrationRequest::fake([
    'tickets/*/comments.json' => ['comments' => []],
    'tickets/*.json' => ['id' => 1, 'subject' => 'Test'],
]);
```

More specific patterns take priority -- `tickets/*/comments.json` matches before `tickets/*.json`. Exact matches always take priority over wildcards.

### Method-aware fakes

Prefix an endpoint with an HTTP method to return different responses for different methods on the same endpoint:

```php
IntegrationRequest::fake([
    'GET:tickets/123.json' => ['id' => 123, 'subject' => 'Bug report'],
    'PUT:tickets/123.json' => ['id' => 123, 'subject' => 'Updated'],
]);
```

Method-prefixed entries take priority over unprefixed ones. Unprefixed entries match any method (backwards compatible). Wildcards and method prefixes can be combined: `GET:tickets/*.json`.

### Integration-scoped fakes

When testing flows that span multiple integrations, scope fake responses to specific integrations:

```php
IntegrationRequest::fake()
    ->forIntegration($zendesk, [
        'tickets/*.json' => ['id' => 1, 'subject' => 'Test'],
    ])
    ->forIntegration($github, [
        'repos/*/*/issues' => ['number' => 42, 'title' => 'Bug'],
    ]);
```

Scoped responses are checked first. If no scoped match is found, the global responses are used as a fallback:

```php
IntegrationRequest::fake(['fallback/endpoint' => ['ok' => true]])
    ->forIntegration($zendesk, ['tickets/*.json' => ['id' => 1]]);

// $zendesk matches scoped response for tickets/*.json
// $zendesk matches global fallback for fallback/endpoint
// $github matches global fallback for fallback/endpoint
```

You can pass either an `Integration` model or an integer ID to `forIntegration()`.

### Provider passthrough

The fake intercepts every `request()` and `requestAs()` call, returning `null` for any endpoint it has no response for. That gets in the way when a provider is faked through a *different* layer -- a call routed through `Integration::request()` (so the breaker, retries, and rate limiter apply) whose response is stubbed one level down, at the SDK or HTTP client. With no registered response the fake returns `null`, and that lower layer never runs.

Mark such a provider as passthrough and its requests run against the real executor instead of being served from the fake:

```php
IntegrationRequest::fake([
    'tickets/*.json' => ['id' => 1, 'subject' => 'Test'],
])->passthrough('openrouter');
```

`zendesk` calls are faked as usual; `openrouter` calls fall through to the real executor -- breaker, retries, rate limit, transport audit, and your request callback all run, so whatever you've stubbed underneath (a Prism fake, an `Http::fake()`, ...) supplies the response.

Passthrough is opt-in and scoped by [provider](/core-concepts/providers) string. Pass several at once, or call it more than once from different fake-setup helpers -- the list de-duplicates:

```php
IntegrationRequest::fake()->passthrough('openrouter', 'pinecone');
```

A passthrough request isn't recorded by default, so it won't appear in `assertRequested()` -- it ran against the real executor, not the fake. Call `recordPassthrough()` when a test still wants to assert the call was made:

```php
IntegrationRequest::fake()
    ->passthrough('openrouter')
    ->recordPassthrough();

// ... exercise the code, then ...
IntegrationRequest::assertRequested('chat/completions');
```

The real executor writes its own `integration_requests` row for a passthrough request either way; `recordPassthrough()` only adds the request to the in-memory log the assertions read.

Unmatched requests for non-passthrough providers still return `null`. Passthrough is deliberately not a blanket "unmatched goes real", which would let a forgotten fake silently hit a live API.

## Making assertions

```php
IntegrationRequest::assertRequested('/api/v2/tickets.json');
IntegrationRequest::assertRequested('/api/v2/tickets.json', times: 2);
IntegrationRequest::assertNotRequested('customers.delete');
IntegrationRequest::assertRequestedWith('customers.create', function (string $requestData) {
    return str_contains($requestData, 'test@example.com');
});
IntegrationRequest::assertRequestCount(5);
IntegrationRequest::assertNothingRequested();
```

Assertions support wildcards too -- `assertRequested('tickets/*.json')` matches any recorded `tickets/{id}.json` request.

### Filtering assertions

Filter assertions by HTTP method and/or integration:

```php
IntegrationRequest::assertRequested('tickets/123.json', times: 1, method: 'GET');
IntegrationRequest::assertRequested('tickets/123.json', times: 1, method: 'PUT');
IntegrationRequest::assertNotRequested('tickets/123.json', method: 'DELETE');
IntegrationRequest::assertRequested('tickets/*.json', integrationId: $zendesk->id);
```

For symmetry with the `METHOD:endpoint` form accepted by `fake()`, assertions accept the same prefix in the endpoint argument. These two forms are equivalent:

```php
IntegrationRequest::assertRequested('PUT:tickets/*.json', times: 1);
IntegrationRequest::assertRequested('tickets/*.json', times: 1, method: 'PUT');
```

Passing a prefix *and* an explicit `method:` that disagrees raises `InvalidArgumentException` so the mismatch isn't silent.

## Sequences and exceptions

```php
use Integrations\Testing\ResponseSequence;

IntegrationRequest::fake([
    '/api/items' => new ResponseSequence('first', 'second', 'third'),
    '/api/fail' => new \RuntimeException('Service unavailable'),
]);

// Returns 'first', 'second', 'third', then null
$r1 = $integration->request(endpoint: '/api/items', method: 'GET', callback: fn () => Http::get($url));

// Throws RuntimeException
$integration->request(endpoint: '/api/fail', method: 'GET', callback: fn () => Http::get($url));
```

## Cleanup

```php
IntegrationRequest::stopFaking();
```

## Test helpers

### CreatesIntegration trait

A `createIntegration()` method for test setup. Creates an integration with default values and a registered provider. Use this when your test class already extends a base `TestCase` and you just need a quick integration instance:

```php
use Integrations\Testing\CreatesIntegration;
use Tests\TestCase;

class TicketSyncTest extends TestCase
{
    use CreatesIntegration;

    public function test_syncs_tickets(): void
    {
        $integration = $this->createIntegration('github', GitHubProvider::class);

        IntegrationRequest::fake([
            'tickets.list' => ['tickets' => [['id' => 1, 'subject' => 'Bug report']]],
        ]);

        $result = $integration
            ->at('tickets.list')
            ->as(TicketListResponse::class)
            ->get(fn () => Http::get('https://api.github.com/issues'));

        IntegrationRequest::assertRequested('tickets.list');
    }
}
```

The trait handles creating the `Integration` model with sensible defaults (active status, healthy state, a registered provider) so you can focus on the behavior under test.

### IntegrationTestCase

Base test class for adapter packages, built on Orchestra Testbench. It:

- registers the laravel-data and laravel-integrations service providers, and any providers that `getAdapterProviders()` returns
- runs Laravel's default migrations and the package's migrations on an in-memory SQLite database
- sets laravel-data's `validation_strategy` to `Always`, so `Data::from()` validates in your tests as it does for a consumer that uses that setting
- uses the `CreatesIntegration` trait
- deactivates the fake in `tearDown()`

It doesn't activate the fake or create an integration. Do both in your test or in `setUp()`:

```php
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Testing\IntegrationTestCase;

class GitHubProviderTest extends IntegrationTestCase
{
    private Integration $integration;

    protected function getAdapterProviders($app): array
    {
        return [GitHubServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = $this->createIntegration('github', GitHubProvider::class);
    }

    public function test_fetches_repository(): void
    {
        IntegrationRequest::fake([
            'repos.get' => ['id' => 42, 'name' => 'laravel-integrations'],
        ]);

        $repo = $this->integration
            ->at('repos.get')
            ->as(RepoData::class)
            ->get(fn () => Http::get('https://api.github.com/repos/pocketarc/laravel-integrations'));

        IntegrationRequest::assertRequested('repos.get');
    }

    public function test_handles_api_failure(): void
    {
        IntegrationRequest::fake([
            'repos.get' => new \RuntimeException('API rate limit exceeded'),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->integration->request(
            endpoint: 'repos.get',
            method: 'GET',
            callback: fn () => Http::get('https://api.github.com/repos/pocketarc/laravel-integrations'),
        );
    }
}
```

Use `IntegrationTestCase` as the base class for an adapter package's tests, so that you don't repeat the Testbench, database and provider setup in every test class.

Because `validation_strategy` is `Always`, a test fails if its fixture has an empty string or an empty array in a non-nullable property of a Data class. Check whether the provider can send that value. If it can, override `rules()` on the Data class (see [empty strings and empty arrays](/adapters/building-adapters#empty-strings-and-empty-arrays)). If it can't, correct the fixture.
