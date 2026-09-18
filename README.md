# Prime Printing Co. — WordPress theme

Custom WooCommerce theme for primeprint.com.kw, built to the approved design
references in `prime-printing-references/`. Follows
`prime-printing-build-plan.md`.

**Start here:** [`SETUP.md`](SETUP.md) — local dev runs via `./dev/boot.sh`,
see `SETUP.md`.

## Phase status

| Phase | | |
|---|---|---|
| 0 — Environment | Done | Real WordPress running via `./dev/boot.sh`, see `SETUP.md` |
| 1 — Design tokens & shared components | Done | Verified live — header, menu, footer, logo |
| 2 — Homepage | Done | Hero, ticker, shuffling wall, client marquee — verified live |
| 3 — Shop | Done | Categories grid, all-products AJAX pager, search/sort — verified live |
| 4 — Product & add-ons | Done | 4a/4b/4c all verified live, incl. server-side price enforcement; 4c formula is placeholder pending Reem's real calculators |
| 5 — Checkout | Done | Address types, governorate shipping, phone validation, gift-mode fix, COD, payment tiles, local pickup shipping option with a pickup-location map on order confirmation (`inc/checkout-pickup.php`) — all verified live with real placed orders |
| 6 — Bilingual | Done | Polylang installed and active; `en`/`ar` registered, existing content assigned; theme textdomain loads correctly (see gotcha below); RTL, `dir`/`lang` attributes, and translated theme strings verified live on `/ar/`. Individual Arabic-language content pages (Shop, Cart, Checkout, products) still need to be created in Polylang before `/ar/` versions of those URLs exist — until then they redirect to the English page, which is expected |
| 7 — Invoice | Done | Custom Dompdf generator (vendored without Composer — see `vendor/autoload.php`), matched against real historical Prime Printing invoices rather than the missing `prime-printing-invoice.html` mockup (Challan Pro was found already installed and is what generated those, with its own separate invoice-number sequence — Reem's call was to use the WooCommerce order number as the invoice number instead, so this build does that). Bilingual (EN structure, Arabic T&C — see the placeholder note below), color + ink-saving print modes, add-on/custom-pricing specs pulled automatically into line items, auto-attached to order emails, admin download/print buttons — all verified live against a real placed order. One placeholder still needs Reem's confirmation before launch: the exact T&C wording (`inc/invoice.php`) |
| 8 — Account | Done | Overrides WooCommerce's own `/my-account/` endpoints (per the plan) rather than a separate page: dashboard with real stat tiles, Orders tab (card layout, expandable specs, invoice download, reorder, WhatsApp deep link), Addresses tab (collapsed to one billing-only address — this checkout never had a separate shipping address — now showing the real Kuwait fields, not WooCommerce's generic ones, a real gap found and fixed mid-phase), a new Files tab (every uploaded design file, downloadable, reorder-with-same-file), Details tab (+ company billing info and notification prefs as user meta). Also fixed a real bug found while building this: the invoice/file download endpoints used `admin.php`, which silently redirects any real customer away before their request is ever handled (customers have no wp-admin access) — moved to frontend-reachable routes. All of it verified live against a real logged-in customer account, not just an admin account |
| 9 — Integrations | Deferred | Corrected from the build plan: the payment gateway is **Tap Payments**, not MyFatoorah — the WordPress plugin is already installed. QuickBooks sync is **MyWorks Woo Sync for QuickBooks Online** (not the "Intuit WooCommerce Connector" the plan names), already recording each order as a QB Sales Receipt. Both are already connected via their plugins — confirmed by Reem, no reconnection work needed. There's also a separate, already-live automation system (`prime-automation-hub`, QBO webhook → Tap payment link → WhatsApp, deployed on Netlify + Render) — confirmed out of scope for this build; it's for QB invoices created outside the shop, not WooCommerce orders. **Armada Delivery is explicitly deferred** — Reem's own team will handle the Armada integration (pricing and dispatch) later; do not build anything Armada-specific (shipping rate calls, dispatch payload, the two known bugs, credential rotation) until asked again. What that manual dashboard screenshot confirmed: Armada's real fee is computed per drop-off location (city → area → map pin, e.g. Saad Al-Abdulla City / area 11 → 3.000 KWD, 36 min ETA) — not the flat per-governorate placeholder currently in `checkout-data.php` — so whenever this is picked back up, that placeholder rate table is the first thing to replace, either via a live Armada API quote call or a real per-area rate table built from actual quotes (both discussed, neither started; blocked either way on Armada's API docs or a full set of real per-area quotes from Reem) |
| 10 — Migration | Not started | |
| 11 — Cutover | Not started | Deadline: Dec 30 2026 |
| 12 — QA | Not started | |
| 13 — iOS app (Capacitor, post-launch) | Not started | Apple Developer enrollment status needs confirming with Reem before starting |

## Live deployment (interim — still on WordPress.com, Cloudways migration deferred)

Reem's call (2026-09-03): apply the new theme to the *existing* live WordPress.com
site now, while ~4 months remain on the Business plan, rather than wait for the
Cloudways migration (Phase 10). So the theme is live on production
(primeprint.com.kw) ahead of Phase 10/11 as formally tracked above. Flatsome
was deactivated but is left installed for rollback.

What was done to get there:
- Theme uploaded via SFTP (`sftp.wp.com`, port 22) — WordPress.com's own
  "Push to Staging" sync repeatedly failed/hung, so this bypasses staging
  entirely. Password was reset for this; ask Reem before reusing/rotating it
  again.
- `inc/setup.php`'s `prime-cat` image size and the whole Shop category tile
  (`template-parts/shop/category-card.php`, `assets/css/shop.css`) were
  redesigned to drop the old Flatsome hexagon clip-path in favor of a flat
  hairline-bordered tile. The category **images themselves** originally still
  had the old hexagon baked into the PNG for most categories — since
  resolved: Reem supplied a full 18-icon glossy-3D SVG set
  (`prime-printing-icons-3d.html`), ported into `inc/category-icons.php` as
  inline SVG (`prime_category_icon_svg( $name, $unique_id )`, matched by
  category name, case-insensitive). `category-card.php` renders the matching
  inline icon and only falls back to the old term-thumbnail image if a
  category's name doesn't match anything in the set (e.g. a brand-new
  category with no icon yet) — nothing left using the hexagon-era PNGs on a
  normal run. Add a new category's icon by adding one more entry to the
  array in `prime_category_icons()`.
- Checkout also got three fixes the same day: the phone-number field's
  country-code box now aligns to the input, not the label+input combined
  (`.prime-phone`/`.prime-cc` in `checkout.css`); Cash on Delivery is
  disabled (`prime_disable_cod_gateway()` in `inc/checkout-payment.php` —
  it was dev-only scaffolding from before Tap Payments was live, self-heals
  if switched back on); and the terms-checkbox/pay button no longer floats
  fixed to the viewport bottom — it now sits in normal flow directly under
  Payment method (`.place-order` in `checkout.css`).
- **Shipping options were showing before any address was entered** — and
  too many of them. Root cause was two legacy, manually-configured WooCommerce
  *Flat rate* methods still sitting in the Kuwait zone ("Al Ahmadi / Al Jahra /
  Mubarak Al Kabeer" @ 3.000 and "City / Hawalli / Farwaniya" @ 2.000) that
  predate this theme's own governorate-based `Delivery` method
  (`inc/checkout-shipping.php`) and duplicated it, minus the address gating.
  Both are now **disabled** (not deleted) in WooCommerce → Settings →
  Shipping → Kuwait. Also confirmed WooCommerce's own "Hide shipping costs
  until an address is entered" is ON. Resulting flow: nothing shipping-related
  shows on Cart or Checkout until an address is entered; after a governorate
  + area is chosen the customer sees exactly two options — **Pick up From
  Al-Dajeej** and **Delivery** at that governorate's rate (verified live,
  e.g. Al Asimah → 1.500 KWD). Don't re-enable those two flat-rate methods;
  if a rate is wrong, change it in `prime_governorates()` in
  `inc/checkout-data.php` instead.
- `page-optimize` (WordPress.com's script/style combiner, a symlinked
  platform plugin — can't be edited) is now told not to concatenate JS or CSS
  (`js_do_concat` / `css_do_concat` filters in `inc/enqueue.php`). A
  third-party script with an undeclared jQuery dependency
  (`wcj-checkout-core-fields.js`) could throw inside the combined bundle and
  silently stop the theme's own `checkout.js` from running — intermittently,
  which is why the Area dropdown "worked" in testing but failed for a real
  customer. Every script/stylesheet now loads as its own tag.
- WooCommerce shipping *debug mode* was on, printing an admin-only "Customer
  matched zone 'Kuwait'" notice to every customer on Cart/Checkout. Now
  force-disabled, self-healing (`prime_disable_shipping_debug_mode()` in
  `inc/checkout-shipping.php`).
- **Mobile audit (2026-09-04, 375px, logged-out guest)** across Home, Shop,
  Product, Cart, Checkout, About, Contact: no page scrolls horizontally, no
  content element wider than the screen. One real bug found and fixed: on
  product pages the WooCommerce tabs row was clipped so the third tab
  ("Reviews") was off-screen and unreachable — WooCommerce core's
  `overflow: hidden` on `ul.tabs` plus its own `li` padding/negative margins.
  Fixed in `product.css` (tabs now wrap; core's li box reset). Two things
  that look like bugs in an audit but aren't: (a) the hero's shuffling image
  columns extend past the right edge by design, clipped by the hero's
  `overflow: hidden`; (b) `window.innerWidth` can read wider than the layout
  viewport in the browser tool (scrollbar gutter) — check
  `document.documentElement.clientWidth` / `visualViewport.width` instead.
- **Checkout, 2026-09-04 (four changes, all live):**
  1. *Area dropdown never populating on a real phone* — root cause found and
     fixed. WooCommerce turns `#billing_state` into a select2 widget, and
     select2 announces a pick via jQuery's `.trigger('change')`, which fires
     **no native DOM event** — so `checkout.js`'s `addEventListener('change')`
     never ran for a real tap. It only ever worked in tests that dispatched a
     native `Event` by hand (which is how it passed every earlier check).
     `checkout.js` now binds through jQuery when present. **Lesson: on any
     WooCommerce select, test with `jQuery(el).val(x).trigger('change')`, not
     `dispatchEvent`.** The "cities/states" plugins were suspected — they
     aren't even loaded on checkout; unrelated.
  2. *"Auto-fill address" / delivery pin* — hidden. It never filled an
     address; it only saved a GPS Google-Maps link on the order for the
     courier (admin order screen still shows it for old orders). Reem plans to
     rebuild it as real reverse-geocoding on the Google Maps API. Code is
     intact behind `apply_filters( 'prime_show_delivery_pin', false )` in
     `woocommerce/checkout/form-billing.php` — one filter re-enables it.
  3. *Gift mode* — Governorate and Area are hidden and no longer required
     (`[data-prime-address-common]`, toggled by `checkout.js`; both fields are
     now `required => false` at the WooCommerce level like every other
     sub-field, enforced per-type in `prime_validate_checkout()`). A gift
     order therefore has no governorate to price delivery from, so
     `Prime_Governorate_Shipping` adds a placeholder line — **"Delivery — fee
     confirmed with the recipient", 0 KWD** — instead of leaving the order
     with no delivery option. The amount is `apply_filters(
     'prime_gift_delivery_fee', 0 )`. **Pricing decision still needed from
     Reem:** keep 0 and settle with the recipient (via the existing
     WhatsApp/Tap-link flow), or set a flat gift-delivery fee via that filter.
     Plumbing: `prime_remember_checkout_address_type()` parks the chosen type
     in the WC session during update_order_review, and
     `prime_shipping_package_address_type()` stamps it onto each shipping
     package (which also keys WooCommerce's rate cache, so gift/house never
     share a cached rate).
  4. *Area list was missing most of Kuwait* — the list in
     `prime_governorates()` was a Phase 5 placeholder of ~40 areas (7 for
     Hawalli, 8 for Al Asimah). Replaced with the **real, full 129-district
     list**, imported from the "States, Cities, and Places for WooCommerce"
     plugin's own `places/KW.php` dataset and mapped onto this theme's
     existing governorate codes (codes deliberately unchanged, so saved
     orders' `billing_state` still resolves). Counts now AS 37, HA 17, FA 20,
     AH 27, JA 14, MU 14. **That plugin is not a dependency** — the data was
     copied into `inc/checkout-data.php`, which stays the single source
     checkout, cart and shipping all read, so it can be deactivated again
     with no change in behaviour.
  5. *Hiding areas Prime doesn't deliver to* — `prime_hidden_areas()` in
     `inc/checkout-data.php` is a plain list of area names to leave out of the
     dropdown; `prime_governorates()` filters them out, `prime_governorates_all()`
     still returns the raw 129 (also filterable via `prime_hidden_areas`).
     Hiding only affects the dropdown: the governorate keeps its rate, an
     order already placed to a now-hidden area still reads back fine (nothing
     validates a saved area against this list), and a customer there can still
     use "Other". Reem's first pass hides **20** — far-south Al Ahmadi
     (Wafra, Zour, Nuwaiseeb, Khairan, Mina Abdullah, Shuaiba Port, Sabah Al
     Ahmad…), the Jahra industrial estates and outlying desert (Kabd, Taima,
     Sulaibiya Industrial 1–2, Amgarah), and Wista / South Wista — leaving
     **109** offered (AS 37, HA 17, FA 20, AH 14, JA 9, MU 12).
  6. *"Other" area* — the Area select gets a last option `__other__` that
     reveals a free-text `prime_area_other` field; required when chosen; on
     save the typed name is written to `_billing_area` so the admin screen,
     invoice and dispatch all read a real area name. Not yet mirrored on the
     account page's Address tab (`assets/js/address-fields.js` still lists
     only the fixed areas) — minor, do when touching that file next.
### Serious bug found and fixed 2026-09-05 — no delivery fee was being charged

For an unknown period the live shop charged **no delivery at all**: cart and
checkout showed no Shipment section, and orders completed with shipping = 0.

Cause: WooCommerce's default locale marks the **postcode required for Kuwait**,
and this checkout has never collected one (a Kuwaiti address is governorate →
area → block → street). `WC_Cart::show_shipping()` returns false when a
*required* postcode is empty, which hides the entire shipping section at
render — while every shipping method still calculated its rate correctly and
invisibly behind it. Fixed by `prime_kuwait_address_locale()` in
`inc/checkout-data.php`, which marks KW's postcode not-required and hidden.

**Why it went unnoticed:** the admin's own saved test address carried a dummy
`0000` postcode, which satisfied the check — so it looked fine in testing and
broke only for real customers, who have no postcode. Any future "works for me,
broken for customers" report on checkout deserves a logged-out test with a
*fresh* address, not a saved one.

Two lessons worth keeping: an earlier session had also mistaken the missing
Shipment section for the intended result of disabling two flat-rate methods
(it wasn't — it was this bug), and `WC_Cart::show_shipping()` is the gate to
check whenever rates calculate but don't display.

### Armada live delivery pricing (built 2026-09-05, needs credentials)

Armada charges by **road distance from the shop** — their table runs 1.5 KD at
0–15 km up to 6 KD at 45–60 km, then 6 KD + 250 fils/km. A per-governorate
table cannot express that (Al Ahmadi alone spans several bands), so instead of
approximating it, `inc/armada.php` asks Armada what each specific address
costs, via `POST /v2/deliveries/estimate/static`.

- **Why `/static` and not `/estimate`:** static pricing ignores live traffic,
  so the same address always quotes the same fee. The live endpoint can move
  between the customer reading the total and paying it.
- **No new checkout fields were needed.** Armada's `kuwait_format` requires
  contact name, phone, area, block, street, building — all already collected.
  `prime_armada_address_from_posted()` maps them (building = house no. or
  building name depending on address type; "Other" area sends the typed name).
- **It can never block a sale.** Missing credentials, a timeout, a bad
  response, or a half-typed address all return `WP_Error` and
  `Prime_Governorate_Shipping::calculate_shipping()` falls back to the
  per-governorate table. Failures are logged to WooCommerce → Status → Logs,
  source `prime-armada`.
- **Caching:** quotes cache for an hour keyed on a hash of the exact
  origin+destination, and the address is stamped onto the shipping package so
  WooCommerce's own rate cache re-quotes when the address changes.
- **Credentials** are entered by Reem at WooCommerce → Settings → Shipping →
  Kuwait → Delivery → Edit (API key, secret, environment, optional branch ID,
  shop lat/lng defaulting to Al-Dajeej). They are deliberately not in this
  repository. Signing is HMAC-SHA256 over
  `"{timestamp}.{method}.{path}.{body}"`, valid for 30 seconds.
- Armada's dashboard already points an order webhook at
  `primeflowboard.netlify.app` (the separate, out-of-scope automation hub).
  Estimates are read-only and don't interact with it.
- **Still to verify once the key is in:** that a real address returns a fee
  matching the published table, and that Al Ahmadi/Jahra addresses (which the
  old flat table mispriced) now quote correctly. Until the key is entered,
  every order still prices from the placeholder table below.

### Waiting on Reem — two live-site decisions

1. **Real Armada delivery rates.** Reem is getting them and will send them
   through. Everything customers see today prices from the placeholder table
   in `prime_governorates()` (1.500 / 2.000 / 2.500 / 3.000 KWD) — plausible
   but *not* Prime's actual pricing, so the live site is currently quoting
   made-up delivery fees.
   - Swapping them in is a one-line edit per governorate **if** the real rates
     are flat per governorate.
   - If Armada in fact prices per *area* (which the dashboard screenshot
     suggests — city → area → pin, e.g. Saad Al-Abdulla area 11 → 3.000 KWD),
     the table needs a second level: `areas` becomes name ⇒ rate rather than a
     flat list, and `Prime_Governorate_Shipping::calculate_shipping()` reads
     the area instead of the governorate. Worth knowing which shape the rates
     arrive in before quoting the work — ask for a couple of sample areas
     within one governorate and check whether their fees differ.
2. **Gift-order delivery fee.** A gift order collects no address (recipient is
   phoned for it), so there's no governorate to price from. Right now it adds
   a placeholder line, **"Delivery — fee confirmed with the recipient", 0
   KWD**, set by `apply_filters( 'prime_gift_delivery_fee', 0 )` in
   `inc/checkout-shipping.php`. Either keep 0 and settle with the recipient on
   the existing WhatsApp/Tap-link flow, or set a flat gift fee through that
   filter.

- **Known plugin-side noise, not theme:** the Booster / "WooCommerce Jetpack"
  plugin (`woocommerce-jetpack`) is the source of several recurring console
  errors and oddities — `wcj-checkout-core-fields.js` calling jQuery before
  it's loaded (the trigger for the concat fix above), `wpColorPicker is not a
  function` from its admin script loading on the front end, and its "sales
  notification" popup injecting two `<img src="">` elements on every page
  (they render nothing). Whether that popup is wanted at all is Reem's call;
  if not, disable that module in the Booster settings rather than patching
  around it.
- The "Home" and "Our Profile" pages had their entire old Flatsome/UX-Builder
  page-builder content still sitting in `post_content` — harmless while
  Flatsome rendered the shortcodes, but printed as literal broken
  `[ux_banner …]` text once Flatsome was deactivated. Cleared both. **If any
  other page ever shows raw `[ux_...]`/`[col_grid...]` bracket text, this is
  why — clear that page's content the same way.**
- Footer contact details (`Customizer → Prime Printing → Contact details`)
  were still on their placeholder defaults (`+965 0000 0000`,
  `hello@primeprint.com.kw`) — live site was showing fake info. Set to the
  real number/email/WhatsApp link.
- `assets/css/cart.css` didn't exist at all (only `checkout.css` did) — the
  Cart page (`table.cart`, `.cart_totals`, shipping radios, the
  "Proceed to checkout" button) was rendering fully unstyled (generic
  WooCommerce purple button, no borders). Built it to match `checkout.css`'s
  conventions and deployed; verified live.
- **WordPress.com's edge cache serves stale CSS/JS at the exact `?ver=`
  query string** — a plain SFTP re-upload of a changed file is not enough,
  the browser/edge keeps serving the old cached copy at the old URL
  indefinitely. Fix: bump `Version:` in `style.css` (it's the `?ver=` for
  every theme asset, see `prime_asset_version()` in `inc/enqueue.php`) on
  every CSS/JS change before/after uploading, and re-upload `style.css` too.
  Currently at `0.1.11`. **Do this every time**, or changes silently won't
  show up in Live Preview or on the live site.

### False alarm, corrected — "theme JS not loading for guests" (2026-09-04)

Earlier the same day this was flagged as a critical, checkout-blocking bug
(the Area dropdown supposedly never populating for real customers). **It was
wrong — a false positive from a flawed test, not a real bug.** Verified with
a genuine logged-out session (explicitly logged out, added a real product,
went to Checkout, selected a Governorate): the Area dropdown populated
correctly, and the mobile menu opened correctly. Both work fine for guests.

What caused the false positive: the site's `page-optimize` plugin (WordPress.com's
own combiner) merges enqueued scripts into one file served from a hashed
`_jb_static/??<hash>` URL — normal, working behavior. The original test
searched the rendered HTML for a literal `<script src="...navigation.js">`
tag, which combining naturally never produces (the code is inlined into a
bundle under a hash unrelated to the filename), so its absence was
misread as "not loading." A second miss compounded it: the search regex only
matched double-quoted `src="..."`, but page-optimize's own tags use single
quotes (`src='...'`), so even the combined bundle's own script tag was
invisible to that search.

**Lesson for next time:** to check whether a script actually reaches guests,
test the *behavior* (click the menu button, select a governorate, check the
resulting DOM) — not the presence of a literal filename in the page source.
Combiners like `page-optimize` make filename-searching unreliable by design.

## Layout

```
wp-content/themes/prime-printing/
├── style.css              theme header only — no rules live here
├── functions.php          loads inc/, defines nothing else
├── rtl.css                auto-loaded when is_rtl(); Phase 6 extends it
├── screenshot.png
├── inc/
│   ├── setup.php          supports, menus, image sizes, editor palette
│   ├── enqueue.php        fonts + the cascade; page CSS enqueued conditionally
│   ├── template-tags.php  prime_mark(), prime_logo(), nav, categories, contact
│   └── woocommerce.php    supports, HPOS, wrappers, KWD 3-decimal prices
├── template-parts/
│   ├── header.php         topbar, wordmark, nav, language, cart
│   ├── mobile-menu.php    full-screen overlay
│   └── footer.php         four columns + legal line
├── assets/
│   ├── css/tokens.css     ← every brand value, single source of truth
│   ├── css/base.css       reset, type, mark, buttons, fields
│   ├── css/layout.css     header, menu, footer, page head
│   ├── css/home.css       hero shell (Phase 2 completes)
│   ├── js/navigation.js   menu open/close/focus-trap, header-on-scroll
│   └── img/               logo files
├── woocommerce/           overrides — see the README in that folder
└── languages/             .pot lands here in Phase 6
```

## Rules this build follows

**No raw brand values outside `tokens.css`.** No stylesheet contains a hex
colour, a font stack, or a spacing value — they read tokens. Changing the sky
blue is one edit, not a grep. The exception is the one-off white overlays on
navy (`rgba(255,255,255,.16)` and friends): the recurring ones are tokenised as
`--on-navy-*`, the incidental ones are left inline rather than growing the token
file with a name per opacity.

**Logical properties, not left/right.** `margin-inline`, `inset-inline-start`,
`border-inline-end`. Arabic mirrors for free; `rtl.css` only carries what
genuinely cannot flip on its own (the Latin wordmark, directional arrows).

**Every string is translatable.** `__()` / `esc_html__()` with the
`prime-printing` text domain, from the start — retrofitting these across finished
templates in Phase 6 is how strings get missed.

**WooCommerce templates are not stubbed ahead of their phase.** An empty override
replaces working output with broken output. The default template stays until its
replacement is finished — see `wp-content/themes/prime-printing/woocommerce/README.md`.

**Prices are never trusted from the browser.** Relevant from Phase 4c: the
client-side calculator renders a preview, and the same formula is re-run in PHP
at add-to-cart. This is the `custom_price` vulnerability on the current site and
it does not get carried over.

## References

Design references live in `prime-printing-references/` and are the source of
truth for colour, type and layout. They are references, not files to copy
wholesale — their inline `<style>`, `data-en`/`data-ar` JS translation toggle and
hard-coded product arrays are all replaced by proper WordPress equivalents
(enqueued CSS, Polylang, real WooCommerce queries).

`prime-printing-invoice.html`, listed in the build plan, is **not present** in
the references folder. It is needed for Phase 7.
