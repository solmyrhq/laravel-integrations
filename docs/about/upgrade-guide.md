# Upgrade guide

This project follows [Semantic Versioning](https://semver.org/). Minor and patch releases will never contain breaking changes.

## 6.4 to 6.5

You do not need to change your code for 6.5 unless your tests extend `IntegrationTestCase`.

`IntegrationTestCase` now sets laravel-data's `validation_strategy` to `Always`. If your tests extend `IntegrationTestCase`, a test can fail when its fixture has an empty string or an empty array in a non-nullable property of a Data class. For each failure, check whether the provider can send that value. If it can, override `rules()` on the Data class (see [empty strings and empty arrays](/adapters/building-adapters#empty-strings-and-empty-arrays)). If it can't, correct the fixture.

To keep the old behaviour, set `data.validation_strategy` back to `OnlyRequests` in your own test case:

```php
use Spatie\LaravelData\Support\Creation\ValidationStrategy;

protected function getEnvironmentSetUp($app): void
{
    parent::getEnvironmentSetUp($app);

    $app['config']->set('data.validation_strategy', ValidationStrategy::OnlyRequests->value);
}
```

## 6.3 to 6.4

6.4 adds a column and an index to the webhooks table. There are no breaking changes, and no code change is required.

### Add the column and the index

The `attempts` column holds the number of times a job has claimed the webhook. The `(status, updated_at)` index is for the queries in [`integrations:recover-webhooks`](/reference/artisan-commands#integrations-recover-webhooks).

A fresh install gets both from the published baseline migration. On an existing deployment, you have already run that migration, so add a downstream migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Integrations\Support\Config;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Config::tablePrefix().'_webhooks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->index(['status', 'updated_at']);
        });
    }
};
```

Then run `php artisan migrate` before any web server, queue worker, or scheduled command runs the 6.4 code. `ProcessWebhook` writes `attempts` when it claims a webhook, and `integrations:recover-webhooks` reads it, so both fail until the column exists. Existing webhooks have an `attempts` value of 0. `integrations:recover-webhooks` never retries a failed webhook with 0 attempts, so a webhook that failed before the upgrade stays `failed`.

### Behaviour changes

`integrations:recover-webhooks` now also dispatches a job for a webhook that has been `pending` for longer than [`webhook.pending_timeout`](/reference/configuration#webhook). Its first run after the upgrade dispatches a job for every such webhook, however long ago its job was lost. Failed webhooks are not retried unless you raise `webhook.max_attempts`. The recommended schedule for the command is now every five minutes instead of hourly, because a failed webhook is retried only when the command runs.

The hash in the [response cache](/core-concepts/response-caching#how-it-works) key has a new format. The package does not serve responses that it cached before the upgrade. On the next matching request, the package calls the upstream again and caches the new response.

## 6.1 to 6.2

6.2 adds two columns to the integrations table. There are no breaking changes, and no code change is required.

### Add the new columns

The package derives the multiplier on the [failure backoff](/features/scheduled-syncs#failure-backoff) from `consecutive_sync_failures`, and `sync_stale_alerted_at` holds the open/closed state for the [staleness signal](/core-concepts/health-monitoring#sync-staleness).

A fresh install gets them from the published baseline migration. On an existing deployment, you have already run that migration, so add a downstream migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Integrations\Support\Config;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Config::tablePrefix().'s', function (Blueprint $table): void {
            $table->unsignedInteger('consecutive_sync_failures')->default(0);
            $table->timestamp('sync_stale_alerted_at')->nullable();
        });
    }
};
```

Then run `php artisan migrate` before any queue worker or scheduled command runs the 6.2 code. `SyncIntegration`, `FinaliseSyncRun`, `integrations:sync` and `integrations:prune` read or write these columns and fail until the columns exist. Existing integrations have a `consecutive_sync_failures` value of 0. You don't need to backfill it: the first run that is finalised with failures after the upgrade sets it to 1.

### Behaviour changes

`next_sync_at` now advances after a run that finalised with failures, where before only a clean run moved it. An integration held due on every scheduler tick by a failing item will start pacing itself instead. `last_synced_at` still moves only on a clean run, so anything reading it for "when did this last work" is unaffected.

## 5.x to 6.0

