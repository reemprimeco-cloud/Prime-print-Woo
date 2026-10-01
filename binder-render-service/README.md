# binder-render-service

Turns a binder design JSON into a print-ready CMYK PDF (spec §5).

```
POST /render  ->  validate  ->  fetch + inspect images  ->  Chromium (print route)
              ->  exact CMYK text colours  ->  Ghostscript FOGRA39  ->  TrimBox / BleedBox
```

## Run locally

```bash
cd binder-editor && npm run build          # the print page the service loads
cd ../binder-render-service
ENABLE_SAMPLES=1 BINDER_SECRET=dev ALLOWED_IMAGE_HOSTS=127.0.0.1 npm run dev
```

Needs Ghostscript (`GS_BIN`, default `~/.local/prime-tools/gs/bin/gs` or `/usr/bin/gs`) and an ICC
profile at `assets/icc/FOGRA39.icc`. That file is git-ignored: on this Mac it is Adobe's "Coated
FOGRA39" copy, for development only. **For production use ECI's `ISOcoated_v2_300_eci.icc`** (eci.org,
free download) — see "Open items".

## API

`POST /render` — header `X-Binder-Secret`, JSON body:

```json
{ "design_id": 123, "template": "binder_outer", "session_token": "…16-64 chars…",
  "design_json": { … }, "callback_url": "https://…/optional" }
```

* `200 { status:"ready", pdf_rgb_url, pdf_cmyk_url, warnings, timings_ms }` — synchronous.
* With `callback_url`: `202 { job_id }` immediately; the result is POSTed to the callback signed with
  `X-Binder-Signature: sha256=HMAC(secret, body)`; `GET /jobs/:id` also reports it.
* `422 { error:"validation_failed", errors:[…] }` — a hard block (resolution under 100 dpi, text in the
  turn-in zone, …). Nothing is rendered or stored.
* `429` rate limit per `session_token`, `503` queue full, `401` bad secret.

File links are signed and expire after an hour; the WordPress plugin downloads and keeps its own copy.

## What it will not trust

| Client claim | What the service does |
|---|---|
| `source_px` of an image | reads the real file and uses that (a 600 px image cannot claim to be 300 dpi) |
| Text `h_mm` | the browser measures the real height; the final turn-in / safe check uses it |
| Image `src` | server fetches it (allow-listed host, no redirects, size/type checked); Chromium gets a local file and no network |
| `callback_url` | host must be in `ALLOWED_CALLBACK_HOSTS` |

## Colour

Chromium can only write RGB. Each text colour is painted as a unique "sentinel" RGB and then rewritten
in the PDF to the design's own DeviceCMYK numbers, so pure black text stays `0 0 0 1 k` (colour-managed
RGB→CMYK would make a four-colour rich black). Photos are converted by Ghostscript through the ICC profile.

## UV DTF transfers

The `uvdtf` template is not a PDF job. The print route is captured as a transparent raster at 300 dpi
(`pipeline/uvdtf.ts`) and the service returns two files, listed under `files` in the result with
`proof_kind: "png"` and `print_kind: "tiff"`:

- `png` — sRGB + alpha, the exact artboard size; the customer's proof and a usable print file;
- `tiff` — CMYK through the ICC profile, a transparency channel (Photoshop's "save transparency" layout, so
  the file does not open on paper white), plus two spot channels built from the artwork's coverage, named
  **White** and **Varnish** the way Photoshop names them (image-resource block 1006/1045/1007/1077), with
  the profile embedded, Deflate strips, 300 dpi. 0 = solid ink in a spot channel, as in Photoshop.
  `pipeline/tiff-spot.ts` writes it; `readSpotTiff()` there reads it back for the tests.

No bleed, no cut line: the artboard is the size typed in the calculator, and nothing prints where
nothing was placed. `scripts/export-uvdtf.ts` renders the sample to files for a look.

## Tests

```bash
npm test                        # 27 integration tests over the real pipeline
scripts/verify-all.sh           # renders the samples and prints every check
python3 scripts/verify-pdf.py output/x.cmyk.pdf binder_outer --k-only
python3 scripts/verify-geometry.py output/x.cmyk.pdf binder_outer ../binder-shared/samples/x.json
```
`verify-pdf.py` and `verify-geometry.py` share no code with the pipeline on purpose.

## Deploy

`Dockerfile` (context = repo root). Host needs ≥ 1 GB RAM and a persistent process (Railway, Render,
a VPS) — not serverless. See the repo's `binder-shared/SPEC.md` §7 for the hosting decision.
