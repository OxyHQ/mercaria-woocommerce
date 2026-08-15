# Mercaria for WooCommerce — plugin

> **Scope:** a standalone PHP WordPress/WooCommerce plugin, NOT part of the Mercaria TypeScript monorepo. The Oxy TS-ecosystem rules (bun, NativeWind, `@oxyhq/*`, device-first session, shared SDK) do **NOT** apply. Only the universal engineering standards do, expressed in WordPress idioms. **Budget: under 8 KB.**

A merchant installs it on their WooCommerce site to **push** catalog and stock into Mercaria — **outbound only**. It authenticates with a long-lived, store-scoped **Channel API Key** (`mck_…`) generated in the Mercaria dashboard, not an Oxy access token. The merchant pastes the channel **connection id** and the **key** into settings; **there is no handshake call from the plugin** — the push-in connection is created dashboard-side.

## WordPress standards enforced here

- Every PHP file opens with `defined( 'ABSPATH' ) || exit;`; `uninstall.php` guards on `WP_UNINSTALL_PLUGIN`.
- **Every admin state-changing action verifies a nonce (`check_admin_referer`) AND the `manage_woocommerce` capability.**
- Inputs sanitized (`sanitize_text_field`, `esc_url_raw`); all output escaped (`esc_html`, `esc_attr`, `esc_url`).
- Full i18n under the `mercaria-woocommerce` text domain, loaded on `init`, with the `.pot` in `languages/`.
- **No secrets in code.** The Channel API Key lives in `wp_options`, is never echoed back, and its field is blank-keeps-existing.
- HPOS-compatible (declares `custom_order_tables`; the plugin does not touch order storage).
- Class autoloading via a small `spl_autoload_register` shim (`Mercaria_WC_Foo` → `includes/class-mercaria-wc-foo.php`).

## The ingest contract (build to this exactly)

Token-free surface reached with only the Channel API Key — **no `storeId` in the path, because the key carries the store**.

- Auth: `Authorization: Bearer mck_…` (also accepted as `X-Mercaria-Channel-Key`), scope `channels:write`, never expires, revocable per key server-side.
- **Push products:** `POST /channels/ingest/{connectionId}/products`, body `{ products: IngestProduct[] }`, batched at 100.
- **Push stock:** `POST /channels/ingest/{connectionId}/inventory`, body `{ items: { externalId, sku?, available:int }[] }`.
- **Test connection:** post a single inventory item with a sentinel `externalId` that maps to no listing → `skipped` (200, no catalog side effect). A 401 is a bad or revoked key; 400/403/404 means the connection id is not a push-in channel of the key's store.

`IngestProduct` carries `externalId` (the WooCommerce product id, and the idempotency key), `externalUpdatedAt`, `title`, `description`, absolute `images`, `vendor`, `productType`, `handle`, `seo`, `options` (variable products only) and `variants[]` — each with `optionValues`, `price`, `compareAtPrice`, `sku`, `barcode` and `inventory` when stock is managed.

**Money is always INTEGER MINOR UNITS in the shop currency.** The exponent is derived from ISO 4217 — 0 for zero-decimal currencies, 3 for three-decimal, otherwise 2. `12.50` in EUR is `{ amount: 1250, currency: "EUR" }`.

**`externalUpdatedAt` must be UTC `Z`, never an offset.** Mercaria's ingest schema accepts `…Z` only, so `WC_DateTime::format('c')` — which renders `+00:00`, and `+02:00` on a store in a non-UTC zone — is refused with a 400 for every product while "Test connection" (which carries no timestamp) still succeeds. Build it from `getTimestamp()`, never `getOffsetTimestamp()`: the latter is shifted for display and would move the instant.

**A blank secret field is ambiguous and `register_setting`'s sanitizer resolves it wrongly for one caller.** WordPress applies `sanitize_option_*` to `update_option()` as well as to the form, so the blank-keeps-existing rule silently restores anything a programmatic clear is trying to remove. Any code path that must CLEAR a stored secret deletes the option and re-creates it; it cannot update in place.

## Sync behaviour

- Hooks: new/update product, save variation (enqueues the PARENT), and both stock-set hooks (enqueue inventory).
- **Debounce and batch** — changed ids accumulate in two option-backed queues and a single debounced `wp_schedule_single_event` flushes them in batches of 100. **One request per burst, not one per save.**
- **Backfill is chunk-driven** (a page of published products at a time) to avoid PHP timeouts on large catalogs, with progress stored in an option and shown on the settings page.
- **The daily reconcile re-runs the full backfill** and is also the retry safety net when a live push fails — which is safe only because `externalId` is always the WooCommerce product id, so Mercaria upserts and never duplicates.

## Verifying changes

- `php -l` every changed file — it must report no syntax errors.
- Confirm the mapper still emits the exact `IngestProduct` shape, with `price.amount` as integer minor units in the shop currency.
- **WordPress-side behaviour (hooks firing, cron, admin nonces) must be verified in a real WordPress + WooCommerce install** — `php -l` only checks syntax.

## Not built

Orders and refunds, shipping (Moovo owns it), and product deletes — there is no delete endpoint in the contract yet, and archival on delete is a follow-up. A "Connect with Mercaria" flow that auto-provisions the connection and mints a key would remove the copy/paste but is not required: the ingest calls are unchanged either way.