In 6.0, `mapExternalId()` throws instead of re-pointing a mapping that another model of the same type holds on the same integration. Most apps need no code changes: the call is normally made once per external ID, and `upsertByExternalId()` handles the collision internally. The work is in direct callers that relied on the overwrite, and in checking for rows that lost their mapping before the upgrade.

### Why

`mapExternalId()` was an `updateOrCreate` keyed on `(external_id, internal_type)` with `internal_id` in the update payload, and `upsertByExternalId()` read then wrote with no lock. Two workers syncing the same upstream record both found no mapping, both inserted a local row, and the second one's call moved the mapping onto its own row.

Nothing threw. The result was two rows and one mapping, and the row that lost it was unreachable: it had every column it started with, so it still came back from ordinary queries, but `findExternalId()` returned null for it and no upstream call could address it. A consumer running ten queue workers produced two such rows in three days, and one of them cost about $72/day in wasted AI calls before anyone noticed, because it was selected for work, attempted, and failed on every cycle.

### 1. Check for existing orphans

Damage done before the upgrade is still there. For each model you map:

```bash
php artisan integrations:find-orphans "App\Models\Ticket"
```

Expect zero rows. Each one that turns up needs its `integration_mappings` row restored, or merged into the row that kept the mapping, depending on what the two rows hold.

### 2. If you call `mapExternalId()` on an already-mapped ID

It now throws `MappingAlreadyClaimed`. Calling it twice with the *same* model is still fine, so this only affects deliberate re-points.

**Before (5.x):**

```php
$integration->mapExternalId('ticket-4521', $newTicket); // silently moved it
```

**After (6.0):**

```php
$integration->remapExternalId('ticket-4521', $newTicket);
```

If you were guarding the call yourself, you can drop the guard. This pattern:

```php
$existing = $integration->resolveMapping($externalId, Ticket::class);

if ($existing === null || $existing->id === $ticket->id) {
    $integration->mapExternalId($externalId, $ticket);
}
```

becomes a `try`/`catch` on `MappingAlreadyClaimed`, or nothing at all if reaching that state is a bug you'd rather hear about.

### 3. Optional: pin the mapping table's collation

Only on MySQL and MariaDB, and only if your own tables use a different collation from the one Laravel gave `integration_mappings`. The symptom is `Illegal mix of collations` when you compare `internal_id` against your primary keys.

```php
// config/integrations.php
'mappings' => [
    'collation' => 'utf8mb4_general_ci', // match your domain tables
],
```

Then publish and run the migrations. `0001_01_01_000002_pin_integration_mappings_collation.php` applies it to an existing table and no-ops when the setting is null or the driver has no per-column collation.

`integrations:find-orphans` compares in PHP, so you don't need this just to run it.

## 5.2 to 5.3

5.3 adds four columns, an index, and a table. There are no breaking changes, and no code change is required.

### Add the columns, the index, and the table

