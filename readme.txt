=== Mercaria for WooCommerce ===
Contributors: oxyhq
Tags: woocommerce, marketplace, mercaria, sync, catalog
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push your WooCommerce catalog and stock into your Mercaria marketplace store. Products, variations and inventory sync automatically.

== Description ==

Mercaria for WooCommerce connects your WooCommerce store to your store on the
Mercaria marketplace and pushes your catalog to Mercaria (WooCommerce &rarr;
Mercaria, outbound).

* Push simple and variable products, including variations, images, SKUs and prices.
* Automatic sync as you create or edit products and as stock levels change.
* A daily reconciliation job re-pushes your catalog so Mercaria stays in step.
* A one-click "Sync all products now" backfill for the initial import.
* Idempotent upserts keyed by the WooCommerce product id — re-pushing never
  creates duplicates in Mercaria.
* Prices are sent in your shop currency (WooCommerce &rarr; General &rarr;
  Currency) as integer minor units.

This is the official outbound WooCommerce connector for Mercaria. You install
this plugin and it pushes your catalog to Mercaria's channel ingestion API,
authenticated with a long-lived Channel API Key you generate in the Mercaria
dashboard (no expiring login tokens to keep re-pasting).

== Installation ==

1. Upload the plugin to `wp-content/plugins/mercaria-woocommerce` or install it
   from the Plugins screen.
2. Activate it. WooCommerce must be installed and active.
3. Go to **Settings &rarr; Mercaria**.
4. In the Mercaria dashboard, open your WooCommerce channel's settings, copy its
   **Connection id** and generate a **Channel API Key**.
5. Back in WordPress, enter the Mercaria **API base URL**
   (e.g. `https://api.mercaria.co`), the **Connection id**, and the **Channel API
   Key**, then click **Save settings**.
6. Click **Test connection**. Once it succeeds, click **Sync all products now**
   to push your existing catalog.

== Frequently Asked Questions ==

= Where do I get the connection id and Channel API Key? =

In the Mercaria dashboard, open your store's WooCommerce channel settings. It
shows the channel's **Connection id** and an **API keys** area where you generate
a **Channel API Key** (`mck_…`). The key is shown once — copy it immediately and
paste it, with the connection id, into this plugin's settings.

= What is a Channel API Key? =

It is a long-lived, store-scoped credential that only authorizes catalog
ingestion. Unlike a login/access token it does **not** expire, so the plugin
keeps working without you re-pasting anything. If a key is ever exposed, revoke
it in the Mercaria dashboard and generate a new one — revoking takes effect
immediately.

= Which currency are prices sent in? =

Prices are sent in your WooCommerce shop currency as integer minor units
(for example EUR 12.50 is sent as 1250). Mercaria stores the native currency
and converts for display.

= Does re-syncing create duplicates? =

No. Every product is sent with its WooCommerce product id as `externalId`, and
Mercaria upserts by that id, so re-pushing updates the existing listing.

= What is synced? =

Products (simple, variable and external types), their variations, images,
SKUs, barcodes/GTIN, prices, and stock levels. Shipping, orders and refunds are
not pushed by this version.

== Changelog ==

= 1.0.0 =
* Initial release: product and inventory push to the Mercaria channel
  ingestion API, automatic hooks, chunked backfill, and daily reconciliation.
* Authentication uses a long-lived, store-scoped Channel API Key (`mck_…`)
  generated in the Mercaria dashboard and posted to the token-free
  `/channels/ingest/{connectionId}/…` endpoints — no expiring access tokens.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
