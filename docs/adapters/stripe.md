# Stripe adapter

Wraps the [stripe/stripe-php](https://github.com/stripe/stripe-php) SDK. Covers payment intents, refunds, charges, customers, disputes, events, and webhook endpoints. Methods return Stripe's native typed objects (`\Stripe\Refund`, `\Stripe\PaymentIntent`, etc.), or `\Stripe\Collection<T>` for list endpoints.

Part of the [`pocketarc/laravel-integrations-adapters`](https://github.com/solmyrhq/laravel-integrations-adapters) package.

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

The adapter requires `stripe/stripe-php` `^22.0`. `StripeClient` does not set `stripe_version`, so API calls use the dated API version pinned by the installed stripe-php release. A newer SDK major can pin a newer API version.

If stripe-php is only a transitive dependency, `composer update` can install a newer major when a future adapter release allows it, changing the API version. Resource methods return Stripe's own objects, so renamed or removed fields can break application code at runtime. Require `stripe/stripe-php` in your own `composer.json` at the major you want to control API version upgrades. For example, to stay on major 22:

```bash
composer require stripe/stripe-php:^22.0
```

## Setup

```php
// config/integrations.php
'providers' => [
    'stripe' => \Integrations\Adapters\Stripe\StripeProvider::class,
],
```

```php
$integration = Integration::create([
    'provider' => 'stripe',
    'name' => 'Stripe Live',
    'credentials' => [
        'api_key' => 'sk_live_...',
        'webhook_secret' => 'whsec_...',
        'publishable_key' => 'pk_live_...', // optional
    ],
]);
```

| Credentials        | Description                                                        |
|--------------------|--------------------------------------------------------------------|
| `api_key`          | Secret key. Must start with `sk_live_` or `sk_test_`.              |
| `webhook_secret`   | Endpoint secret. Must start with `whsec_`.                         |
| `publishable_key`  | Optional. Must start with `pk_live_` or `pk_test_` when set.       |

No metadata is required; the credential prefixes gate live vs test mode.

## Resources

```php
$client = new StripeClient($integration);
```

| Resource                        | Method                                                         | Description                                                                                              |
|---------------------------------|----------------------------------------------------------------|----------------------------------------------------------------------------------------------------------|
| `$client->paymentIntents()`     | `->create($amount, $currency, ..., $idempotencyKey?)`          | Create a PaymentIntent. Returns `\Stripe\PaymentIntent`.                                                 |
|                                 | `->retrieve($id)`                                              | Get a PaymentIntent by id.                                                                               |
|                                 | `->update($id, ...)`                                           | Update editable fields.                                                                                  |
|                                 | `->confirm($id, $paymentMethod?, $idempotencyKey?)`            | Confirm a PaymentIntent.                                                                                 |
|                                 | `->capture($id, $amountToCapture?, $idempotencyKey?)`          | Capture an authorised PaymentIntent.                                                                     |
|                                 | `->cancel($id, $cancellationReason?, $idempotencyKey?)`        | Cancel a PaymentIntent.                                                                                  |
|                                 | `->list($customer?, $limit?)`                                  | Returns `\Stripe\Collection<\Stripe\PaymentIntent>`.                                                     |
| `$client->refunds()`            | `->create($paymentIntent?, $charge?, ..., $idempotencyKey?)`   | Refund against exactly one of `paymentIntent` or `charge`.                                               |
|                                 | `->retrieve($id)` / `->list($paymentIntent?, $charge?, $limit?)` | Returns `\Stripe\Refund` or `\Stripe\Collection<\Stripe\Refund>`.                                      |
| `$client->charges()`            | `->retrieve($id)`                                              | Get a Charge.                                                                                            |
|                                 | `->capture($id, $amount?, $receiptEmail?, $idempotencyKey?)`   | Capture an authorised charge.                                                                            |
|                                 | `->list($customer?, $paymentIntent?, $limit?)`                 | Returns `\Stripe\Collection<\Stripe\Charge>`.                                                            |
| `$client->customers()`          | `->create(...)` / `->update($id, ...)`                         | Returns `\Stripe\Customer`.                                                                              |
|                                 | `->retrieve($id)` / `->delete($id, $idempotencyKey?)`          | Delete returns the Customer with `$deleted = true`.                                                      |
|                                 | `->list($email?, $limit?)`                                     | Returns `\Stripe\Collection<\Stripe\Customer>`.                                                          |
| `$client->disputes()`           | `->retrieve($id)` / `->update($id, ...)` / `->close($id)`      | Returns `\Stripe\Dispute`.                                                                               |
|                                 | `->list($charge?, $paymentIntent?, $limit?)`                   | Returns `\Stripe\Collection<\Stripe\Dispute>`.                                                           |
| `$client->events()`             | `->retrieve($id)` / `->list($types?, $limit?)`                 | Returns `\Stripe\Event` or `\Stripe\Collection<\Stripe\Event>`.                                          |
| `$client->webhookEndpoints()`   | `->create($url, $enabledEvents, ...)` / `->update($id, ...)`   | Returns `\Stripe\WebhookEndpoint`.                                                                       |
|                                 | `->retrieve($id)` / `->delete($id, $idempotencyKey?)` / `->list($limit?)` | Delete returns the WebhookEndpoint with `$deleted = true`.                                    |

All methods go through `Integration::request()` internally, so every API call is logged, rate-limited, and health-tracked. The provider declares a 100-requests/second rate limit (Stripe's documented live-mode ceiling), enforced as a fixed window.

### Authenticated identity

`StripeProvider` implements [`IdentifiesAuthenticatedUser`](/reference/contracts#identifiesauthenticateduser). [`$integration->authenticatedUser()`](/features/authenticated-identity) calls `GET /v1/account` and maps the account onto the provider-agnostic `AuthenticatedUser`:

```php
$me = $integration->authenticatedUser(cacheFor: now()->addDay());
$me->id;     // Stripe account id, e.g. "acct_123"
$me->name;   // business profile name
$me->email;  // account email
```

A Stripe account has no handle, so `username` is null.

### Idempotency

Every money-moving POST accepts an optional `$idempotencyKey`. Pass a stable, application-meaningful value (e.g. `"charge:order-{$order->id}"`) when you need at-most-once execution. The package writes a row in `integration_idempotency_keys` before the SDK call fires, throws `Integrations\Exceptions\IdempotencyConflict` on a second call with the same key (carrying the prior response on `$e->priorResponse` for [recovery](/core-concepts/idempotency#recovering-on-conflict)), and forwards the key as Stripe's `Idempotency-Key` header so Stripe also dedupes upstream within its 24-hour window.

Pass `null` (the default) to skip idempotency entirely. Cross-process retries from queue replays will then re-execute. See [Idempotency](/core-concepts/idempotency) for the full semantics.

`StripeProvider` implements `SupportsIdempotency`, so callers attaching a key won't trigger the "provider doesn't support dedup" warning that other adapters surface.

### Provider request IDs

Stripe's `Request-Id` response header is captured on `integration_requests.provider_request_id` for every call. When a Stripe support ticket asks for the request ID, that's the column to grep:

```php
IntegrationRequest::where('provider_request_id', 'req_ABC123')->first();
```

## Input validation

Resource methods reject empty ids, non-positive amounts and limits, and blank idempotency keys up front with `InvalidArgumentException` rather than letting them hit Stripe. Pass `null` to omit an optional parameter; pass a non-empty value to include.

## Webhooks

`verifyWebhookSignature()` uses `\Stripe\WebhookSignature::verifyHeader()` with the stored `webhook_secret` and `\Stripe\Webhook::DEFAULT_TOLERANCE`. It checks the signature without parsing the body, so a correctly signed thin event or body that is not JSON passes verification. Missing or invalid `Stripe-Signature` headers, timestamps outside Stripe's time tolerance, and an empty webhook secret cause verification to fail.

After verification, `handleWebhook()` fires a `StripeWebhookReceived` event only when the payload has non-empty strings for both `type` and `id`. Otherwise it logs a warning and returns `['status' => 'ignored', 'event' => $type ?? 'unknown']`, where `$type` is the non-empty string type or null. This is the handler result; the webhook endpoint queues verified deliveries for processing (see [Webhooks](/features/webhooks)).

`StripeWebhookReceived` carries the integration, event type, event id, and raw payload array. Listen for the event and route by type:

```php
Event::listen(StripeWebhookReceived::class, function (StripeWebhookReceived $event) {
    match ($event->eventType) {
        'payment_intent.succeeded' => MarkOrderPaid::dispatch($event->payload),
        'charge.dispute.created' => OpenDisputeCase::dispatch($event->payload),
        default => null,
    };
});
```

A thin event (`object: v2.core.event`) has no `data.object`. When it concerns an object, the payload's `related_object` gives the `id`, `type` and `url` of the object the listener must fetch from the API. `StripeEvents::retrieve()` (`$client->events()->retrieve()`) reads v1 events only (`GET /v1/events/{id}`), so it cannot fetch a thin event.

For typed access to a snapshot event, construct the matching stripe-php object from `data.object`. For a `payment_intent.succeeded` listener:

```php
$payload = $event->payload;
$paymentIntent = \Stripe\PaymentIntent::constructFrom($payload['data']['object']);
```

## Health check

`healthCheck()` calls `$sdk->balance->retrieve()` and returns `false` on any error (API error, bad credentials, network failure).
