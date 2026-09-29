# Prime Printing — Binder Live Designer for WooCommerce

**Read this whole file before writing code.** It is the complete spec for adding a
product-page design tool to a WooCommerce store: customers either upload a finished
design or design live in-browser (Canva-style), constrained to a locked print
template, and the store owner receives a print-ready CMYK PDF with the order.

Template assets referenced below ship alongside this file in `/templates/`.

---

## 0. Context (don't skip)

- Product: soft binder / كلاسير, sold as two printed panels:
  - **Outer cover** — flat trim 690 × 350 mm (includes 15 mm turn-in wrap on all sides)
  - **Inner liner** — flat trim 650 × 310 mm
  - Both share the same spine width (80 mm; was 75 mm in the first package,
    changed 2026-09-29) and panel layout (back — spine — front). The whole
    template package is generated from `scripts/make-templates.py`; change the
    numbers there, never in the generated files.
- All print files: **300 DPI, CMYK, 3 mm bleed, 5 mm safe margin.**
- Two customer flows, same output pipeline:
  1. **Upload flow** — customer uploads a finished image, positions/scales it inside
     the printable area on the template. No new artwork created in-browser.
  2. **Live design flow** — full in-browser editor (add text, upload elements,
     arrange), like Canva, constrained to the same template.
- End state per order: a print-ready PDF attached to the WooCommerce order,
  CMYK, no further edits needed before RIP.
