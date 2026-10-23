# PRIME Image Lab

Free image tools for primekw.net that run **entirely in the visitor's browser**.
Shortcode: `[prime_image_lab]`, placed on the page `/image-lab/`.

## What it does
- **Improve:** brightness, contrast, sharpen, light noise reduction, resize by 1x to 4x or exact pixels. Browser Canvas resampling, not AI.
- **Background:** removes a flat, single-colour background that touches the photo's edges; exports a transparent PNG. Not AI.
- **Print check:** pixels, format, size, aspect ratio, estimated PPI at a print size in mm or cm, with plain warnings.
- **Export:** JPEG (quality slider) or PNG, generic filename. WhatsApp hand-off and a shop link.
- English and Arabic (RTL), switchable on the page.

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

No dependencies, no build step, no external libraries or models.

## Limits (shown to users where relevant)
- JPEG, PNG, WebP up to 30 MB. Max 40 megapixels on computers and 16 megapixels on phones and tablets
  (iOS Safari cannot create larger canvases).
- Resizing cannot recreate detail. The page never calls anything "AI".
- JPEG export drops colour profile and EXIF. No CMYK conversion, bleed, cutlines or PDF preflight.
- Plain-background removal fails on busy or similar-coloured backgrounds. A model-based mode is not included.
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
