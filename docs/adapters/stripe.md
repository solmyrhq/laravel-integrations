# Stripe adapter

Wraps the [stripe/stripe-php](https://github.com/stripe/stripe-php) SDK. Covers payment intents (including saving cards and off-session charges), setup intents, payment methods, refunds, charges, customers, disputes, events, and webhook endpoints. Methods return Stripe's native typed objects (`\Stripe\Refund`, `\Stripe\PaymentIntent`, etc.), or `\Stripe\Collection<T>` for list endpoints.

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
| `$client->paymentIntents()`     | `->create($amount, $currency, ..., $idempotencyKey?, ...)`     | Create a PaymentIntent. Supports saving a card or confirming a saved method off-session; see [Saved cards and off-session payments](#saved-cards-and-off-session-payments). Returns `\Stripe\PaymentIntent`. |
|                                 | `->retrieve($id)`                                              | Get a PaymentIntent by id.                                                                               |
|                                 | `->update($id, ...)`                                           | Update editable fields.                                                                                  |
|                                 | `->confirm($id, $paymentMethod?, $idempotencyKey?, $offSession?, $returnUrl?, $setupFutureUsage?)` | Confirm a PaymentIntent, optionally off-session or with future usage. Returns `\Stripe\PaymentIntent`. |
|                                 | `->capture($id, $amountToCapture?, $idempotencyKey?)`          | Capture an authorised PaymentIntent.                                                                     |
|                                 | `->cancel($id, $cancellationReason?, $idempotencyKey?)`        | Cancel a PaymentIntent.                                                                                  |
|                                 | `->list($customer?, $limit?)`                                  | Returns `\Stripe\Collection<\Stripe\PaymentIntent>`.                                                     |
| `$client->setupIntents()`       | `->create($customer, $usage?, $automaticPaymentMethods?, $allowedPaymentMethodTypes?, $description?, $metadata?, $idempotencyKey?)` | Create a SetupIntent to save a payment method without charging it. Returns `\Stripe\SetupIntent`. |
|                                 | `->retrieve($id)`                                              | Get a SetupIntent by id. Returns `\Stripe\SetupIntent`. |
|                                 | `->cancel($id, $cancellationReason?, $idempotencyKey?)`          | Cancel a SetupIntent. Returns `\Stripe\SetupIntent`. |
| `$client->paymentMethods()`     | `->retrieve($id)`                                              | Get a saved payment method. Returns `\Stripe\PaymentMethod`. |
|                                 | `->detach($id, $idempotencyKey?)`                               | Detach a method from its customer. It cannot be used for payment or attached again. Returns `\Stripe\PaymentMethod`. |
|                                 | `->listForCustomer($customer, $type?, $limit?)`                  | List a customer's methods, optionally filtered by type (e.g. `'card'`). Returns `\Stripe\Collection<\Stripe\PaymentMethod>`. |
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

## Saved cards and off-session payments

Saved-card and off-session support, including the `setupIntents()` and `paymentMethods()` resources, requires `pocketarc/laravel-integrations-adapters` 7.1.0 or later.

`paymentIntents()->create()` accepts these parameters after `$idempotencyKey`, in the order shown. Existing positional arguments keep their positions.

| Parameter | Default | Description |
|-----------|---------|-------------|
| `paymentMethod` | `null` | PaymentMethod id to use for the payment. Sent as `payment_method`. |
| `allowedPaymentMethodTypes` | `null` | Explicit list of method types, such as `['card']`. Sent as `allowed_payment_method_types`, the stripe-php 22 replacement for `payment_method_types`. Requires `automaticPaymentMethods: false`. |
| `setupFutureUsage` | `null` | `StripeSetupFutureUsage` enum value. Sent as `setup_future_usage` to save the method for future payments. |
| `confirm` | `false` | Send `confirm: true` to confirm the intent during creation. Requires `paymentMethod`. |
| `offSession` | `false` | Send `off_session: true` when the customer is not present. Requires `confirm: true`. |
| `returnUrl` | `null` | Send `return_url` for the customer's return after redirect authentication. Requires `confirm: true`. |

`paymentIntents()->confirm()` accepts `offSession`, `returnUrl` and `setupFutureUsage` after `$idempotencyKey`, with the same defaults. Its optional `paymentMethod` can be omitted to use the method already on the intent.

`Integrations\Adapters\Stripe\Enums\StripeSetupFutureUsage` has two cases: `OffSession` (`'off_session'`) for future payments when the customer may be absent, and `OnSession` (`'on_session'`) for future payments only when the customer is present. The same enum supplies a SetupIntent's `usage`.

### Saving without a charge

`setupIntents()->create()` requires a customer id. It defaults to `usage: StripeSetupFutureUsage::OffSession` and `automaticPaymentMethods: true`. It also accepts `allowedPaymentMethodTypes`, `description`, `metadata` and `idempotencyKey`.

```php
$setupIntent = $client->setupIntents()->create(
    customer: 'cus_123',
    automaticPaymentMethods: false,
    allowedPaymentMethodTypes: ['card'],
    idempotencyKey: 'save-card:123',
);
```

Confirm the SetupIntent in the browser with Stripe.js and `$setupIntent->client_secret`. Successful setup attaches the payment method to the customer without charging it. The adapter exposes `retrieve()` and `cancel()` for SetupIntents, but no `confirm()` method.

### Saving during a payment

`setupFutureUsage` on a PaymentIntent saves the method while taking a payment. With a customer supplied, Stripe attaches the method to that customer after confirmation and any required customer actions.

```php
use Integrations\Adapters\Stripe\Enums\StripeSetupFutureUsage;

$paymentIntent = $client->paymentIntents()->create(
    amount: 1900,
    currency: 'usd',
    customer: 'cus_123',
    automaticPaymentMethods: false,
    allowedPaymentMethodTypes: ['card'],
    setupFutureUsage: StripeSetupFutureUsage::OffSession,
);
```

Confirm the payment in the browser with Stripe.js and `$paymentIntent->client_secret`. `setupFutureUsage` can also be supplied to `paymentIntents()->confirm()` when confirming an existing intent.

### Charging a saved card

`paymentIntents()->create()` can charge a saved method with `customer`, `paymentMethod`, `confirm: true` and `offSession: true`:

```php
$paymentIntent = $client->paymentIntents()->create(
    amount: 1900,
    currency: 'usd',
    customer: 'cus_123',
    automaticPaymentMethods: false,
    idempotencyKey: 'charge:123:attempt:1',
    paymentMethod: 'pm_123',
    allowedPaymentMethodTypes: ['card'],
    confirm: true,
    offSession: true,
);
```

For an existing intent, `paymentIntents()->confirm($id, paymentMethod: 'pm_123', offSession: true)` confirms the saved method off-session.

### Card declines

If the bank declines an off-session charge through `create()` or `confirm()`, the SDK throws `\Stripe\Exception\CardException`. The exception's `getError()->payment_intent` holds the PaymentIntent, and `getDeclineCode()` gives the decline code. A decline code of `authentication_required` means the customer must return and confirm the payment themselves with Stripe.js using that intent's `client_secret`.

Stripe returns card declines with HTTP 402. `StripeProvider::classifyFailure()` maps that status to `Integrations\Enums\FailureClass::Client`. The failure is [not retried](/core-concepts/retries#backoff-strategy) and does not count as a failure for the [circuit breaker or integration health](/advanced/circuit-breaker#what-counts-as-a-failure).

## Input validation

Resource methods reject empty ids, non-positive amounts and limits, and blank idempotency keys up front with `InvalidArgumentException` rather than letting them hit Stripe. Pass `null` to omit an optional parameter; pass a non-empty value to include.

Saved-card parameters also throw `InvalidArgumentException` before any request is sent in these cases:

- `paymentIntents()->create()` receives `offSession: true` or a non-null `returnUrl` without `confirm: true`.
- `paymentIntents()->create()` receives `confirm: true` without a `paymentMethod`, or an empty payment method id. `paymentIntents()->confirm()` allows the method to be omitted, but rejects an empty id when supplied.
- Either `paymentIntents()->create()` or `setupIntents()->create()` receives a non-null `allowedPaymentMethodTypes` with `automaticPaymentMethods: true` (the default), or an empty list of allowed types.

When `allowedPaymentMethodTypes` is supplied with `automaticPaymentMethods: false`, the adapter sends only `allowed_payment_method_types`, omitting `automatic_payment_methods`. When the list is `null`, it sends `automatic_payment_methods: ['enabled' => $automaticPaymentMethods]`.

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
