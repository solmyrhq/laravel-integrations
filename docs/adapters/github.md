# GitHub adapter

Wraps the [knplabs/github-api](https://github.com/KnpLabs/php-github-api) SDK. Currently focused on issues (not PRs, releases, etc.).

Part of the [`pocketarc/laravel-integrations-adapters`](https://github.com/solmyrhq/laravel-integrations-adapters) package.

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

## Setup

```php
// config/integrations.php
'providers' => [
    'github' => \Integrations\Adapters\GitHub\GitHubProvider::class,
],
```

```php
$integration = Integration::create([
    'provider' => 'github',
    'name' => 'My Repo',
    'credentials' => ['token' => 'ghp_...'],
    'metadata' => ['owner' => 'acme', 'repo' => 'widgets'],
]);
```

| Credentials | Metadata |
|-------------|----------|
| `token` (string) - GitHub personal access token | `owner` (string) - repository owner |
| | `repo` (string) - repository name |

## Resources

```php
$client = new GitHubClient($integration);
```

| Resource | Method | Description |
|----------|--------|-------------|
| `$client->issues()` | `->create($title, $body, $labels, $idempotencyKey?)` | Create an issue. Returns `GitHubIssueData`. |
| | `->get($number)` | Get a single issue by number. |
| | `->since($since, $callback)` | Iterate issues updated since a timestamp. Skips PRs. |
| | `->close($number, $stateReason, $idempotencyKey?)` | Close an issue. Optional state reason (completed, not_planned, duplicate). |
| | `->reopen($number, $idempotencyKey?)` | Reopen a closed issue. |
| | `->update($number, $title?, $body?, $idempotencyKey?)` | Edit an issue's title and/or body. At least one is required. Returns `?GitHubIssueData`. |
| | `->timeline($number, $callback)` | Iterate timeline events (labels, assignments, etc.). |
| `$client->comments()` | `->list($number, $callback)` | Iterate all comments on an issue. |
| | `->since($since, $callback)` | Iterate the repository's issue and pull request comments updated since a timestamp, oldest updated first. Callback receives each comment's raw array. Unlike `issues()->since()`, does not skip PRs. Requires adapters 6.1.0 or later. |
| | `->add($number, $body, $idempotencyKey?)` | Add a comment to an issue. Returns `?GitHubCommentData`. |
| `$client->assets()` | `->download($url)` | Download an asset with token auth for GitHub-hosted URLs. |

All methods go through `Integration::request()` / `requestAs()` internally, so every API call is logged, health-tracked, and rate-limited. The provider implements `CustomizesRetry` so the core handles retry for GitHub SDK exceptions (rate limits, server errors, connection failures) with method-aware defaults (GET = 3 attempts, non-GET = 1).

## Authenticated identity

`GitHubProvider` implements [`IdentifiesAuthenticatedUser`](/reference/contracts#identifiesauthenticateduser). [`$integration->authenticatedUser()`](/features/authenticated-identity) calls `GET /user` and maps the response onto the provider-agnostic `AuthenticatedUser`:

```php
$me = $integration->authenticatedUser(cacheFor: now()->addDay());
$me->id;        // GitHub user id, e.g. "583231"
$me->username;  // login, e.g. "octocat"
```

Use it to skip the activity your own integration authored instead of reacting to it.

## Sync

The adapter syncs issues via `$client->issues()->since()`. For each issue it calls `$session->dispatch()` with a `GitHubIssueSynced` event. The framework wraps each one in a queued job, runs your listeners, and advances `sync_cursor` once every issue's job has succeeded. Listeners for `GitHubIssueSynced` must not implement `ShouldQueue` (see [Scheduled syncs](/features/scheduled-syncs) for the per-item model).

First sync (null cursor) fetches all issues from timestamp 0. Set `sync_cursor` on the integration to control the starting point:

```php
$integration->updateSyncCursor('2024-05-01T00:00:00+00:00');
```

Every incremental sync subtracts a 1-hour buffer from the cursor to catch issues updated between runs. The framework's cursor advance is monotonic, so re-presenting items inside that window can't regress progress. Consumers should still use [`upsertByExternalId()`](/features/id-mapping#upsert-by-external-id) in their listeners since overlap is expected.

With `pocketarc/laravel-integrations-adapters` 6.1.0 or later, incremental syncs with a cursor also read the repository's comments feed via `$client->comments()->since()`, after listing issues. Both listings use the same `since` window (cursor minus one hour). The first sync without a cursor does not read the comments feed.

For each parent issue missing from the issues list, the provider calls `$client->issues()->get()` once, even if several comments refer to it. It dispatches `GitHubIssueSynced` through the same session, with the issue's `updated_at` as the checkpoint and its issue number as the external ID. It skips issues already listed, comments whose `html_url` contains `/pull/`, and comments without a valid issue number in `issue_url`. A fetched issue is skipped if `get()` returns `null` or the response has a `pull_request` key.

Each recovered issue logs a warning that it was missing from the issues list and is being synced from the comments feed. The warning context contains `integration_id`, `issue_number` and `since` (the start of the buffered window).

This recovers issues when GitHub's issues list takes more than an hour to include a recently updated issue. By then, the issue's `updated_at` can fall before the sync window, so later runs miss it and its new comments. Recovery relies on the comments feed not having the same delay.

The comments feed adds one request per incremental run with a cursor, plus pagination in batches of 100 comments. Each full page triggers another request, including an empty final page when the count is an exact multiple of 100. Each distinct missing issue adds one fetch, even if the fetched response is then skipped.

Defaults: 5-minute sync interval, a 5,000-requests/hour rate limit (GitHub's authenticated budget), enforced as a fixed window. The adapter also feeds the [adaptive rate limiter](/core-concepts/rate-limiting#adaptive-rate-limits) from GitHub's `X-RateLimit-Remaining` / `X-RateLimit-Reset` headers, so when a token's hourly bucket runs out, subsequent requests are suppressed until the reset window passes.

## Provider request IDs

GitHub's `X-GitHub-Request-Id` is captured on `integration_requests.provider_request_id` for every call. Useful when filing issues against the SDK or [GitHub's own bug tracker](https://github.com/orgs/community/discussions). Asset downloads (raw `Http::` calls outside the SDK) capture the same header.

## Idempotency

All `GitHubIssues` write methods (`create()`, `close()`, `reopen()`, `update()`) and `GitHubComments::add()` accept an optional `$idempotencyKey`. Pass a stable, application-meaningful value (e.g. `"open-issue:order-{$order->id}"`, `"close-issue:{$issueNumber}"`) when you need at-most-once execution: the package writes a row in `integration_idempotency_keys` before the call fires, throws `Integrations\Exceptions\IdempotencyConflict` on a second call with the same key. The exception carries the prior response on `$e->priorResponse` so you can [recover the original result](/core-concepts/idempotency#recovering-on-conflict) without re-fetching. GitHub itself doesn't natively dedupe by header (`GitHubProvider` doesn't implement `SupportsIdempotency`), so the local row is the only protection here. Pass `null` (the default) to skip idempotency entirely. See [Idempotency](/core-concepts/idempotency) for the full picture.

## Data classes

| Class | Description |
|-------|-------------|
| `GitHubIssueData` | Issue with body, state, user, labels, assignees, attachments (extracted from body HTML via `prepareForPipeline()`). Stores original API response. |
| `GitHubCommentData` | Comment with body, user, attachments (extracted from body HTML via `prepareForPipeline()`). Stores original API response. |
| `GitHubEventData` | Timeline event (label, assignment, close, etc.) with formatted descriptions. Handles cross-reference ID synthesis via `prepareForPipeline()`. |
| `GitHubUserData` | User with login, avatar, name, email. |
| `GitHubAttachmentData` | Attachment URL extracted from issue/comment HTML body. |

## Enums

| Enum | Values |
|------|--------|
| `GitHubEventType` | ~50 timeline event types with human-readable descriptions. |
| `GitHubIssueStateReason` | `Completed`, `NotPlanned`, `Duplicate`, `Reopened` |
