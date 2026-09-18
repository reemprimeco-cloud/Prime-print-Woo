# Running the site

There is a **real WordPress install** here — real PHP 8.2, real WooCommerce, the
real theme. Start it with:

```bash
./dev/boot.sh
```

Then open <http://127.0.0.1:9400>. First boot takes a few minutes (it downloads
WordPress, WooCommerce and Polylang, then imports the catalogue); later boots
are quicker.

`dev/boot.sh` wraps `dev/start.sh` with automatic retries — this sandbox's
network to wordpress.org has been intermittent throughout this build (roughly
one `fetch failed` in two or three attempts, never a real code problem), and it
also occasionally fails to install a plugin *without* aborting the boot, which
looks like success until you notice Polylang isn't active. The wrapper retries
until the site is both reachable and has every plugin this build needs
actually active, up to 5 attempts. Use `./dev/start.sh` directly only if you
want to see one attempt's raw output.

## What this is, and what it is not

`dev/start.sh` runs **WordPress Playground** — genuine PHP compiled to
WebAssembly, running inside Node. It is a real WordPress site: real plugins, real
WooCommerce objects, real template rendering. It needs no PHP, no MySQL, no
Homebrew and no admin password, which is why it is what runs on this machine.

It is a **development environment**, not a host. Production is Cloudways
(Phase 11). Two consequences worth knowing:

- **State is not persistent.** Each restart rebuilds the site from
  `blueprint.json` plus the seeder. Anything typed into wp-admin is lost on
  restart — so configuration that must survive belongs in `blueprint.json`, and
  content that must survive belongs in the seeder.
- **Some integrations cannot be tested here.** MyFatoorah, Armada and the
  QuickBooks connector all call out to live services and need a conventional
  host. Those are Phase 9, on staging.

## The alternative: Local by WP Engine

If you would rather have a persistent, conventional install — and you will want
one before Phase 9 — install **Local** from <https://localwp.com/>, create a site
on **PHP 8.2 / MySQL 8.0**, and copy the theme in:

```bash
cp -R "/Users/primeco/Downloads/Prime print Woo/wp-content/themes/prime-printing" ~/Local\ Sites/prime-printing/app/public/wp-content/themes/
```

Then activate it under Appearance → Themes, install WooCommerce, and apply the
store settings listed below.

## Classic checkout, not the block checkout

WooCommerce defaults new installs to the **block-based** Cart and Checkout
pages (the `woocommerce/cart` and `woocommerce/checkout` blocks). This theme's
entire Phase 5 checkout — `woocommerce_checkout_fields`, every template in
`woocommerce/checkout/*.php`, the governorate shipping method, payment tiles —
targets the **classic shortcode** system instead, which is a different
customization surface WooCommerce still fully supports. `prime_force_classic_checkout_pages()`
in `inc/checkout-data.php` corrects the Cart/Checkout pages back to
`[woocommerce_cart]` / `[woocommerce_checkout]` automatically on theme
activation, so this should never need doing by hand — but if checkout ever
shows WooCommerce's stock block UI instead of the design, this is why: check
whether the Checkout page's content is still the shortcode, not the block.

## Regenerating Arabic translations

`languages/ar.po` is the source of truth; `languages/ar.mo` is compiled from
it (see the script referenced in `inc/setup.php`'s translation-loading
comment). If you ever regenerate the `.mo` file by hand, **it must be named
`ar.mo`, not `prime-printing-ar.mo`.** WordPress's core textdomain loader
uses the bare-locale filename (`{locale}.mo`) for any theme whose languages
directory lives inside the theme itself, as this one does — the
domain-prefixed convention (`{textdomain}-{locale}.mo`) only applies to
translations loaded from the global `WP_LANG_DIR/themes/` directory. Getting
this backwards fails silently: the domain still reports as "loaded", the URL,
`dir="rtl"`, and `get_locale()` all still correctly show Arabic, but every
`__()` call quietly falls through to the English source string. This is
exactly what happened during Phase 6 development — the `.mo` was originally
shipped as `prime-printing-ar.mo` and nothing about the request looked wrong
except the actual copy on the page.

## Invoice PDFs vendor Dompdf without Composer

