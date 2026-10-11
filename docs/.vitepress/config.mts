import { defineConfig } from "vitepress";
import llmstxt from "vitepress-plugin-llms";
import { copyOrDownloadAsMarkdownButtons } from "vitepress-plugin-llms";

export default defineConfig({
  title: "Laravel Integrations",
  description:
    "API integrations without the boilerplate. Credentials, logging, retries, rate limiting, health monitoring, OAuth2, webhooks, and sync scheduling for Laravel.",

  lastUpdated: true,
  cleanUrls: true,

  srcExclude: ["**/README.md"],

  markdown: {
    config(md) {
      md.use(copyOrDownloadAsMarkdownButtons);
    },
  },

  head: [
    // ["link", { rel: "icon", href: "/favicon.ico" }],
    ["meta", { name: "author", content: "Bruno Moreira" }],

    // OpenGraph
    ["meta", { property: "og:type", content: "website" }],
    ["meta", { property: "og:site_name", content: "Laravel Integrations" }],
    [
      "meta",
      { property: "og:title", content: "Laravel Integrations" },
    ],
    [
      "meta",
      {
        property: "og:description",
        content:
          "API integrations without the boilerplate. Credentials, logging, retries, rate limiting, health monitoring, OAuth2, webhooks, and sync scheduling for Laravel.",
      },
    ],

    ["meta", { property: "og:image", content: "https://integrations.pocketarc.com/og-image.png" }],

    // Twitter/X card
    ["meta", { name: "twitter:card", content: "summary_large_image" }],
    ["meta", { name: "twitter:site", content: "@pocketarc" }],
    ["meta", { name: "twitter:creator", content: "@pocketarc" }],

    // tvsort.com proxies Plausible, because ad-blocker lists include
    // plausible.io. The proxy serves only the trackers listed in a separate
    // infrastructure configuration, so add a new tracker there too.
    ["script", { async: "", src: "https://tvsort.com/js/pa-oh7Fe-g_1NS4cVXllZuEK.js" }],
    [
      "script",
      {},
      'window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)},plausible.init=plausible.init||function(i){plausible.o=i||{}};plausible.init({endpoint:"https://tvsort.com/api/event"})',
    ],
  ],

  themeConfig: {
    nav: [
      { text: "Guide", link: "/getting-started/introduction" },
      { text: "Reference", link: "/reference/contracts" },
      {
        text: "Packagist",
        link: "https://packagist.org/packages/pocketarc/laravel-integrations",
      },
      {
        text: "Blog",
        link: "https://pocketarc.com",
      },
    ],

    sidebar: [
      {
        text: "Getting started",
        items: [
          { text: "Introduction", link: "/getting-started/introduction" },
          { text: "Installation", link: "/getting-started/installation" },
          { text: "Quick start", link: "/getting-started/quick-start" },
          {
            text: "Scaffolding providers",
            link: "/getting-started/scaffolding",
          },
        ],
      },
      {
        text: "Core concepts",
        items: [
          { text: "Providers", link: "/core-concepts/providers" },
          {
            text: "Credentials & metadata",
            link: "/core-concepts/credentials",
          },
          {
            text: "Making requests",
            link: "/core-concepts/making-requests",
          },
          {
            text: "Response caching",
            link: "/core-concepts/response-caching",
          },
          { text: "Retries", link: "/core-concepts/retries" },
          { text: "Rate limiting", link: "/core-concepts/rate-limiting" },
          { text: "Idempotency", link: "/core-concepts/idempotency" },
          {
            text: "Health monitoring",
            link: "/core-concepts/health-monitoring",
          },
          { text: "Logging", link: "/core-concepts/logging" },
        ],
      },
      {
        text: "Features",
        items: [
          { text: "OAuth2", link: "/features/oauth2" },
          { text: "Webhooks", link: "/features/webhooks" },
          { text: "Scheduled syncs", link: "/features/scheduled-syncs" },
          { text: "Authenticated identity", link: "/features/authenticated-identity" },
          { text: "ID mapping", link: "/features/id-mapping" },
          { text: "Data redaction", link: "/features/redaction" },
          { text: "Multi-tenancy", link: "/features/multi-tenancy" },
        ],
      },
      {
        text: "Testing",
        items: [{ text: "Testing", link: "/testing/testing" }],
      },
      {
        text: "Reference",
        items: [
          { text: "Contracts", link: "/reference/contracts" },
          { text: "Configuration", link: "/reference/configuration" },
          {
            text: "Artisan commands",
            link: "/reference/artisan-commands",
          },
          { text: "Events", link: "/reference/events" },
          { text: "Database schema", link: "/reference/database-schema" },
          { text: "Models", link: "/reference/models" },
        ],
      },
      {
        text: "Adapters",
        items: [
          { text: "Overview", link: "/adapters/overview" },
          { text: "GitHub", link: "/adapters/github" },
          { text: "Zendesk", link: "/adapters/zendesk" },
          { text: "Stripe", link: "/adapters/stripe" },
          { text: "Postmark", link: "/adapters/postmark" },
          {
            text: "Building adapters",
            link: "/adapters/building-adapters",
          },
        ],
      },
      {
        text: "Advanced",
        items: [
          { text: "Custom retry logic", link: "/advanced/custom-retry" },
          { text: "Circuit breaker", link: "/advanced/circuit-breaker" },
          {
            text: "Health notifications",
            link: "/advanced/notifications",
          },
          { text: "Extending", link: "/advanced/extending" },
        ],
      },
      {
        text: "About",
        items: [
          { text: "Changelog", link: "/about/changelog" },
          { text: "Upgrade guide", link: "/about/upgrade-guide" },
        ],
      },
    ],

    socialLinks: [
      {
        icon: "github",
        link: "https://github.com/pocketarc/laravel-integrations",
      },
      {
        icon: "x",
        link: "https://x.com/pocketarc",
      },
    ],

    search: {
      provider: "local",
    },

    editLink: {
      pattern:
        "https://github.com/pocketarc/laravel-integrations/edit/main/docs/:path",
      text: "Edit this page on GitHub",
    },

    footer: {
      message: "Released under the MIT License.",
      copyright:
        'Copyright 2026 <a href="https://pocketarc.com">Bruno Moreira</a>',
    },
  },

  vite: {
    plugins: [
      llmstxt({
        domain: "https://integrations.pocketarc.com",
      }),
    ],
  },
});
