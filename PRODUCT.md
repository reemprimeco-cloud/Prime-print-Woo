# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Two audiences share one storefront (primekw.net), told apart by what they are doing:

- **Print buyers** (businesses, cafes, shops, schools, individuals) who need custom printed items (stickers, UV DTF transfers, paper bags, boxes, notebooks, calendars, stamps, signage) and either upload finished artwork, design it live in the browser, or ask for a quote.
- **Gift and occasion shoppers** in Kuwait (Ramadan, Eid, Gergea'an, graduation, teachers' day, parties) browsing ready or lightly customised products.

Customers use the website on desktop and phone, and the iOS app (a Capacitor wrapper of the same WordPress theme). Staff run the back office: orders, quotes, print files, delivery drivers.

## Product Purpose

Prime Printing Co. is a creative printing boutique in Kuwait. The site sells printed products online, produces print-ready files for customised orders, and collects orders that Prime prints and delivers. Success: a customer reaches a correct, orderable job (right size, artwork or quote request, delivery location) without a phone call, and staff receive production-ready files.

## Positioning

Four things together that a neighbouring Kuwait print shop could not truthfully claim:

- An online designer that outputs print-ready files (layered CMYK PDF with dieline for stickers, UV DTF, paper bags, binders).
- Everything printable in one place ("If it can be printed, we already print it").
- Local speed and delivery: Kuwait delivery with a map-pinned drop-off for the driver, shipping within 48 hours after approval.
- A choice of instant calculated price or a request-a-quote path for custom jobs (for example paper bags).

## Operating Context

- WordPress.com (Atomic) site with WooCommerce, Polylang (single-product mode), custom theme `prime-printing`, and the `prime-binder-designer` plugin; deploys through GitHub Actions over SFTP.
- The same theme serves the website and the iOS app shell (app mode has its own topbar, tab bar and home).
- Print files are rendered by a service on a Synology NAS (Playwright, Ghostscript, ICC profile) reached through a Tailscale Funnel URL.
- Payments through Kuwait gateways (MyFatoorah, UPayments, Tap); delivery through Armada; the delivery pin uses Leaflet and OpenStreetMap.

## Capabilities and Constraints

- Languages: English and Arabic (Modern Standard Arabic), with true RTL layout. Both are first-class.
- Currency: KWD to three decimals; phone +965; Kuwait governorates and areas.
- Domain: primekw.net (primary). Email runs on Google Workspace; DNS for mail must not be touched casually.
- Designer is optional for stickers, UV DTF and paper bags (Add to cart is always available); binders still require a design and a language choice.
- Paper-bag price constants are placeholders until Prime supplies the real formula.
- Tracking: Meta Pixel and Pinterest are active on the website and deliberately stripped inside the iOS app.

## Brand Commitments

- Name: Prime Printing Co. (PRIME wordmark; navy and sky-blue identity with the small square mark used across the theme).
- Bilingual copy in natural Modern Standard Arabic, not stiff or over-formal.
- Hero line in use: "If it can be printed, we already print it."
- Launch screen: animated logo and slogan in the iOS app.

## Evidence on Hand

- Real product catalogue with photos on the live store, category photos licensed from Shutterstock (originals in `originals/category-images/`).
- Existing customer-facing flows: calculator, quote requests, designer, checkout with delivery pin.
- Not available and must not be fabricated: customer testimonials, order counts, press coverage, turnaround guarantees beyond the stated 48 hours after approval, final bag pricing.

## Product Principles

1. A customer should reach a correct, orderable job without needing help; ambiguity is resolved in the interface, not by phone.
2. Arabic and English are equal: every surface must work in both directions.
3. Print correctness over decoration: sizes, bleeds, units and prices shown must match what production will receive.
4. One system, two shells: the website and the app share the theme, and changes must be checked in both.
5. Show real work: the catalogue's own photography carries the brand.

## Accessibility & Inclusion

- Bilingual RTL support is required.
- Motion must respect `prefers-reduced-motion`; hover-only affordances need touch equivalents.
- Phone-first: most visits are on phones, including inside the iOS app.