`inc/invoice.php` (Phase 7) generates invoices with Dompdf. There's no
Composer on this project's dev machine — no PHP CLI to run it against, since
the site itself only runs inside the WASM PHP sandbox described above — so
`wp-content/themes/prime-printing/vendor/` holds Dompdf and its four
dependencies (`php-font-lib`, `php-svg-lib`, `masterminds/html5`,
`sabberworm/php-css-parser`) as plain source trees pulled directly from their
tagged GitHub releases, with `vendor/autoload.php` standing in for Composer's
own autoloader (a small hand-written PSR-4 map, not the real thing).

If any of these libraries are ever upgraded: re-check each package's own
`composer.json` `autoload` block against the map in `vendor/autoload.php`,
and don't forget the non-`src/` files some of them need at runtime —
`dompdf/dompdf`'s root-level `VERSION` file and `php-font-lib`'s root-level
`maps/` directory both being missing is exactly what broke this the first
time it was set up (Dompdf's constructor reads `VERSION` directly via
`file_get_contents()`, so a missing file there is a fatal error with no
obvious connection to what's actually wrong).

On a real production host (with Composer available), replacing this with a
proper `composer require dompdf/dompdf` and deleting the hand-vendored
`vendor/` folder is worth doing before launch — this setup exists only
because of this specific dev machine's constraints, not because it's the
right long-term approach.

## Store settings

These are set automatically by `blueprint.json`. On any other install they must
be set by hand, because the design depends on them:

| Setting | Value | Why |
|---|---|---|
| Country / region | Kuwait | Shipping zones, tax base |
| Currency | Kuwaiti dinar (KWD) | |
| Number of decimals | **3** | KWD is quoted to three; every reference shows `18.750` |
| Currency position | **Right with space** | The references read `18.750 د.ك`, not `د.ك18.750` |
| Permalinks | Post name | Product and category URLs |
| **Coming soon mode** | **Off** (WooCommerce → Settings → General) | New WooCommerce stores default to this **on**, which shows a "Great things are on the horizon" placeholder on every store page (shop, product, cart, checkout) instead of the real theme — while the homepage looks fine, since coming-soon mode can be scoped to store pages only. This is the actual switch to flip before Phase 11 launch, not just a dev-environment setting. 

The theme enforces the decimal count itself (`prime_price_decimals()` in
`inc/woocommerce.php`) because a two-decimal price would misprice every order.
Currency *position* is left as a store setting — it is presentation, and
overriding a merchant's choice from a theme is the wrong place for it.

## Development data

`dev/mu-plugins/prime-dev-seed.php` populates a fresh install with the real
18-category structure, a representative catalogue, and the client logos. It is
**development only** and does not ship with the theme; Phase 10 replaces it with
the real CSV export from the live site.

One rule it follows, worth keeping: **each photograph belongs to exactly one
product.** Sharing a photo between two products guarantees at least one of them
is captioned with something it does not show. Products with no truthful photo yet
are created without one — the homepage wall filters imageless products out by
design, so nothing is ever shown under the wrong name.

## Debugging

`WP_DEBUG` and `WP_DEBUG_LOG` are on, `WP_DEBUG_DISPLAY` is off — errors go to
the log rather than being printed into the page, where they would corrupt
WooCommerce's AJAX responses and surface as a phantom JS bug.

```bash
tail -f /tmp/claude-*/scratchpad/playground.log   # boot and PHP errors
```

## If the site loads with the wrong theme

Occasionally a boot comes up with WooCommerce active but the theme still on
Twenty Twenty-Five and the site title still "My WordPress Website" — the
`activateTheme` and `setSiteOptions` blueprint steps failed silently (a network
hiccup fetching from wordpress.org mid-boot; WooCommerce and the seeder still
ran fine since they don't depend on those steps). `blueprint.json` ends with a
`runPHP` step that force-corrects this after every other step runs, so it
should self-heal — if it still happens, `pkill -f wp-playground` and
`./dev/start.sh` again.

## Disk

The install needs roughly **2 GB**, and Phase 10's media import will want several
more. This machine has run out of space twice during this build; if a boot fails
with `No space left on device`, that is why. `npm cache clean --force` reclaims a
few GB safely.