`integration_requests.failure_class` holds the `FailureClass` of a failed request. For a failure logged inside a sync item run, `integration_logs.attempt` and `max_attempts` hold the attempt number and the maximum number of attempts. `integrations.anomaly_alerted_at` holds the open/closed state for the [anomaly signal](/advanced/circuit-breaker#anomaly-signal), and `integration_incidents` holds the [incident history](/core-concepts/health-monitoring#incident-history).

A fresh install gets all of them from the published baseline migration. On an existing deployment, you have already run that migration, so add a downstream migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Integrations\Support\Config;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = Config::tablePrefix();

        Schema::table("{$prefix}s", function (Blueprint $table): void {
            $table->timestamp('anomaly_alerted_at')->nullable();
        });

        Schema::table("{$prefix}_requests", function (Blueprint $table) use ($prefix): void {
            $table->string('failure_class')->nullable();
            $table->index(['integration_id', 'failure_class', 'created_at'], "{$prefix}_requests_failure_class_idx");
        });

        Schema::table("{$prefix}_logs", function (Blueprint $table): void {
            $table->unsignedSmallInteger('attempt')->nullable();
            $table->unsignedSmallInteger('max_attempts')->nullable();
        });

        Schema::create("{$prefix}_incidents", function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('integration_id')->constrained("{$prefix}s")->cascadeOnDelete();
            $table->string('status', 16)->default('open');
            $table->string('source', 16);
            $table->string('reason');
            $table->string('peak_severity');
            $table->timestamp('opened_at');
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'status']);
            $table->index(['integration_id', 'opened_at']);
        });
    }
};
```

Then run `php artisan migrate` before any web server or queue worker runs the 5.3 code. In 5.3, the package writes `failure_class` with every request it logs, and `attempt` and `max_attempts` with every operation it logs. Incident recording is on by default (`observability.incidents_enabled`), so the package also writes to `integration_incidents` when an integration's health or circuit breaker state changes. Each of these writes fails until the migration adds its column or table. Requests logged before the upgrade have a null `failure_class`, which [`failureSummary()`](/core-concepts/health-monitoring#failure-summary) counts as `unknown`.

## 4.x to 5.0

5.0 makes the circuit breaker an availability detector driven by a single failure classifier, switches its default strategy, and adds runtime overrides. Most apps need no code changes; the work is in custom providers and any direct callers of `Integration::recordFailure()`.

### Why

The breaker and health tracking each decided independently what counted as a "failure", and both counted too much. An HTTP 429 (the upstream throttling you) and a 4xx (a malformed request from one caller) would trip the breaker and degrade health, even though the dependency was healthy and answering. One bad caller could pull an integration offline for everyone sharing it. SDK exceptions the breaker couldn't read were counted as outages by accident.

5.0 routes every failure through one [`FailureClassifier`](/advanced/circuit-breaker#what-counts-as-a-failure) whose verdict feeds both subsystems. Only genuine upstream faults (5xx except 501, connection errors, timeouts) count; throttles and client errors don't.

### 1. Add the new columns

5.0 adds four columns to the integrations table for [runtime overrides](/advanced/circuit-breaker#runtime-overrides) (`circuit_override`, `circuit_override_until`, `rate_limit_override`, `rate_limit_override_until`).

A fresh install gets them from the published baseline migration. An existing deployment has already run that migration, so add a downstream migration that appends the columns (the canonical migration is the only one bumped, matching how earlier schema changes shipped):

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Integrations\Support\Config;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Config::tablePrefix().'s', function (Blueprint $table): void {
            $table->string('circuit_override')->nullable();
            $table->timestamp('circuit_override_until')->nullable();
            $table->json('rate_limit_override')->nullable();
            $table->timestamp('rate_limit_override_until')->nullable();
        });
    }
};
```

Then run `php artisan migrate`.

### 2. If you call `Integration::recordFailure()` directly

It now takes a `FailureClass` argument. The framework calls it for you inside the request pipeline, so this only matters for direct callers (custom health-check flows, tests).

**Before (4.x):**

```php
$integration->recordFailure();
```

**After (5.0):**

```php
use Integrations\Enums\FailureClass;

$integration->recordFailure(FailureClass::Upstream);
```

Pass the class that matches the failure: `Upstream` for a genuine outage, `Throttle` for a rate limit, `Client` for a 4xx, `Unknown` when there's no signal. Only `Upstream` degrades health.

### 3. Choose your breaker strategy

The default strategy changed from consecutive-count to **rate** (failure percentage over a window). If you relied on "opens after N consecutive failures", set it back explicitly:

```php
// config/integrations.php
'circuit_breaker' => [
    'strategy' => 'count', // 4.x behaviour
    'threshold' => 5,
],
```

