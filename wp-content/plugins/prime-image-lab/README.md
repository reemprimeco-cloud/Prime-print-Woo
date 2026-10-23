# PRIME Image Lab

Free image tools for primekw.net that run **entirely in the visitor's browser**.
Shortcode: `[prime_image_lab]`, placed on the page `/image-lab/`.

## What it does
Three one-tap jobs, no settings:
- **Make it bigger and clearer:** picks the enlargement automatically (up to 4x for small photos, none for large ones) and applies light sharpening and noise reduction. Browser Canvas resampling, not AI.
- **Remove the background:** cuts out the main subject with a small AI model (U²-Net-P, Apache-2.0) running in the browser through ONNX Runtime Web (MIT). On first use the visitor is told about the download (about 19 MB, once), sees its progress, and agrees before anything loads. If the model cannot run (old browser, blocked file), it falls back to removing a flat single-colour background and says so. Exports a transparent PNG. Works best when one object or person stands out; multiple objects or similar-coloured backgrounds can lose parts.
- **Make a print-ready file:** pick A5, A4, A3 or A2; the photo is fitted to the paper at 300 DPI (lower on phones that cannot make that many pixels), the DPI is written into the JPEG/PNG file, and a plain verdict says whether the photo is sharp enough.
Jobs stack on the current result.
- **WhatsApp:** on phones the button opens the share sheet with the result as a one-page PDF (JPEG embedded at maximum quality, or lossless with transparency), so WhatsApp receives it as a document and does not recompress it. On devices without file sharing it opens the chat and explains how to attach the downloaded file as a Document. Export is JPEG, or PNG when transparent, with a generic filename. English and Arabic (RTL).

## Privacy
Images are read with `createImageBitmap`, drawn on in-memory canvases, processed in a Web Worker (or on the page if
workers are blocked) and downloaded through a short-lived blob URL. No upload, no REST/AJAX route, no database table or
option, no analytics, no third-party scripts or fonts. Canvases are released on Start over and on page hide.

## Files
| File | Purpose |
|---|---|
| `prime-image-lab.php` | Shortcode, page-scoped enqueue, WhatsApp and shop links |
| `assets/image-lab.js` | The page |
| `assets/image-lab-core.js` | Pixel maths and print preflight (also used by the worker) |
| `assets/image-lab-worker.js` | Worker wrapper |
| `assets/image-lab.css` | Styles, all scoped to `.pil` |

No build step. The only third-party files are the optional AI cut-out engine and model, listed with licences in `NOTICE.md`. No external requests: everything is served from this plugin.

## Limits (shown to users where relevant)
- JPEG, PNG, WebP up to 30 MB. Max 40 megapixels on computers and 16 megapixels on phones and tablets
  (iOS Safari cannot create larger canvases).
- Resizing cannot recreate detail. The page never calls anything "AI".
- JPEG export drops colour profile and EXIF. No CMYK conversion, bleed, cutlines or PDF preflight.
- The AI cut-out uses a small model (4.6 MB): good on single objects and people on clear backgrounds, weaker on several objects, fine hair, glass or low contrast.
- Large images are slow on phones (a 24 MP result took about 17 s in a software-rendered desktop browser).

## WhatsApp
Defaults to `https://wa.me/96565068000` (+965 6506 8000). Override with the `prime_image_lab_whatsapp` filter;
return an empty string to hide the button.

## Deploy
Commit title containing `[deploy imagelab]` on `main-0t9dg8`, or Actions, Deploy to live site, target `imagelab`
(or `scripts/deploy-image-lab.sh`). Then WordPress, Plugins, activate **PRIME Image Lab**, and create a page with the
slug `image-lab` containing `[prime_image_lab]`.

## Rollback
Deactivate the plugin (the page then shows its other content and no tool), or delete
`wp-content/plugins/prime-image-lab` over SFTP. Nothing else is changed anywhere: the theme, the Prime Designer plugin,
WooCommerce, checkout and the database are untouched.
