# Mercaria for WooCommerce — plugin

> **Scope:** this is a **standalone PHP WordPress/WooCommerce plugin**, NOT part
> of the Mercaria TypeScript monorepo. The Oxy TS-ecosystem rules (bun,
> NativeWind, `@oxyhq/*`, device-first session, shared SDK) do **NOT** apply
> here. Only the *universal* engineering standards apply — production-grade code,
> zero hacks, clean structure, no secrets in code — expressed in
> WordPress/WooCommerce idioms.

## What it is

A WordPress plugin a merchant installs on their WooCommerce site. It connects to
their Mercaria marketplace store and **pushes** their catalog and stock into
Mercaria (**WooCommerce → Mercaria, outbound only**). It is the "official"
counterpart to Mercaria's built-in WooCommerce channel connector: instead of the
merchant pasting API keys into the Mercaria dashboard, this plugin performs the
push handshake and sends products/inventory to Mercaria's channel ingestion API.

See the plan (`~/.claude/plans/quiero-implementar-plugins-conectores-o-hazy-chipmunk.md`,
§Arquitectura C + Fase 5, decision #9) for the full connector vision.

## WordPress/WooCommerce standards enforced here

- Every PHP file starts with `defined( 'ABSPATH' ) || exit;` (no direct access).
  `uninstall.php` guards on `WP_UNINSTALL_PLUGIN`.
- All admin state-changing actions verify a **nonce** (`check_admin_referer`)
  **and** the `manage_woocommerce` capability (`current_user_can`).
- Inputs are sanitized (`sanitize_text_field`, `esc_url_raw`); all output is
  escaped (`esc_html`, `esc_attr`, `esc_url`).
- Full i18n: text domain `mercaria-woocommerce`, loaded on `init`, `.pot` in
  `languages/`.
- No secrets in code. The merchant's access token is entered in settings and
  stored in `wp_options` (never echoed back).
- HPOS-compatible (declares `custom_order_tables` compatibility; the plugin does
  not touch order storage).
- Class autoloading via a small `spl_autoload_register` shim
  (`Mercaria_WC_Foo` → `includes/class-mercaria-wc-foo.php`).

## File layout

```
mercaria-woocommerce.php                     Main plugin file: header, constants,
                                             autoloader, HPOS declaration,
                                             activation/deactivation, bootstrap.
uninstall.php                                Removes options + cron on delete.
readme.txt                                   wordpress.org readme.
includes/
  class-mercaria-wc-plugin.php               Singleton controller; option keys,
                                             cron hook names, get_client(),
                                             is_connected(), activate/deactivate.
  class-mercaria-wc-settings.php             Settings → Mercaria admin page;
                                             Connect/Test/Disconnect/Sync/Clear
                                             actions (nonce + cap guarded).
  class-mercaria-wc-client.php               HTTP client (wp_remote_request,
                                             Bearer auth, retry/backoff).
  class-mercaria-wc-product-mapper.php       WC_Product → IngestProduct + minor
                                             units + inventory items.
  class-mercaria-wc-sync.php                 Hooks → debounced queue → batched
                                             pushes; chunked backfill; reconcile.
  class-mercaria-wc-logger.php               Capped activity log in an option.
languages/mercaria-woocommerce.pot           i18n template.
```

## Mercaria channel ingestion API contract (build to this exactly)

The Mercaria backend (built in parallel, Fase 5) exposes:

- Base URL: merchant-configured (e.g. `https://api.mercaria.co`).
- Auth: `Authorization: Bearer <token>` — a store-scoped Oxy access token
  (`channels:write`). v1: merchant pastes it in settings.
- **Connect:** `POST /admin/stores/{storeId}/channels/woocommerce/connect-push`
  body `{ shopDomain }` → `{ connectionId, storeId }`. Treated as
  idempotent/upsert per store+provider+shopDomain (Test re-runs it).
- **Push products:**
  `POST /admin/stores/{storeId}/channels/{connectionId}/ingest/products`
  body `{ products: IngestProduct[] }` (batched at 100).
- **Push stock:**
  `POST /admin/stores/{storeId}/channels/{connectionId}/ingest/inventory`
  body `{ items: { externalId, sku?, available:int }[] }`.

### `IngestProduct` shape produced by the mapper

```
{
  externalId: string,              // the WooCommerce product id (idempotency key)
  externalUpdatedAt?: string,      // ISO 8601 (WC_DateTime->format('c'))
  title: string,
  description?: string,            // raw WooCommerce HTML description
  images?: string[],              // absolute featured + gallery URLs (CDN passthrough)
  vendor?: string,                // "brand" product attribute, if present
  productType?: string,           // first product category name
  handle?: string,                // product slug
  seo?: { title?, description? }, // Yoast / Rank Math meta, if present
  options?: [{ name: string, values: string[] }],   // variable products only
  variants: [{
    optionValues?: [{ name: string, value: string }], // variations only
    price: { amount: int, currency: ISO },            // INTEGER MINOR UNITS
    compareAtPrice?: { amount: int, currency: ISO },  // regular price when on sale
    sku?: string,
    barcode?: string,             // get_global_unique_id() (GTIN, WC 9.2+)
    inventory?: { available: int } // only when stock is managed
  }]
}
```

**Money is always integer minor units in the shop currency**
(`get_woocommerce_currency()`). Exponent is derived from ISO 4217:
0 for zero-decimal currencies (JPY, KRW, …), 3 for three-decimal (KWD, BHD, …),
otherwise 2. Example: shop in EUR, price `12.50` → `{ amount: 1250, currency: "EUR" }`.

## Sync behavior

- Hooks: `woocommerce_new_product`, `woocommerce_update_product`,
  `woocommerce_save_product_variation` (enqueue parent),
  `woocommerce_product_set_stock` + `woocommerce_variation_set_stock`
  (enqueue inventory).
- Debounce/batch: changed product ids accumulate in two option-backed queues
  (products, inventory); a single debounced `wp_schedule_single_event`
  (`mercaria_wc_process_queue`) flushes them in batches of 100. One request per
  burst, not one per save.
- Backfill: `mercaria_wc_backfill_chunk` walks published products a page at a
  time (chunk-driven to avoid PHP timeouts on large catalogs); progress is
  stored in an option and shown on the settings page. Triggered by "Sync all
  products now".
- Reconciliation: daily `mercaria_wc_reconcile` cron re-runs the full backfill
  (idempotent upsert) — this is also the retry safety net when a live push
  fails.
- Idempotency: `externalId` is always the WooCommerce product id, so Mercaria
  upserts (never duplicates).

## Deferred / handoff (needs the Mercaria backend + Oxy IdP)

- **OAuth "Connect with Mercaria":** v1 uses a **pasted store-scoped token** as
  the auth seam. The planned follow-up is an OAuth authorization-code flow
  against the Oxy IdP (auth.oxy.so) with a registered "Mercaria WooCommerce"
  client, where the merchant signs in, picks their Mercaria store, and grants
  `channels:write` — the plugin then receives the store-scoped token with no
  manual paste. When that lands, replace the token field + Connect button with
  the OAuth redirect/callback; the ingestion calls are unchanged.
- **Backend ingestion endpoints** (`connect-push`, `ingest/products`,
  `ingest/inventory`) are implemented by a parallel agent in the Mercaria
  monorepo (`packages/backend`), idempotent by `{provider, externalId}` and
  respecting `overriddenFields` on re-sync.
- **Not yet pushed:** orders/refunds, shipping (Moovo owns shipping), product
  deletes (no delete endpoint in the contract yet — archival on delete is a
  follow-up).

## Verifying changes

- Lint every PHP file: `php -l <file>` — must report no syntax errors.
- Confirm the mapper still emits the exact `IngestProduct` shape above, with
  `price.amount` as **integer minor units** in the shop currency.
- WordPress-side behavior (hooks firing, cron, admin nonces) must be verified in
  a real WordPress + WooCommerce install; `php -l` only checks syntax.