- Existing stack (match it, don't introduce a competing pattern): React,
  Node.js, Supabase-style Postgres is available but **this integration must use
  WordPress/WooCommerce's own MySQL DB** via `$wpdb`, not Supabase. Twilio
  WhatsApp is already wired up for order notifications — reuse it. Fonts:
  Tajawal (Arabic), Poppins (Latin).
- Arabic/RTL is a hard requirement. The render pipeline **must** use a real
  Chromium engine (Playwright) for the final PDF export, not a server-side
  canvas library — Node canvas/Skia text shaping for Arabic is unreliable;
  Chromium's own text shaping is not.

---

## 1. Provided template assets (`/templates/`)

| File | Purpose |
|---|---|
| `binder-outer-template.pdf` | Reference PDF with correct TrimBox/BleedBox, CMYK guide layer |
| `binder-inner-template.pdf` | Same, for the inner liner |
| `binder-outer-template.svg` / `binder-inner-template.svg` | Same guides, real mm units, editable |
| `binder-outer-overlay.png` / `binder-inner-overlay.png` | **Transparent PNG guide layer** — bleed/trim/fold/safe-area lines + labels. Load this as a locked, non-printing top layer in the editor. 2400 px wide, alpha channel. |
| `binder-outer-spec.json` / `binder-inner-spec.json` | Machine-readable geometry: canvas size in mm and px @300dpi, each panel's trim box and safe box in mm, fold line positions. **This is the source of truth for all layout math — don't hardcode numbers, read from these files.** |

`spec.json` shape (both files share this schema):

```json
{
  "template": "binder_outer",
  "unit": "mm",
  "dpi": 300,
  "bleed_mm": 3,
  "safe_margin_mm": 5,
  "turn_in_mm": 15,
  "trim_mm": { "w": 690, "h": 350 },
  "canvas_with_bleed_mm": { "w": 696, "h": 356 },
  "canvas_with_bleed_px": { "w": 8220, "h": 4205 },
  "panels_relative_to_trim": [
    { "name": "back_cover", "trim_mm": {...}, "safe_mm": {...} },
    { "name": "spine", "trim_mm": {...}, "safe_mm": {...} },
    { "name": "front_cover", "trim_mm": {...}, "safe_mm": {...} }
  ],
  "fold_lines_x_mm_from_trim_left": [15, 305, 385, 675],
  "fold_lines_y_mm_from_trim_top": [15, 335]
}
```

Convert mm→px at 300 DPI with `px = mm / 25.4 * 300`. All editor canvases must
be built at this px size (or a working-resolution fraction of it — see §4.4).

---

## 2. Architecture

```
WordPress/WooCommerce (PHP)
 ├─ plugin: prime-binder-designer/
 │   ├─ enqueues React editor bundle on binder product pages only
 │   ├─ REST endpoints (namespace: binder/v1)
 │   ├─ custom table wp_binder_designs (draft + final design records)
 │   ├─ hooks into WooCommerce order lifecycle to attach the print PDF
 │   └─ WhatsApp notification via existing Twilio integration on order paid
 │
 ├─ React app: binder-editor/  (Vite build, output copied into the plugin's assets/dist)
 │   ├─ Upload-and-position mode (Fabric.js) — simple crop/scale/rotate in a mask
 │   ├─ Live design mode (Polotno) — full Canva-like editor
 │   └─ both modes save the SAME design JSON schema (§4.3) so one render
 │       pipeline handles both
 │
 └─ Node render service: binder-render-service/  (separate small service, not inside WP)
     ├─ Express, one endpoint: POST /render
     ├─ Playwright (Chromium) loads a print-only React route at full 300dpi
     │  canvas size, injects the design JSON, waits for fonts+images, exports PDF
     ├─ Ghostscript converts the exported RGB PDF to CMYK (FOGRA39 ICC)
     └─ returns the final PDF; WP plugin stores it and attaches to the order
```

**Why a separate Node service instead of doing everything in PHP:** Playwright
needs a real Chromium binary and Node runtime. Most WooCommerce hosting
doesn't allow that. Run this service on the same infra already hosting the
other Reemora Node apps (Netlify Functions won't work for Playwright — use a
small VPS, Railway, or Render.com — anywhere with a persistent Node process
and enough memory, ~1 GB minimum). The WP plugin calls it over HTTPS with a
shared secret header.

---

## 3. WordPress plugin: `prime-binder-designer`

### 3.1 File tree

```
prime-binder-designer/
├── prime-binder-designer.php          # plugin bootstrap
├── includes/
│   ├── class-rest-api.php             # REST endpoints
│   ├── class-product-meta.php         # per-product template assignment (admin UI)
│   ├── class-order-integration.php    # attach PDF to order, admin + my-account display
│   ├── class-render-client.php        # calls the Node render service
│   └── class-db.php                   # wp_binder_designs table create/query
├── assets/
│   └── dist/                          # built React bundle goes here (gitignored, built by CI or manually)
└── templates/                         # copy of the /templates/ assets from this package
```

### 3.2 Custom DB table

```sql
CREATE TABLE {$wpdb->prefix}binder_designs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  order_item_id BIGINT UNSIGNED NULL,
  session_token VARCHAR(64) NOT NULL,      -- ties a draft to a guest cart before checkout
  template VARCHAR(32) NOT NULL,           -- 'binder_outer' | 'binder_inner'
  mode VARCHAR(16) NOT NULL,               -- 'upload' | 'live'
  design_json LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft | rendering | ready | failed
  preview_url VARCHAR(500) NULL,
  pdf_url VARCHAR(500) NULL,
  pdf_cmyk_url VARCHAR(500) NULL,
  validation_warnings LONGTEXT NULL,       -- JSON array, see §4.5
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (session_token),
  INDEX (order_id)
) {$wpdb->get_charset_collate()};
```

### 3.3 REST endpoints (`binder/v1`)

| Method | Route | Purpose |
|---|---|---|
| GET | `/template/{template}` | Returns spec.json + signed URLs for the overlay PNG, for the editor to bootstrap |
| POST | `/design` | Create/update a draft design row. Body: `{session_token, product_id, template, mode, design_json}` |
| POST | `/design/{id}/preview` | Low-res preview render (fast, RGB PNG only, for on-screen proof — not the production file) |
| POST | `/design/{id}/finalize` | Called at add-to-cart: locks the design, triggers the full production render job async |
| GET | `/design/{id}/status` | Poll render status (`rendering` \| `ready` \| `failed`) |
| GET | `/design/{id}` | Fetch a saved design (used to reopen "Edit design" from cart/account) |

Auth: guest-safe via `session_token` (random UUID stored in a cookie/localStorage
on first editor load) matched against WooCommerce session; validate ownership
before returning/mutating a design.

### 3.4 Product integration

- Add a product meta box (Woo product data panel, new tab "Binder Template")
  where Reem selects: `binder_outer`, `binder_inner`, or `binder_set` (sells
  both, requires two design sessions — outer + inner — before add-to-cart is
  enabled).
- On the frontend product page, replace/augment the Add to Cart button:
  - Two buttons: **"Upload your design"** and **"Design it now"**.
  - Both open the same editor React app in a modal, just starting in a
    different mode (`?mode=upload` or `?mode=live`).
  - Add to Cart is disabled until `finalize` returns a `design_id`; store
    `design_id` as a hidden cart item field (`WC()->cart->add_to_cart(..., cart_item_data)`).
  - If `binder_set`, require both an outer and inner `design_id` before enabling
    Add to Cart, and show two "Design ✓ / Design →" rows.

### 3.5 Order integration

- On `woocommerce_checkout_create_order_line_item`, copy `design_id`(s) from
  cart item data onto order item meta, and update the `wp_binder_designs` row
  with `order_id` / `order_item_id`.
- On order status → `processing`/`paid` (hook `woocommerce_order_status_changed`):
  1. If not already `ready`, block until the production render finishes (it
     should already be running from `finalize`, called at add-to-cart time —
     don't wait for payment to start rendering).
  2. Attach the **CMYK PDF** as a downloadable link in:
     - WooCommerce admin order screen (custom meta box, "Print Files" — direct
       download links per line item, outer + inner separately)
     - Customer's My Account → Order view (their own uploaded/designed proof,
       for their records — not the CMYK file, give them the RGB preview)
  3. Trigger the existing Twilio WhatsApp integration: notify Reem's shop
     number with the order number and a direct link to the CMYK PDF(s).

---

## 4. React editor app (`binder-editor`)

### 4.1 Stack

- Vite + React + TypeScript.
- **Live mode:** [Polotno SDK](https://polotno.com/) — commercial license required
  (buy before going to production; free tier works for development).
- **Upload mode:** Fabric.js (free, MIT) — simpler task, doesn't need Polotno's
  full editor chrome. Keep this mode lightweight: one image, drag/scale/rotate
  inside a clip mask, nothing else.
- Both modes render into the **same coordinate system**: mm, using the numbers
  from `spec.json` for that template. Never hardcode 685/350/645/310 etc. in
  the React code — fetch and read from `/template/{template}`.

### 4.2 Guide overlay behavior

- Load `{template}-overlay.png` as a top layer, `locked: true`, `listening:
  false` (non-interactive, click-through), opacity as-is (already semi-transparent
  in the PNG). Never export this layer — it's stripped before the print render
  (§5 loads a *different*, print-only route with no overlay).
- Show a persistent legend/caption in the UI (not baked into canvas) explaining
  the guide colors, matching the legend already printed in the template:
  bleed / trim / fold / safe area / turn-in / corner miter.

### 4.3 Design JSON schema (shared by both modes)

```json
{
  "template": "binder_outer",
  "mode": "live",
  "canvas_mm": { "w": 691, "h": 356 },
  "elements": [
    {
      "type": "image",
      "src": "https://.../uploaded-asset.jpg",
      "x_mm": 20, "y_mm": 18, "w_mm": 200, "h_mm": 280,
      "rotation_deg": 0,
      "source_px": { "w": 4000, "h": 3000 }
    },
    {
      "type": "text",
      "text": "الصف السادس - رياضيات",
      "font": "Tajawal", "size_pt": 42, "weight": "700",
      "color_cmyk": [0, 0, 0, 100],
      "x_mm": 40, "y_mm": 200, "w_mm": 220,
      "align": "center", "rtl": true
    }
  ]
}
```

- `source_px` on images is required — it's how the DPI validation (§4.5) checks
  whether the uploaded file has enough resolution for its placed size.
- Upload-mode designs are just a design JSON with a single `image` element and
  no `text` elements — same schema, same renderer.

### 4.4 Working resolution vs. export resolution

Don't run the live editor canvas at full 8161×4205 px in the browser — it'll
be slow on customer devices. Run the editor at a working scale (e.g. 1600 px
wide, computed as a fraction of the real mm size) and store all element
positions in **mm**, not px, in the design JSON. The render service (§5)
rebuilds the canvas at full 300 DPI px from the same mm coordinates, so
position/scale is resolution-independent by construction.

### 4.5 Client-side validation (run continuously, block finalize on hard errors)

Read thresholds from `spec.json`. Implement:

1. **Resolution check (warning):** for each image element, compute effective
   DPI = `source_px.w / (w_mm / 25.4)`. If < 150 DPI → warn "may print blurry."
   If < 100 DPI → hard block.
2. **Safe-margin check (warning):** for text/logo elements (not full-bleed
   background images), flag if the element's bounding box falls outside the
   panel's `safe_mm` box from spec.json.
3. **Empty bleed check (warning):** if the outermost background element
   doesn't cover the full `canvas_with_bleed_mm` box, warn that white will
   show at the trim edge after cutting.
4. **Turn-in check (outer template only, hard rule):** block any text/logo
   element (not background fill) from being placed inside the 15 mm turn-in
   zone — call this out explicitly in the UI ("this area wraps behind the
   board and will not be visible — background color only").

Push all triggered warnings into `validation_warnings` when calling `/design`,
so the admin order screen can show "customer proceeded despite N warnings."
Never silently auto-fix a customer's layout — warn and let them decide.

---

## 5. Node render service (`binder-render-service`)

### 5.1 Stack

Express + Playwright (Chromium) + a **print-only** React route (same repo as
the editor, different entry — e.g. `/print-render/:template`) that:

- Takes the design JSON via `postMessage` or a query-loaded JSON blob.
- Builds the canvas at true 300 DPI px size from `spec.json`, mm→px math.
- Renders every element (fonts must be self-hosted, same Tajawal/Poppins
  files already used elsewhere in the stack — don't rely on Google Fonts CDN
  at render time, embed locally for reliability and speed).
- **No guide overlay, no UI chrome** — pure artwork only, full bleed.
- Signals render-complete (e.g. sets `window.__RENDER_READY__ = true`) so
  Playwright knows when to capture.

### 5.2 Render endpoint

```
POST /render
{ "design_id": 123, "template": "binder_outer", "design_json": {...} }
→ 200 { "pdf_rgb_url": "...", "pdf_cmyk_url": "..." }
```

Steps inside the handler:
1. Launch Playwright Chromium, `deviceScaleFactor` set so the viewport in CSS
   px times scale factor equals the target px size (or just set the viewport
   directly to the target px size — simpler, do this).
2. Navigate to `/print-render/{template}?design_id={id}`, wait for
   `__RENDER_READY__`.
3. `page.pdf({ width: '{mm}mm', height: '{mm}mm', printBackground: true,
   margin: 0 })` — this is the existing pattern already used in the Reemora
   pipeline (Playwright HTML→PDF), reuse it verbatim.
4. Shell out to Ghostscript to convert RGB → CMYK:
   ```bash
   gs -dSAFER -dBATCH -dNOPAUSE -dNOCACHE \
      -sColorConversionStrategy=CMYK \
      -sProcessColorModel=DeviceCMYK \
      -sDEVICE=pdfwrite \
      -sOutputICCProfile=FOGRA39.icc \
      -sOutputFile=output-cmyk.pdf input-rgb.pdf
   ```
   Source `FOGRA39.icc` from ECI (eci.org, free download, "eciCMYK" or "ISO
   Coated v2 300%" — same profile already referenced for Prime Printing's
   CMYK conversion). Bundle it in the render service, don't fetch at runtime.
5. Stamp TrimBox/BleedBox onto the output PDF (mirror what's already done in
   the reference template PDFs — pypdf equivalent in Node is `pdf-lib`,
   `setTrimBox`/`setBleedBox` or set the `/TrimBox` and `/BleedBox` entries
   directly via `pdf-lib`'s low-level page dict access if not exposed).
6. Upload both PDFs to storage (WP media library via REST, or S3-compatible
   bucket — match whatever's already used for Reemora file storage) and
   return the URLs.
7. Update `wp_binder_designs.status = 'ready'`, store both URLs.

### 5.3 Security

- Shared-secret header between WP plugin and this service
  (`X-Binder-Secret`), not public.
- Validate `design_json` shape server-side before rendering (don't trust the
  client blindly — re-run the §4.5 hard-block checks server-side too).
- Rate-limit `/render` per session_token to prevent abuse.

---

## 6. Build order (do these in sequence, each should be independently testable)

1. **Scaffold the plugin** with the DB table + REST stub endpoints returning
   mock data. Confirm activation/deactivation hooks create/drop the table
   cleanly.
2. **Build `/print-render/:template`** first, standalone, fed a hardcoded
   sample design JSON. Get one flawless PDF export (correct mm size, correct
   TrimBox, Arabic text shaping correct, CMYK conversion correct) before
   touching the interactive editor. This is the highest-risk piece — validate
   it in isolation.
3. **Build the render service** around that route (Playwright + Ghostscript +
   trim/bleed box stamping). Test with 3–4 sample design JSONs covering:
   image-only, Arabic text, mixed, and a deliberately-bad one (low-res image)
   to confirm validation blocks it.
4. **Build the upload-mode editor** (Fabric.js, simpler) end to end: upload →
   position → validate → save draft → finalize → poll status → see the
   rendered PDF link. This proves the whole pipeline before the harder
   Polotno integration.
5. **Build the live-mode editor** (Polotno) reusing the same save/finalize/
   poll logic from step 4.
6. **Wire into the product page** (buttons, modal, Add to Cart gating).
7. **Wire into checkout/order** (attach files, admin display, customer
   display, WhatsApp notify).
8. **End-to-end test with a real order**, both templates, both modes, at
   least one Arabic-text design, before going live.

---

## 7. Things to explicitly confirm with Reem before/while building

- Turn-in depth (15 mm) and corner miter assume **2–3 mm board thickness** —
  confirm with the actual board stock, adjust `spec.json` and regenerate
  templates if different (ask for the regenerated package rather than editing
  the geometry by hand).
- Front/back panel order is mirrored between outer (front on the right) and
  inner (front on the left) — confirm this still matches the shop's binding
  convention before launch.
- Where should the Node render service live (VPS/Railway/Render.com) — needs
  ≥1 GB RAM for Playwright, persistent process, not serverless-function-only
  hosting.
- Polotno license tier (commercial use requires a paid plan).
- Final storage location for generated PDFs (WP media library vs. existing
  Reemora storage bucket) — match whatever's already standard for the other
  apps.

---

## 8. Package dependencies (reference)

**binder-editor (Vite React app):**
```
polotno, fabric, react, react-dom, zustand (or existing state approach), axios
```

**binder-render-service (Node/Express):**
```
express, playwright, pdf-lib, node-fetch (or axios), dotenv
```
Plus system binary: `ghostscript` installed on the render service host, and
`FOGRA39.icc` bundled in the repo (download once from eci.org, commit the
file — it's free to redistribute).

**WordPress plugin:** no Composer dependencies required beyond WP/WooCommerce
core APIs; keep it framework-free PHP to avoid conflicts with the existing
theme.

---

## 9. Sticker template (added after the binder build)

One template, `sticker`, for every sticker product (paper, PP, UV DTF, die-cut
cards). It differs from the binder covers in one way: **there is no spec.json**.
The customer chooses the size and shape in the product's calculator, and the
spec is derived from those three values — `binder-shared/src/sticker.ts`
(`stickerSpec`) for the editor and the render service, `class-sticker.php` for
the plugin. Keep the two in step; the constants are:

| | |
|---|---|
| bleed | 1 mm |
| safe zone | 2 mm |
| size range | 10 – 1000 mm per side (0.1 mm steps) |
| shapes | rectangle, square, round, hexagon, triangle, star, heart, custom |
| calculator aliases | circle → round, rect → rectangle |

- The design JSON carries `sticker: { w_mm, h_mm, shape }`. `validateShape`
  refuses a design whose params do not match the spec; the render service
  rebuilds the spec from those params (`loadSpec(cfg, 'sticker', design)`), and
  the plugin checks them against the calculator fields posted with add-to-cart
  (`Binder_Sticker::design_matches_request`), so a design made for 5 × 5 cm can
  never ship with a 7 × 7 cm order.
- The guide overlay is an SVG drawn from the spec (`stickerOverlaySvg`): veil
  over the bleed, magenta cut line, blue dashed bleed, grey dashed safe zone —
  the colours of the approved sticker designer mock-up. `overlay_url` from
  `GET /template/sticker?w=&h=&shape=` is empty; the editor draws it.
- The print files get the cut line as a **CutContour spot colour** stroke
  (Separation, alternate 100 % magenta, overprinting), stamped after the
  Ghostscript CMYK conversion so it survives as a spot. `TrimBox` is the cut
  line's bounding box; for round and other shapes the plotter follows the
  contour. No nesting: one artwork file per sticker design.
- Product side: assign **Sticker** in the product's Binder Template tab. The
  storefront reads the size from the calculator fields (`paper_width`,
  `pp_width`, `uvdtf_width`, `diecut_width`; filter `binder_sticker_fields`),
  passes it to the editor, and drops the design if the customer changes the
  size or shape afterwards.