The rate strategy can only trip once `minimum_requests` (default 10) have landed in the window, so a low-volume integration may never reach the floor. Use `'count'` for volume-independent tripping. See [Strategies](/advanced/circuit-breaker#strategies).

### 4. Custom providers: classify SDK failures (optional)

Core reads Laravel/Guzzle/Symfony exceptions and duck-types the common SDK status accessors, so most providers need nothing. If your SDK signals failures in a way core can't see (a throttle that arrives as a generic 403, a connection error with no HTTP status), implement [`ClassifiesFailures`](/reference/contracts#classifiesfailures):

```php
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Enums\FailureClass;

public function classifyFailure(\Throwable $e): ?FailureClass
{
    if ($e instanceof AcmeRateLimitException) {
        return FailureClass::Throttle;
    }

    return null; // defer to the default classifier
}
```

The verdict drives retries too (`Upstream`/`Throttle` are retried), so one method covers breaker, health, and retry behaviour.

### Behaviour change to note

A 429 or 4xx no longer degrades health or trips the breaker. If you depended on repeated 4xx eventually auto-disabling an integration (via `health.disabled_after`), that no longer happens: only upstream faults count toward the disable threshold. This is the intended fix, but worth knowing if your monitoring assumed the old behaviour.

## 3.x to 4.0

4.0 makes rate limits window-aware and stops a throttled sync from failing. Two breaking changes, both small.

### Why

`HasScheduledSync::defaultRateLimit()` returned a bare `?int` the framework read as requests per minute. A per-minute integer can't express an hourly budget (5,000/hour is not 83/minute, because the hourly budget can be spent in a burst), so the unit was ambiguous and easy to get wrong. And when the limiter gave up waiting it threw an exception that failed the `ProcessSyncItem` job; since 3.0 a failed item holds the cursor, so a brief throttle could wedge the whole sync.

4.0 replaces the integer with a `RateLimit` value object that names the window, and defers (re-queues) rate-limited sync items instead of failing them.

### 1. Update `defaultRateLimit()`

If you have a custom provider implementing `HasScheduledSync` (or `HasIncrementalSync`), change `defaultRateLimit()` to return `?Integrations\RateLimit`.

**Before (3.x):**

```php
public function defaultRateLimit(): ?int
{
    return 83; // meant to approximate ~5000/hour
}
```

**After (4.0):**

```php
use Integrations\RateLimit;

public function defaultRateLimit(): ?RateLimit
{
    return RateLimit::perHour(5000);
}
```

Pick the constructor that matches the upstream's real limit: `perMinute()`, `perHour()`, `perDay()`, or `per($limit, $seconds)`. Append `->sliding()` if the API enforces a rolling window rather than a budget that resets on a fixed boundary. Return `null` for no limit, as before.

### 2. If you construct `RateLimitExceededException`

Its constructor changed from `($integration, $requestsThisMinute, $limit)` to `($integration, $retryAfterSeconds, ?RateLimit $rateLimit = null)`. The framework throws it for you, so this only matters if you construct it directly (in tests, say).

### Nothing else changes

The rate-limit deferral inside syncs needs no action; `ProcessSyncItem` handles it. The new `sync.item_retry_window` config has a sensible 6-hour default. Official adapters (`pocketarc/laravel-integrations-adapters`) ship the matching change in their next major; bump both together.

## 2.x to 3.0

3.0 reworks how scheduled syncs advance the cursor. The provider contract changes, per-item events get a base class, and there's a new table. This guide covers every change you need to make.

### Why

In 2.x a sync dispatched an event per item and advanced the cursor as soon as the events were dispatched. If a consumer's listener was queued (`implements ShouldQueue`) and later exhausted its retries, the item sat in `failed_jobs` while the cursor had already moved past it. Once the item fell outside the overlap window, nothing re-fetched it: silent data loss.

In 3.0 the framework wraps each item in a queued `ProcessSyncItem` job, runs the listeners inside it, and only advances the cursor once every item's job has succeeded. A failed item stops the cursor at it until it's resolved.

### 1. Run the new migrations

3.0 adds the `integration_sync_items` table. It also dispatches a `Bus::batch`, which needs Laravel's `job_batches` table, and records exhausted item jobs in `failed_jobs`.

```bash
# If you don't already have them:
php artisan queue:batches-table
php artisan queue:failed-table

# Then publish + run this package's new migration:
php artisan vendor:publish --tag=integrations-migrations
php artisan migrate
```

### 2. Update your provider

`sync()` and `syncIncremental()` change signature: they take a `SyncSession` and return `void`. Instead of dispatching events and returning a `SyncResult`, the provider hands each item to `$session->dispatch()`.

**Before (2.x):**

```php
use Integrations\Sync\SyncResult;

public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
{
    $since = $cursor ?? now()->subDay()->toIso8601String();
    $success = 0;
    $safeCursor = $since;

    foreach ($this->fetchItems($since) as $item) {
        try {
            ItemSynced::dispatch($integration, $item);
            $success++;
            $safeCursor = max($safeCursor, $item->updated_at);
        } catch (\Throwable $e) {
            ItemSyncFailed::dispatch($integration, $item, $e);
        }
    }

    return new SyncResult($success, 0, now(), $safeCursor);
}
```

**After (3.0):**

```php
use Integrations\Concerns\ReducesCheckpointsByMax;
use Integrations\Sync\SyncSession;

class MyProvider implements IntegrationProvider, HasIncrementalSync
{
    use ReducesCheckpointsByMax;

    public function syncIncremental(Integration $integration, SyncSession $session): void
    {
        $since = $session->cursor() ?? now()->subDay()->toIso8601String();

        foreach ($this->fetchItems($since) as $item) {
            $session->dispatch(
                new ItemSynced($integration, $item),
                checkpointValue: $item->updated_at,
                externalId: (string) $item->id,
            );
        }
    }
}
```

What changed:

- No try/catch. A listener failure is now the `ProcessSyncItem` job's failure; the framework retries it and records it. Don't catch it in the provider.
- No counting and no `SyncResult`. The framework derives success/failure counts from the `integration_sync_items` rows.
- No cursor handling. Pass each item's `checkpointValue`; the framework reduces the run's checkpoints into the next `sync_cursor` via `reduceCheckpoints()`.
- `reduceCheckpoints()` is required. `use ReducesCheckpointsByMax` for the common case (max of ISO-8601 timestamps / lexicographic ids), or implement it directly for non-comparable cursors.
- `sync()` changes the same way: `sync(Integration $integration, SyncSession $session): void`.

### 3. Update per-item events

Events handed to `$session->dispatch()` must extend `Integrations\Sync\SyncItemEvent`.

**Before:**

```php
use Illuminate\Foundation\Events\Dispatchable;

class ItemSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly ItemData $item,
    ) {}
}
```

**After:**

```php
use Integrations\Sync\SyncItemEvent;

class ItemSynced extends SyncItemEvent
{
    public function __construct(
        public readonly Integration $integration,
        public readonly ItemData $item,
    ) {}
}
```

`SyncItemEvent` already pulls in `Dispatchable` and `SerializesModels`, so drop those traits from your event.

### 4. Make sync-item listeners synchronous

This is the consumer-facing change. A listener for a `SyncItemEvent` **must not implement `ShouldQueue`**. The `ProcessSyncItem` job is already the queued unit; a queued listener would let it report success before the listener ran. `ProcessSyncItem` rejects a queued listener with `SyncListenerMustNotBeQueuedException`.

**Before:**

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class IngestItem implements ShouldQueue
{
    public function handle(ItemSynced $event): void
    {
        // heavy work...
    }
}
```

**After:**

```php
class IngestItem
{
    public function handle(ItemSynced $event): void
    {
        // The sync-critical work runs here, synchronously.
        $local = MyModel::updateOrCreate(/* ... */);

        // Genuinely async follow-up work? Dispatch your own job.
        GenerateSummary::dispatch($local);
    }
}
```

Each attempt re-runs the listener, so it must be idempotent: use `upsertByExternalId()` / `updateOrCreate()`.

### 5. Replace removed events

The per-adapter aggregate and failure events are gone. Switch to the canonical core events:

| 2.x | 3.0 |
|-----|-----|
| `{Adapter}SyncCompleted` | `Integrations\Events\SyncCompleted` |
| `{Adapter}{ItemType}SyncFailed` | `Integrations\Events\SyncItemFailed` |

`SyncCompleted` carries the `Integration` and a `SyncResult`. `SyncItemFailed` carries the `Integration`, the `IntegrationSyncItem` row, and the `Throwable`.

### 6. `SyncResult` is now internal

`SyncResult` is `@internal` in 3.0: the framework builds it and hands it to the `SyncCompleted` event. If you constructed `SyncResult` directly (in tests, custom flows), read it off the `SyncCompleted` event instead.

### Recovery

Once on 3.0, a failed item stops the cursor until you resolve it:

```bash
php artisan integrations:list-failed-items     # see what needs attention
php artisan queue:retry <uuid>                 # fix the cause, retry the item
php artisan integrations:skip-sync-item <id>   # or skip an unrecoverable one
```

See [Scheduled syncs](/features/scheduled-syncs) for the full picture.
