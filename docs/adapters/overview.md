# Adapters

Adapters wrap third-party SDKs and implement the core package's contracts. Install one and you get a working integration with logging, retries, rate limiting, and health tracking out of the box.

## Official adapters

The [`pocketarc/laravel-integrations-adapters`](https://github.com/solmyrhq/laravel-integrations-adapters) package has officially maintained adapters:

| Adapter | SDK | Focus |
|---------|-----|-------|
| [GitHub](/adapters/github) | [knplabs/github-api](https://github.com/KnpLabs/php-github-api) | Issues |
| [Zendesk](/adapters/zendesk) | [zendesk/zendesk_api_client_php](https://github.com/zendesk/zendesk_api_client_php) | Tickets, users, comments |
| [Stripe](/adapters/stripe) | [stripe/stripe-php](https://github.com/stripe/stripe-php) | Payment intents (saving cards and off-session charges), setup intents, payment methods, refunds, charges, customers, disputes, events, webhooks |
| [Postmark](/adapters/postmark) | [wildbit/postmark-php](https://github.com/ActiveCampaign/postmark-php) | Mail credentials bridge, typed webhooks (delivery/bounce/open/click/spam/subscription/inbound), bounces, suppressions, messages, server stats, webhook endpoints |

These adapters aren't fully API-complete -- they cover what's needed for the projects that use them. You can extend them or build your own.

### Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

Register the adapters you need in `config/integrations.php`:

```php
'providers' => [
    'zendesk'  => \Integrations\Adapters\Zendesk\ZendeskProvider::class,
    'github'   => \Integrations\Adapters\GitHub\GitHubProvider::class,
    'stripe'   => \Integrations\Adapters\Stripe\StripeProvider::class,
    'postmark' => \Integrations\Adapters\Postmark\PostmarkProvider::class,
],
```

## Community adapters

If you've built an adapter for a service, open an issue or PR on the [laravel-integrations](https://github.com/solmyrhq/laravel-integrations) repository and it can be listed here.

## Building your own

Whether you want to contribute to the official adapters package or release your own, see [Building Adapters](/adapters/building-adapters) for the conventions and patterns to follow.
