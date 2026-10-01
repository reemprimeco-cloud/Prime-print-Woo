import { readFile } from 'node:fs/promises';
import sharp from 'sharp';
import { mmToPx, type DesignJSON, type Spec } from '@binder/shared';
import type { Config } from '../config.ts';
import type { PreparedAssets } from './assets.ts';
import { renderArtboardPng, type MeasuredText } from './browser.ts';
import { encodeSpotTiff } from './tiff-spot.ts';

export interface RenderedTransfer {
  /** RGBA PNG at the print resolution, transparent where nothing prints. The proof, and a print file in its own right. */
  png: Uint8Array;
  /** CMYK (FOGRA39) + White + Varnish spot channels. The RIP file. */
  tiff: Uint8Array;
  measured: MeasuredText[];
  timingsMs: { browser: number; colors: number; tiff: number };
}

/**
 * The spot channels of a UV DTF transfer, both derived from the artwork's
 * coverage: white ink is laid under everything that prints so colours sit on
 * an opaque base, and varnish (the adhesive layer) over the same area. A pixel
 * that is 40 % covered gets 40 % white and 40 % varnish, so soft edges stay
 * soft. Nothing where the artboard is transparent.
 */
export const UVDTF_SPOTS = {
  // How Photoshop shows the channels (display only; the ink data is the same
  // either way): black at 100 % solidity for both, which is what Reem's RIP
  // workflow expects (2026-10-01: "both spot channels 100%").
  white: { name: 'White', display: [0, 0, 0] as [number, number, number], solidity: 100 },
  varnish: { name: 'Varnish', display: [0, 0, 0] as [number, number, number], solidity: 100 },
} as const;

/**
 * The UV DTF pipeline: the print route captured as a transparent raster at
 * the template's dpi, then
 *   - PNG: the raster as is (sRGB + alpha, 300 dpi);
 *   - TIFF: colours converted to CMYK through the FOGRA39 profile, the alpha
 *     kept as Photoshop-style transparency (so the file does not open on a
 *     white background), plus the two spot channels built from the alpha.
 * There is no PDF, no page box and no cut line: the file is the artboard.
 */
export async function renderTransfer(
  cfg: Config,
  baseUrl: string,
  spec: Spec,
  design: DesignJSON,
  assets?: Pick<PreparedAssets, 'files'>,
): Promise<RenderedTransfer> {
  const t: RenderedTransfer['timingsMs'] = { browser: 0, colors: 0, tiff: 0 };
  let mark = Date.now();
  const lap = (k: keyof typeof t) => {
    t[k] += Date.now() - mark;
    mark = Date.now();
  };

  const width = Math.round(mmToPx(spec.canvas_with_bleed_mm.w, spec.dpi));
  const height = Math.round(mmToPx(spec.canvas_with_bleed_mm.h, spec.dpi));

  const { png: captured, measured } = await renderArtboardPng(cfg, baseUrl, spec, design, assets, { width, height });
  lap('browser');

  // Exact pixel size (the capture may be a pixel off from viewport rounding), 300 dpi in the file.
  const base = sharp(captured).resize(width, height, { fit: 'fill', kernel: 'lanczos3' });
  const png = await base.clone().ensureAlpha().png({ compressionLevel: 6 }).withMetadata({ density: spec.dpi }).toBuffer();

  // Colour: flatten onto white first so edge pixels convert as they will look
  // over the white underbase, then to CMYK through the shop's press profile.
  const cmyk = await base
    .clone()
    .flatten({ background: '#ffffff' })
    .removeAlpha()
    .withIccProfile(cfg.iccProfile)
    .toColourspace('cmyk')
    .raw()
    .toBuffer({ resolveWithObject: true });
  if (cmyk.info.channels !== 4 || cmyk.info.width !== width || cmyk.info.height !== height) {
    throw new Error(`CMYK conversion gave ${cmyk.info.width}x${cmyk.info.height}x${cmyk.info.channels}, expected ${width}x${height}x4`);
  }
  const alpha = await base.clone().ensureAlpha().extractChannel(3).raw().toBuffer();
  lap('colors');

  // No ink at all where nothing was placed, whatever the profile made of white.
  const px = width * height;
  const c = new Uint8Array(cmyk.data.buffer, cmyk.data.byteOffset, cmyk.data.length);
  for (let i = 0; i < px; i++) {
    if (alpha[i] === 0) c[i * 4] = c[i * 4 + 1] = c[i * 4 + 2] = c[i * 4 + 3] = 0;
  }
  // Spot channels: 0 = solid ink, 255 = none (Photoshop's convention), from the coverage.
  const ink = new Uint8Array(px);
  for (let i = 0; i < px; i++) ink[i] = 255 - alpha[i]!;

  const icc = await readFile(cfg.iccProfile).catch(() => undefined);
  const tiff = encodeSpotTiff({
    width,
    height,
    cmyk: c,
    alpha: new Uint8Array(alpha.buffer, alpha.byteOffset, alpha.length),
    spots: [
      { ...UVDTF_SPOTS.white, ink },
      { ...UVDTF_SPOTS.varnish, ink },
    ],
    dpi: spec.dpi,
    ...(icc ? { icc } : {}),
  });
  lap('tiff');

  return { png, tiff, measured, timingsMs: t };
}
